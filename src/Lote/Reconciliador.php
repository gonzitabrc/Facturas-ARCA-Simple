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
 * Resuelve las filas que quedaron en el aire: INCIERTO (falló el envío y no sabemos
 * si ARCA lo tomó) y ENVIANDO viejas (el proceso se cortó justo en el medio).
 *
 * La regla de oro: después de un error técnico NUNCA se reintenta a ciegas.
 * Se le pregunta a ARCA qué pasó con el número que íbamos a usar.
 */
final class Reconciliador
{
    /** Un ENVIANDO más viejo que esto quiere decir que el proceso se cortó. */
    private const SEGUNDOS_ENVIANDO = 120;

    public function __construct(
        private array $emisor,
        private array $lote,
        private Wsfe $wsfe,
    ) {
    }

    /** Filas del lote que hay que reconciliar antes de seguir emitiendo. */
    public function pendientes(): array
    {
        return Db::todas(
            "SELECT * FROM comprobantes
              WHERE lote_id = ?
                AND (estado = 'INCIERTO'
                     OR (estado = 'ENVIANDO' AND (strftime('%s','now') - strftime('%s', actualizado)) > ?))
              ORDER BY fila_excel",
            [(int) $this->lote['id'], self::SEGUNDOS_ENVIANDO]
        );
    }

    /**
     * Reconcilia una fila.
     *
     * @return array{estado:string, mensaje:string, pausar:bool}
     */
    public function reconciliar(array $comprobante): array
    {
        $nro = (int) ($comprobante['nro_esperado'] ?? 0);
        $tipo = (int) $comprobante['tipo_cbte'];

        if ($nro <= 0) {
            // Nunca llegó a pedir número: es seguro volver a la cola.
            Db::actualizar('comprobantes', (int) $comprobante['id'], ['estado' => 'VALIDADO', 'error_msg' => null]);
            return ['estado' => 'VALIDADO', 'mensaje' => 'Vuelve a la cola: no había número asignado.', 'pausar' => false];
        }

        try {
            $enArca = $this->wsfe->consultarComprobante($tipo, $nro, (int) $comprobante['punto_venta']);
        } catch (ArcaExcepcion $e) {
            // Si tampoco se puede consultar, se queda como estaba y el lote sigue pausado.
            Db::actualizar('comprobantes', (int) $comprobante['id'], [
                'estado'    => 'INCIERTO',
                'error_msg' => 'ARCA no responde, probá en unos minutos.',
            ]);
            App::log('Reconciliación sin respuesta de ARCA: ' . $e->paraLog());
            return [
                'estado'  => 'INCIERTO',
                'mensaje' => 'ARCA no responde, probá en unos minutos.',
                'pausar'  => true,
            ];
        }

        // No existe: el número no se usó, se puede reintentar sin riesgo de duplicar.
        if ($enArca === null) {
            Db::actualizar('comprobantes', (int) $comprobante['id'], [
                'estado'       => 'VALIDADO',
                'nro_esperado' => null,
                'error_msg'    => null,
            ]);
            App::log("Reconciliación: el comprobante {$tipo}-{$nro} no existe en ARCA, la fila {$comprobante['fila_excel']} vuelve a la cola");
            return [
                'estado'  => 'VALIDADO',
                'mensaje' => 'ARCA no tiene ese comprobante: se vuelve a intentar.',
                'pausar'  => false,
            ];
        }

        // Existe: ¿es el nuestro? Se comparan documento, importe y fecha.
        $coincide = $this->coincide($comprobante, $enArca);

        if (!$coincide) {
            $mensaje = 'En ARCA hay un comprobante ' . str_pad((string) $comprobante['punto_venta'], 4, '0', \STR_PAD_LEFT)
                . '-' . str_pad((string) $nro, 8, '0', \STR_PAD_LEFT) . ' que no coincide con esta fila'
                . ' (ARCA dice documento ' . ($enArca['doc_nro'] ?? '?') . ', importe ' . ($enArca['total'] ?? '?')
                . ', fecha ' . ($enArca['fecha'] ?? '?') . '). Revisalo en Mis Comprobantes de ARCA antes de seguir.';

            Db::actualizar('comprobantes', (int) $comprobante['id'], [
                'estado'    => 'REVISION_MANUAL',
                'error_msg' => $mensaje,
            ]);
            App::log("Reconciliación: {$tipo}-{$nro} no coincide con la fila {$comprobante['fila_excel']} → REVISION_MANUAL");
            return ['estado' => 'REVISION_MANUAL', 'mensaje' => $mensaje, 'pausar' => true];
        }

        // Es el nuestro: quedó autorizado y nosotros no nos enteramos.
        Db::actualizar('comprobantes', (int) $comprobante['id'], [
            'estado'    => 'AUTORIZADO',
            'nro'       => $enArca['nro'],
            'cae'       => $enArca['cae'],
            'vto_cae'   => $enArca['vto_cae'],
            'error_cod' => null,
            'error_msg' => null,
        ]);
        App::log("Reconciliación: {$tipo}-{$nro} estaba autorizado en ARCA, se recuperó el CAE para la fila {$comprobante['fila_excel']}");

        $comprobante = Db::una('SELECT * FROM comprobantes WHERE id = ?', [(int) $comprobante['id']]) ?? $comprobante;
        GeneradorPdf::generarSiFalta($this->emisor, $comprobante);

        return [
            'estado'  => 'AUTORIZADO',
            'mensaje' => 'Estaba emitida en ARCA: se recuperó el CAE ' . $enArca['cae'] . '.',
            'pausar'  => false,
        ];
    }

    /** Documento, importe y fecha tienen que ser los de la fila. */
    private function coincide(array $comprobante, array $enArca): bool
    {
        $docNuestro = preg_replace('/\D/', '', (string) $comprobante['doc_nro']) ?? '';
        $docArca = preg_replace('/\D/', '', (string) ($enArca['doc_nro'] ?? '')) ?? '';

        $totalNuestro = number_format((float) $comprobante['total'], 2, '.', '');
        $totalArca = number_format((float) ($enArca['total'] ?? 0), 2, '.', '');

        $fechaNuestra = (string) $comprobante['fecha'];
        $fechaArca = (string) ($enArca['fecha'] ?? '');

        return (int) $docNuestro === (int) $docArca
            && $totalNuestro === $totalArca
            && ($fechaArca === '' || $fechaArca === $fechaNuestra);
    }

    public static function categoriaTecnica(): string
    {
        return Errores::TECNICO;
    }
}
