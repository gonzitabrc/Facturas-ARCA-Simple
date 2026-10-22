<?php
declare(strict_types=1);

namespace Facturador\Lote;

use Facturador\App;
use Facturador\Arca\ArcaExcepcion;
use Facturador\Arca\Errores;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Pdf\GeneradorPdf;

/**
 * Máquina de estados: procesa UNA factura por paso.
 *
 *   CARGADO → VALIDADO → ENVIANDO → AUTORIZADO → PDF_OK
 *       ↓                    ↓   ↘
 *   ERROR_DATOS          RECHAZADO  INCIERTO → (reconciliación) → AUTORIZADO | VALIDADO | REVISION_MANUAL
 *
 * Reglas que no se negocian:
 *  - Se graba ENVIANDO + nro_esperado y se commitea ANTES de llamar a ARCA.
 *  - Nunca se reintenta FECAESolicitar a ciegas después de un error técnico.
 *  - Nunca se asigna un número sin consultar FECompUltimoAutorizado en el mismo paso.
 *  - Un CAE no se revierte nunca.
 */
final class Procesador
{
    private Wsfe $wsfe;

    public function __construct(
        private array $emisor,
        private array $lote,
        ?Wsfe $wsfe = null,
    ) {
        $this->wsfe = $wsfe ?? new Wsfe($emisor);
    }

    /**
     * Un paso del lote. Devuelve el progreso para el loop del navegador.
     *
     * @return array{ocupado:bool, termino:bool, pausado:bool, motivo:?string,
     *               total:int, autorizadas:int, rechazadas:int, pendientes:int,
     *               procesadas:int, ultima:?array}
     */
    public function paso(): array
    {
        $archivoLock = App::storage('locks/' . $this->emisor['cuit'] . '_' . $this->emisor['punto_venta'] . '.lock');
        $lock = fopen($archivoLock, 'c+');
        if ($lock === false) {
            return $this->progreso(['motivo' => 'No se pudo tomar el lock del punto de venta.', 'pausado' => true]);
        }

        // No bloqueante: si otra pestaña está emitiendo, este paso no hace nada.
        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            fclose($lock);
            return $this->progreso(['ocupado' => true]);
        }

        try {
            return $this->pasoConLock();
        } catch (ArcaExcepcion $e) {
            // Falla al obtener el TA o al consultar: se pausa con el mensaje traducido.
            $this->pausar($e->getMessage());
            App::log('Paso del lote ' . $this->lote['id'] . ' interrumpido: ' . $e->paraLog());
            return $this->progreso(['pausado' => true, 'motivo' => $e->getMessage()]);
        } catch (\Throwable $e) {
            App::log('Error inesperado procesando el lote ' . $this->lote['id'] . ': ' . $e->getMessage());
            $this->pausar('Hubo un problema inesperado al emitir. El detalle quedó registrado.');
            return $this->progreso(['pausado' => true, 'motivo' => 'Hubo un problema inesperado al emitir. El detalle quedó registrado.']);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------------ el paso

    private function pasoConLock(): array
    {
        // 1. Primero lo que quedó en el aire: no se emite nada nuevo hasta resolverlo.
        $reconciliador = new Reconciliador($this->emisor, $this->lote, $this->wsfe);
        foreach ($reconciliador->pendientes() as $pendiente) {
            $resultado = $reconciliador->reconciliar($pendiente);

            if ($resultado['pausar']) {
                $this->pausar($resultado['mensaje']);
                return $this->progreso([
                    'pausado' => true,
                    'motivo'  => $resultado['mensaje'],
                    'ultima'  => [
                        'fila'    => (int) $pendiente['fila_excel'],
                        'estado'  => $resultado['estado'],
                        'mensaje' => $resultado['mensaje'],
                    ],
                ]);
            }

            // Se resolvió: si el lote estaba pausado por esto, deja de estarlo.
            if ((string) ($this->lote['estado'] ?? '') === 'PAUSADO') {
                Db::actualizar('lotes', (int) $this->lote['id'], ['estado' => 'PROCESANDO', 'motivo_pausa' => null]);
                $this->lote['estado'] = 'PROCESANDO';
                $this->lote['motivo_pausa'] = null;
            }

            // Reconcilió bien: se devuelve el progreso y el loop vuelve a llamar.
            return $this->progreso([
                'ultima' => [
                    'fila'    => (int) $pendiente['fila_excel'],
                    'estado'  => $resultado['estado'],
                    'mensaje' => $resultado['mensaje'],
                ],
            ]);
        }

        // 2. ¿Está en pie ARCA? Solo en el primer paso del lote.
        if ($this->esPrimerPaso()) {
            $estado = $this->wsfe->dummy();
            if (!$estado['ok']) {
                $motivo = 'ARCA tiene servicios caídos (aplicación: ' . $estado['AppServer']
                    . ', base: ' . $estado['DbServer'] . ', autenticación: ' . $estado['AuthServer']
                    . '). No es problema de la app: probá en unos minutos.';
                $this->pausar($motivo);
                return $this->progreso(['pausado' => true, 'motivo' => $motivo]);
            }
        }

        // 3. La próxima fila lista, en el orden del Excel.
        $fila = Db::una(
            "SELECT * FROM comprobantes WHERE lote_id = ? AND estado = 'VALIDADO' ORDER BY fila_excel LIMIT 1",
            [(int) $this->lote['id']]
        );

        if ($fila === null) {
            return $this->terminar();
        }

        return $this->emitir($fila);
    }

    /** Pasos 4 a 7: número, grabar ENVIANDO, pedir CAE y resolver según la respuesta. */
    private function emitir(array $fila, bool $reintento = false): array
    {
        $tipo = (int) $fila['tipo_cbte'];
        $pv = (int) $fila['punto_venta'];

        // 4 y 5. El número lo dice ARCA, nunca la base.
        $ultimo = $this->wsfe->ultimoAutorizado($tipo, $pv);
        $nro = $ultimo + 1;

        // 6. ENVIANDO + nro_esperado, commiteado ANTES de llamar a ARCA.
        //    Si el proceso se corta justo acá, la reconciliación sabe qué preguntar.
        Db::enTransaccion(static function () use ($fila, $nro): void {
            Db::actualizar('comprobantes', (int) $fila['id'], [
                'estado'       => 'ENVIANDO',
                'nro_esperado' => $nro,
                'intentos'     => (int) $fila['intentos'] + 1,
                'error_cod'    => null,
                'error_msg'    => null,
            ]);
        });

        // 7. Pedir el CAE.
        try {
            $respuesta = $this->wsfe->solicitarCae($fila, $nro, (int) $fila['id'], (int) $this->lote['id']);
        } catch (ArcaExcepcion $e) {
            if ($e->categoria === Errores::AUTH) {
                // El certificado o los permisos: la fila vuelve a la cola y el lote se pausa.
                Db::actualizar('comprobantes', (int) $fila['id'], [
                    'estado'       => 'VALIDADO',
                    'nro_esperado' => null,
                    'error_msg'    => $e->getMessage(),
                ]);
                $this->pausar($e->getMessage());
                return $this->progreso([
                    'pausado' => true,
                    'motivo'  => $e->getMessage(),
                    'ultima'  => ['fila' => (int) $fila['fila_excel'], 'estado' => 'VALIDADO', 'mensaje' => $e->getMessage()],
                ]);
            }

            if ($e->categoria === Errores::DATOS) {
                // Algo de la fila no se pudo ni armar (por ejemplo una alícuota inexistente).
                Db::actualizar('comprobantes', (int) $fila['id'], [
                    'estado'    => 'RECHAZADO',
                    'error_msg' => Validador::mensaje((int) $fila['fila_excel'], [$e->getMessage()]),
                ]);
                return $this->progreso([
                    'ultima' => ['fila' => (int) $fila['fila_excel'], 'estado' => 'RECHAZADO', 'mensaje' => $e->getMessage()],
                ]);
            }

            // TECNICO: no sabemos si ARCA lo tomó. INCIERTO y lote pausado.
            $motivo = 'ARCA no respondió al emitir la fila ' . $fila['fila_excel']
                . '. El lote quedó pausado: cuando apretés Continuar, la app le pregunta a ARCA qué pasó antes de seguir.';
            Db::actualizar('comprobantes', (int) $fila['id'], [
                'estado'    => 'INCIERTO',
                'error_cod' => $e->codigo,
                'error_msg' => 'No sabemos si ARCA la tomó. Se verifica antes de reintentar.',
            ]);
            $this->pausar($motivo);
            App::log('Fila ' . $fila['fila_excel'] . ' del lote ' . $this->lote['id'] . ' quedó INCIERTO: ' . $e->paraLog());

            return $this->progreso([
                'pausado' => true,
                'motivo'  => $motivo,
                'ultima'  => ['fila' => (int) $fila['fila_excel'], 'estado' => 'INCIERTO', 'mensaje' => $motivo],
            ]);
        }

        // Autorizada.
        if ($respuesta['resultado'] === 'A') {
            Db::actualizar('comprobantes', (int) $fila['id'], [
                'estado'             => 'AUTORIZADO',
                'nro'                => $respuesta['nro'],
                'cae'                => $respuesta['cae'],
                'vto_cae'            => $respuesta['vto_cae'],
                'observaciones_arca' => $respuesta['observaciones'] === []
                    ? null
                    : json_encode($respuesta['observaciones'], \JSON_UNESCAPED_UNICODE),
                'error_cod'          => null,
                'error_msg'          => null,
            ]);

            $actualizada = Db::una('SELECT * FROM comprobantes WHERE id = ?', [(int) $fila['id']]);
            // Si el PDF falla, la fila queda AUTORIZADA: el CAE no se toca y el PDF se regenera después.
            GeneradorPdf::generarSiFalta($this->emisor, $actualizada ?? $fila);

            return $this->progreso([
                'ultima' => [
                    'fila'        => (int) $fila['fila_excel'],
                    'estado'      => 'AUTORIZADO',
                    'comprobante' => $this->formatearNumero($pv, (int) $respuesta['nro']),
                    'cae'         => $respuesta['cae'],
                    'mensaje'     => $respuesta['observaciones'] === [] ? null : Errores::resumir($respuesta['observaciones']),
                ],
            ]);
        }

        // Rechazada: la categoría decide qué hacer.
        $categoria = $respuesta['categoria'] ?? Errores::DATOS;
        $mensaje = (string) ($respuesta['mensaje'] ?? 'ARCA rechazó el comprobante.');

        if ($categoria === Errores::NUMERACION && !$reintento) {
            // El número no era el que seguía: se reconsulta y se reintenta UNA vez.
            App::log('Numeración desfasada en la fila ' . $fila['fila_excel'] . ', se reintenta una vez');
            $fila = Db::una('SELECT * FROM comprobantes WHERE id = ?', [(int) $fila['id']]) ?? $fila;
            return $this->emitir($fila, true);
        }

        if ($categoria === Errores::NUMERACION) {
            $motivo = 'La numeración del punto de venta ' . $pv . ' está desfasada: alguien emitió comprobantes '
                . 'en este punto de venta por fuera de la app. Revisalo en ARCA antes de seguir.';
            Db::actualizar('comprobantes', (int) $fila['id'], [
                'estado'    => 'VALIDADO',
                'error_msg' => $motivo,
            ]);
            $this->pausar($motivo);
            return $this->progreso([
                'pausado' => true,
                'motivo'  => $motivo,
                'ultima'  => ['fila' => (int) $fila['fila_excel'], 'estado' => 'VALIDADO', 'mensaje' => $motivo],
            ]);
        }

        if ($categoria === Errores::AUTH) {
            Db::actualizar('comprobantes', (int) $fila['id'], [
                'estado'       => 'VALIDADO',
                'nro_esperado' => null,
                'error_msg'    => $mensaje,
            ]);
            $this->pausar($mensaje);
            return $this->progreso([
                'pausado' => true,
                'motivo'  => $mensaje,
                'ultima'  => ['fila' => (int) $fila['fila_excel'], 'estado' => 'VALIDADO', 'mensaje' => $mensaje],
            ]);
        }

        // DATOS: el número no se consumió. Se rechaza la fila y el lote sigue.
        Db::actualizar('comprobantes', (int) $fila['id'], [
            'estado'    => 'RECHAZADO',
            'error_cod' => $respuesta['errores'][0]['codigo'] ?? ($respuesta['observaciones'][0]['codigo'] ?? null),
            'error_msg' => Validador::mensaje((int) $fila['fila_excel'], [$mensaje]),
        ]);

        return $this->progreso([
            'ultima' => ['fila' => (int) $fila['fila_excel'], 'estado' => 'RECHAZADO', 'mensaje' => $mensaje],
        ]);
    }

    // ------------------------------------------------------------------ estado

    private function esPrimerPaso(): bool
    {
        return (int) Db::valor(
            "SELECT COUNT(*) FROM comprobantes WHERE lote_id = ? AND estado IN ('AUTORIZADO','PDF_OK','RECHAZADO')",
            [(int) $this->lote['id']]
        ) === 0;
    }

    private function pausar(string $motivo): void
    {
        Db::actualizar('lotes', (int) $this->lote['id'], ['estado' => 'PAUSADO', 'motivo_pausa' => $motivo]);
        $this->lote['estado'] = 'PAUSADO';
        $this->lote['motivo_pausa'] = $motivo;
    }

    private function terminar(): array
    {
        $conError = (int) Db::valor(
            "SELECT COUNT(*) FROM comprobantes WHERE lote_id = ? AND estado IN ('RECHAZADO','ERROR_DATOS','REVISION_MANUAL')",
            [(int) $this->lote['id']]
        );
        Db::actualizar('lotes', (int) $this->lote['id'], [
            'estado'       => $conError > 0 ? 'FINALIZADO_CON_ERRORES' : 'FINALIZADO',
            'motivo_pausa' => null,
        ]);
        $this->lote['estado'] = $conError > 0 ? 'FINALIZADO_CON_ERRORES' : 'FINALIZADO';

        return $this->progreso(['termino' => true]);
    }

    /** Conteos para la barra de progreso. */
    private function progreso(array $extra = []): array
    {
        $cuentas = Db::una(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado IN ('AUTORIZADO','PDF_OK') THEN 1 ELSE 0 END) AS autorizadas,
                SUM(CASE WHEN estado IN ('RECHAZADO','ERROR_DATOS') THEN 1 ELSE 0 END) AS rechazadas,
                SUM(CASE WHEN estado = 'VALIDADO' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN estado IN ('INCIERTO','ENVIANDO','REVISION_MANUAL') THEN 1 ELSE 0 END) AS en_duda
             FROM comprobantes WHERE lote_id = ?",
            [(int) $this->lote['id']]
        ) ?? [];

        $lote = Db::una('SELECT estado, motivo_pausa FROM lotes WHERE id = ?', [(int) $this->lote['id']]) ?? [];

        return array_merge([
            'ocupado'     => false,
            'termino'     => in_array((string) ($lote['estado'] ?? ''), ['FINALIZADO', 'FINALIZADO_CON_ERRORES'], true),
            'pausado'     => (string) ($lote['estado'] ?? '') === 'PAUSADO',
            'motivo'      => $lote['motivo_pausa'] ?? null,
            'estado_lote' => (string) ($lote['estado'] ?? ''),
            'total'       => (int) ($cuentas['total'] ?? 0),
            'autorizadas' => (int) ($cuentas['autorizadas'] ?? 0),
            'rechazadas'  => (int) ($cuentas['rechazadas'] ?? 0),
            'pendientes'  => (int) ($cuentas['pendientes'] ?? 0),
            'en_duda'     => (int) ($cuentas['en_duda'] ?? 0),
            'procesadas'  => (int) ($cuentas['total'] ?? 0) - (int) ($cuentas['pendientes'] ?? 0),
            'ultima'      => null,
        ], $extra);
    }

    public function formatearNumero(int $pv, int $nro): string
    {
        return str_pad((string) $pv, 4, '0', \STR_PAD_LEFT) . '-' . str_pad((string) $nro, 8, '0', \STR_PAD_LEFT);
    }
}
