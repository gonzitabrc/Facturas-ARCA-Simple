<?php
declare(strict_types=1);

namespace Facturador\Lote;

use Facturador\App;
use Facturador\Db;

/**
 * Reglas de integridad antes de emitir. Un lote puede tener filas buenas y malas:
 * se emiten solo las buenas. Cada problema se guarda como una frase que diga qué corregir.
 *
 * Los errores frenan la fila; las advertencias solo avisan (hay casos especiales legítimos).
 */
final class Validador
{
    public const CLASE_A = [1, 2, 3];
    public const CLASE_B = [6, 7, 8];
    public const CLASE_C = [11, 12, 13];
    public const NOTAS = [2, 3, 7, 8, 12, 13];          // notas de débito y de crédito
    public const ALICUOTAS = ['0', '2.5', '5', '10.5', '21', '27'];

    /** Condiciones de IVA del receptor que implican factura A cuando el emisor es RI. */
    private const RECEPTOR_TIPO_A = [1, 6, 13, 16];

    /**
     * @param array      $emisor         fila de `emisores`
     * @param list<int>  $condicionesIva ids válidos de FEParamGetCondicionIvaReceptor (vacío = no se valida)
     * @param array<int,int> $ultimos    último número emitido por tipo (para avisar fechas hacia atrás)
     */
    public function __construct(
        private array $emisor,
        private array $condicionesIva = [],
        private array $ultimasFechas = [],
    ) {
    }

    /**
     * @param list<array{fila_excel:int, datos:array}> $filas
     * @return list<array{fila_excel:int, datos:array, errores:list<string>, advertencias:list<string>}>
     */
    public function validar(array $filas): array
    {
        $vistos = [];
        $resultado = [];

        foreach ($filas as $fila) {
            $datos = $fila['datos'];
            $errores = [];
            $advertencias = [];

            $this->validarReferencia($datos, $vistos, $fila['fila_excel'], $errores, $advertencias);
            $this->validarPuntoVenta($datos, $errores);
            $this->validarTipo($datos, $errores, $advertencias);
            $this->validarReceptor($datos, $errores, $advertencias);
            $this->validarFechas($datos, $errores, $advertencias);
            $this->validarImportes($datos, $errores);
            $this->validarMoneda($datos, $errores);
            $this->validarAsociado($datos, $errores);

            if (trim((string) ($datos['descripcion'] ?? '')) === '') {
                $errores[] = 'falta la descripción, que es lo que sale impreso en el comprobante';
            }

            $resultado[] = [
                'fila_excel'   => $fila['fila_excel'],
                'datos'        => $datos,
                'errores'      => $errores,
                'advertencias' => $advertencias,
            ];
        }

        return $resultado;
    }

    /** Arma el texto que ve el cliente: "Fila 14: el CUIT ... (y otra cosa)". */
    public static function mensaje(int $fila, array $problemas): string
    {
        if ($problemas === []) {
            return '';
        }
        return 'Fila ' . $fila . ': ' . implode('; ', $problemas) . '.';
    }

    // ------------------------------------------------------------------ reglas

    private function validarReferencia(array &$datos, array &$vistos, int $fila, array &$errores, array &$advertencias): void
    {
        $id = trim((string) ($datos['id_externo'] ?? ''));
        if ($id === '') {
            $errores[] = 'falta la referencia (id_externo), que es lo que evita facturar dos veces la misma operación';
            return;
        }
        if (isset($vistos[$id])) {
            $errores[] = 'la referencia "' . $id . '" está repetida en la fila ' . $vistos[$id] . ' de este mismo archivo';
            return;
        }
        $vistos[$id] = $fila;

        // ¿Ya se emitió (o está en vuelo) con esa referencia? El índice ux_cbte_idext_vivo lo impide.
        $previo = Db::una(
            "SELECT estado, nro, tipo_cbte FROM comprobantes
              WHERE emisor_id = ? AND id_externo = ?
                AND estado IN ('ENVIANDO','AUTORIZADO','INCIERTO','REVISION_MANUAL','PDF_OK')
              LIMIT 1",
            [(int) $this->emisor['id'], $id]
        );
        if ($previo !== null) {
            $errores[] = $previo['nro'] !== null
                ? 'la referencia "' . $id . '" ya se facturó (comprobante ' . str_pad((string) $previo['nro'], 8, '0', \STR_PAD_LEFT) . '); si hay que corregirla va una nota de crédito'
                : 'la referencia "' . $id . '" está en proceso en otro lote (' . strtolower((string) $previo['estado']) . '): revisá ese lote antes de volver a subirla';
        }
    }

    private function validarPuntoVenta(array &$datos, array &$errores): void
    {
        $pvEmisor = (int) $this->emisor['punto_venta'];
        if ($datos['punto_venta'] === null) {
            $datos['punto_venta'] = $pvEmisor;    // si no viene, se usa el configurado
            return;
        }
        if ((int) $datos['punto_venta'] !== $pvEmisor) {
            $errores[] = 'el punto de venta ' . $datos['punto_venta'] . ' no es el de esta app (' . $pvEmisor . ')';
        }
    }

    private function validarTipo(array &$datos, array &$errores, array &$advertencias): void
    {
        $tipo = (int) ($datos['tipo_cbte'] ?? 0);
        $permitidos = in_array((string) $this->emisor['condicion_iva'], ['MONO', 'EXENTO'], true)
            ? self::CLASE_C
            : array_merge(self::CLASE_A, self::CLASE_B);

        if ($tipo === 0) {
            $errores[] = 'falta el tipo de comprobante';
            return;
        }
        if (!in_array($tipo, $permitidos, true)) {
            $errores[] = in_array((string) $this->emisor['condicion_iva'], ['MONO', 'EXENTO'], true)
                ? 'el tipo ' . $tipo . ' no corresponde: siendo monotributo o exento se emiten comprobantes C (11 factura, 12 nota de débito, 13 nota de crédito)'
                : 'el tipo ' . $tipo . ' no corresponde: siendo responsable inscripto se emiten A (1, 2, 3) o B (6, 7, 8)';
            return;
        }

        // Clase A o B según la condición de IVA del receptor (advertencia: hay casos especiales).
        if (in_array($tipo, array_merge(self::CLASE_A, self::CLASE_B), true)) {
            $cond = (int) ($datos['condicion_iva_receptor'] ?? 0);
            $esA = in_array($tipo, self::CLASE_A, true);
            $deberiaSerA = in_array($cond, self::RECEPTOR_TIPO_A, true);
            if ($cond > 0 && $esA !== $deberiaSerA) {
                $advertencias[] = $deberiaSerA
                    ? 'por la condición de IVA del receptor normalmente correspondería comprobante A'
                    : 'por la condición de IVA del receptor normalmente correspondería comprobante B';
            }
        }

        $concepto = (int) ($datos['concepto'] ?? 0);
        if (!in_array($concepto, [1, 2, 3], true)) {
            $errores[] = 'el concepto tiene que ser 1 (productos), 2 (servicios) o 3 (productos y servicios)';
        }
    }

    private function validarReceptor(array &$datos, array &$errores, array &$advertencias): void
    {
        $tipoDoc = (int) ($datos['doc_tipo'] ?? 0);
        $nro = (string) ($datos['doc_nro'] ?? '');

        if ($tipoDoc === 80) {
            if (!App::cuitValido($nro)) {
                $errores[] = 'el CUIT ' . self::formatearCuit($nro) . ' no es válido (dígito verificador)';
            }
        } elseif ($tipoDoc === 96) {
            if (!preg_match('/^\d{7,8}$/', $nro)) {
                $errores[] = 'el DNI "' . $nro . '" tiene que tener 7 u 8 dígitos';
            }
        } elseif ($tipoDoc === 99) {
            if ($nro !== '' && (int) $nro !== 0) {
                $errores[] = 'con documento tipo 99 (consumidor final sin identificar) el número tiene que ser 0';
            }
            $datos['doc_nro'] = '0';

            $tope = (string) App::config('reglas.monto_identificacion_cf', '0');
            if ((float) $tope > 0 && (float) ($datos['total'] ?? 0) >= (float) $tope) {
                $errores[] = 'por el importe (' . $datos['total'] . ') ARCA exige identificar al cliente: poné CUIT (80) o DNI (96)';
            }
        } else {
            $errores[] = 'el tipo de documento tiene que ser 80 (CUIT), 96 (DNI) o 99 (consumidor final sin identificar)';
        }

        $cond = (int) ($datos['condicion_iva_receptor'] ?? 0);
        if ($cond <= 0) {
            $errores[] = 'falta la condición de IVA del receptor (ARCA la rechaza si no viene)';
        } elseif ($this->condicionesIva !== [] && !in_array($cond, $this->condicionesIva, true)) {
            $errores[] = 'la condición de IVA del receptor "' . $cond . '" no está en la lista de ARCA';
        }

        if (trim((string) ($datos['razon_social'] ?? '')) === '' && $tipoDoc !== 99) {
            $advertencias[] = 'falta el nombre del cliente: el PDF va a salir sin ese dato';
        }
    }

    private function validarFechas(array &$datos, array &$errores, array &$advertencias): void
    {
        $fecha = (string) ($datos['fecha'] ?? '');
        if ($fecha === '' || strtotime($fecha) === false) {
            $errores[] = 'la fecha no se entiende: escribila como 31/12/2026 o 2026-12-31';
            return;
        }

        $concepto = (int) ($datos['concepto'] ?? 1);
        $tolerancia = $concepto === 1
            ? (int) App::config('reglas.dias_tolerancia_productos', 5)
            : (int) App::config('reglas.dias_tolerancia_servicios', 10);

        $dias = (int) floor((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
        if (abs($dias) > $tolerancia) {
            $errores[] = 'la fecha ' . date('d/m/Y', (int) strtotime($fecha)) . ' está a ' . abs($dias)
                . ' días de hoy y ARCA acepta hasta ' . $tolerancia . ' para este concepto';
        }

        $tipo = (int) ($datos['tipo_cbte'] ?? 0);
        $ultima = $this->ultimasFechas[$tipo] ?? null;
        if ($ultima !== null && strtotime($fecha) < strtotime((string) $ultima)) {
            $advertencias[] = 'la fecha es anterior a la del último comprobante emitido de este tipo (' . date('d/m/Y', (int) strtotime((string) $ultima)) . ')';
        }

        if ($concepto === 2 || $concepto === 3) {
            foreach (['serv_desde' => 'desde', 'serv_hasta' => 'hasta', 'vto_pago' => 'vencimiento de pago'] as $campo => $nombre) {
                if (($datos[$campo] ?? null) === null) {
                    $errores[] = 'con concepto ' . $concepto . ' (servicios) falta la fecha ' . $nombre;
                }
            }
            if (($datos['serv_desde'] ?? null) !== null && ($datos['serv_hasta'] ?? null) !== null
                && strtotime((string) $datos['serv_desde']) > strtotime((string) $datos['serv_hasta'])) {
                $errores[] = 'el período de servicio está al revés: "desde" es posterior a "hasta"';
            }
        }
    }

    private function validarImportes(array &$datos, array &$errores): void
    {
        $tipo = (int) ($datos['tipo_cbte'] ?? 0);
        $esC = in_array($tipo, self::CLASE_C, true);

        foreach (['neto_gravado' => 'el importe neto', 'no_gravado' => 'el importe no gravado', 'exento' => 'el importe exento', 'total' => 'el total'] as $campo => $nombre) {
            $valor = $datos[$campo];
            if ($valor === null) {
                $datos[$campo] = $campo === 'total' || $campo === 'neto_gravado' ? null : '0';
                continue;
            }
            if (!is_numeric($valor)) {
                $errores[] = $nombre . ' "' . $valor . '" no es un número';
                $datos[$campo] = null;
                continue;
            }
            if ((float) $valor < 0) {
                $errores[] = $nombre . ' no puede ser negativo (una devolución se hace con nota de crédito)';
            }
        }

        if ($datos['total'] === null) {
            $errores[] = 'falta el total';
        }
        if ($datos['neto_gravado'] === null) {
            // En C el neto es el total; en A/B hay que informarlo.
            if ($esC && $datos['total'] !== null) {
                $datos['neto_gravado'] = $datos['total'];
            } else {
                $errores[] = 'falta el importe neto gravado';
            }
        }

        $alicuota = (string) ($datos['alicuota_iva'] ?? '');

        if ($esC) {
            if ($alicuota !== '' && (float) $alicuota !== 0.0) {
                $errores[] = 'en comprobante C no se informa alícuota de IVA: dejá la columna vacía y poné el total en el neto';
            }
            $datos['alicuota_iva'] = '';
            $datos['iva'] = '0';
            if ($datos['total'] !== null && $datos['neto_gravado'] !== null
                && abs((float) $datos['total'] - (float) $datos['neto_gravado'] - (float) ($datos['no_gravado'] ?? 0) - (float) ($datos['exento'] ?? 0)) > (float) App::config('reglas.tolerancia_total', '0.01')) {
                $errores[] = 'en comprobante C el total (' . $datos['total'] . ') tiene que ser igual al neto (' . $datos['neto_gravado'] . ')';
            }
            return;
        }

        // A y B: alícuota obligatoria y el total tiene que cerrar.
        if ($alicuota === '') {
            $errores[] = 'falta la alícuota de IVA (21, 10.5, 27, 5, 2.5 o 0)';
            $datos['iva'] = '0';
            return;
        }
        if (!in_array($alicuota, self::ALICUOTAS, true)) {
            $errores[] = 'la alícuota "' . $alicuota . '" no existe en ARCA: usá 21, 10.5, 27, 5, 2.5 o 0';
            $datos['iva'] = '0';
            return;
        }

        if ($datos['neto_gravado'] === null || $datos['total'] === null) {
            return;
        }

        $iva = number_format(round((float) $datos['neto_gravado'] * (float) $alicuota / 100, 2), 2, '.', '');
        $datos['iva'] = $iva;

        $calculado = round(
            (float) $datos['neto_gravado'] + (float) $iva + (float) ($datos['no_gravado'] ?? 0) + (float) ($datos['exento'] ?? 0),
            2
        );
        $tolerancia = (float) App::config('reglas.tolerancia_total', '0.01');
        if (abs($calculado - (float) $datos['total']) > $tolerancia) {
            $errores[] = 'el total informado (' . $datos['total'] . ') no coincide con el calculado ('
                . number_format($calculado, 2, '.', '') . ' = neto ' . $datos['neto_gravado'] . ' + IVA ' . $iva . ')';
        }
    }

    private function validarMoneda(array &$datos, array &$errores): void
    {
        $moneda = strtoupper((string) ($datos['moneda'] ?? 'PES'));
        $datos['moneda'] = $moneda === '' ? 'PES' : $moneda;

        if ($datos['moneda'] === 'PES') {
            $datos['cotizacion'] = '1';
            return;
        }
        if (($datos['cotizacion'] ?? null) === null || (float) $datos['cotizacion'] <= 0) {
            $errores[] = 'con moneda ' . $datos['moneda'] . ' hay que informar la cotización';
        }
    }

    private function validarAsociado(array &$datos, array &$errores): void
    {
        $tipo = (int) ($datos['tipo_cbte'] ?? 0);
        $esNota = in_array($tipo, self::NOTAS, true);

        if (!$esNota) {
            return;
        }
        if (($datos['asoc_tipo'] ?? null) === null || ($datos['asoc_nro'] ?? null) === null) {
            $errores[] = 'una nota de crédito o débito necesita el comprobante asociado (tipo, punto de venta y número)';
            return;
        }
        if (($datos['asoc_pto_vta'] ?? null) === null) {
            $datos['asoc_pto_vta'] = (int) $this->emisor['punto_venta'];
        }

        // Si el asociado lo emitió esta app, tiene que estar autorizado.
        $asociado = Db::una(
            'SELECT estado FROM comprobantes
              WHERE emisor_id = ? AND tipo_cbte = ? AND punto_venta = ? AND nro = ?
              ORDER BY id DESC LIMIT 1',
            [(int) $this->emisor['id'], (int) $datos['asoc_tipo'], (int) $datos['asoc_pto_vta'], (int) $datos['asoc_nro']]
        );
        if ($asociado !== null && !in_array((string) $asociado['estado'], ['AUTORIZADO', 'PDF_OK'], true)) {
            $errores[] = 'el comprobante asociado está en estado ' . strtolower((string) $asociado['estado']) . ': no se puede asociar una nota a un comprobante que no quedó autorizado';
        }
    }

    private static function formatearCuit(string $cuit): string
    {
        return strlen($cuit) === 11
            ? substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10, 1)
            : $cuit;
    }
}
