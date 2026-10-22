<?php
declare(strict_types=1);

namespace Facturador\Lote;

use Facturador\App;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;

/**
 * Convierte el XLSX o CSV que sube el cliente en filas normalizadas.
 * No valida reglas de negocio (eso es Validador): acá solo se lee y se ordena.
 */
final class Importador
{
    /** Columnas de la plantilla, en orden. */
    public const COLUMNAS = [
        'id_externo', 'punto_venta', 'tipo_cbte', 'concepto', 'fecha', 'doc_tipo', 'doc_nro',
        'condicion_iva_receptor', 'razon_social', 'domicilio', 'email', 'descripcion',
        'neto_gravado', 'alicuota_iva', 'no_gravado', 'exento', 'total', 'moneda', 'cotizacion',
        'serv_desde', 'serv_hasta', 'vto_pago', 'asoc_tipo', 'asoc_pto_vta', 'asoc_nro',
    ];

    /** Nombres alternativos que se aceptan para cada columna. */
    private const ALIAS = [
        'referencia'           => 'id_externo',
        'id'                   => 'id_externo',
        'id_factura'           => 'id_externo',
        'pv'                   => 'punto_venta',
        'punto_de_venta'       => 'punto_venta',
        'tipo'                 => 'tipo_cbte',
        'tipo_comprobante'     => 'tipo_cbte',
        'fecha_comprobante'    => 'fecha',
        'tipo_doc'             => 'doc_tipo',
        'tipo_documento'       => 'doc_tipo',
        'documento'            => 'doc_nro',
        'nro_doc'              => 'doc_nro',
        'numero_documento'     => 'doc_nro',
        'cuit'                 => 'doc_nro',
        'condicion_iva'        => 'condicion_iva_receptor',
        'cond_iva'             => 'condicion_iva_receptor',
        'cliente'              => 'razon_social',
        'nombre'               => 'razon_social',
        'detalle'              => 'descripcion',
        'concepto_detalle'     => 'descripcion',
        'neto'                 => 'neto_gravado',
        'alicuota'             => 'alicuota_iva',
        'iva'                  => 'alicuota_iva',
        'importe_total'        => 'total',
        'cotiz'                => 'cotizacion',
        'servicio_desde'       => 'serv_desde',
        'servicio_hasta'       => 'serv_hasta',
        'vencimiento_pago'     => 'vto_pago',
        'asociado_tipo'        => 'asoc_tipo',
        'asociado_pto_vta'     => 'asoc_pto_vta',
        'asociado_nro'         => 'asoc_nro',
    ];

    /** Columnas numéricas que se guardan como texto decimal. */
    private const IMPORTES = ['neto_gravado', 'no_gravado', 'exento', 'total', 'cotizacion'];

    /** Columnas de fecha. */
    private const FECHAS = ['fecha', 'serv_desde', 'serv_hasta', 'vto_pago'];

    /**
     * @return array{filas: list<array{fila_excel:int, datos:array}>, encabezados: list<string>, faltantes: list<string>}
     */
    public static function leer(string $ruta, string $nombreOriginal): array
    {
        $extension = strtolower(pathinfo($nombreOriginal, \PATHINFO_EXTENSION));
        $matriz = $extension === 'csv' ? self::leerCsv($ruta) : self::leerXlsx($ruta);

        if ($matriz === []) {
            throw new \RuntimeException('El archivo está vacío.');
        }

        // Encabezados: se tolera mayúsculas, acentos y espacios.
        $crudos = array_shift($matriz);
        $encabezados = [];
        foreach ($crudos as $indice => $titulo) {
            $clave = self::normalizarEncabezado((string) $titulo);
            $encabezados[$indice] = self::ALIAS[$clave] ?? $clave;
        }

        $reconocidas = array_values(array_intersect($encabezados, self::COLUMNAS));
        if ($reconocidas === []) {
            throw new \RuntimeException(
                'No se reconoció ninguna columna. La primera fila tiene que tener los encabezados de la plantilla '
                . '(id_externo, punto_venta, tipo_cbte, ...).'
            );
        }

        $obligatorias = ['id_externo', 'tipo_cbte', 'concepto', 'fecha', 'doc_tipo', 'doc_nro', 'total'];
        $faltantes = array_values(array_diff($obligatorias, $reconocidas));

        $filas = [];
        foreach ($matriz as $indice => $fila) {
            $numeroFila = $indice + 2;   // +1 por el encabezado, +1 porque Excel cuenta desde 1
            $datos = [];
            foreach ($encabezados as $columna => $campo) {
                if (!in_array($campo, self::COLUMNAS, true)) {
                    continue;
                }
                $datos[$campo] = $fila[$columna] ?? null;
            }

            if (self::filaVacia($datos)) {
                continue;
            }
            $filas[] = ['fila_excel' => $numeroFila, 'datos' => self::normalizarFila($datos)];
        }

        return ['filas' => $filas, 'encabezados' => $reconocidas, 'faltantes' => $faltantes];
    }

    // ------------------------------------------------------------------ lectura

    private static function leerXlsx(string $ruta): array
    {
        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(false);     // se necesitan los formatos para las fechas
            $libro = $lector->load($ruta);
        } catch (\Throwable $e) {
            App::log('No se pudo leer el XLSX: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo abrir el archivo. Guardalo como .xlsx o .csv y volvé a subirlo.');
        }

        // La hoja "Facturas" si existe; si no, la primera.
        $hoja = $libro->getSheetByName('Facturas') ?? $libro->getSheet(0);
        $ultimaColumna = $hoja->getHighestDataColumn();
        $ultimaFila = $hoja->getHighestDataRow();
        $cantidadColumnas = Coordinate::columnIndexFromString($ultimaColumna);

        $matriz = [];
        for ($f = 1; $f <= $ultimaFila; $f++) {
            $fila = [];
            for ($c = 1; $c <= $cantidadColumnas; $c++) {
                $celda = $hoja->getCell(Coordinate::stringFromColumnIndex($c) . $f);
                $valor = $celda->getValue();

                // Fecha de Excel: viene como número con formato de fecha.
                if (is_numeric($valor) && FechaExcel::isDateTime($celda)) {
                    $valor = self::desdeSerialExcel((float) $valor);
                }
                $fila[$c - 1] = is_string($valor) ? trim($valor) : $valor;
            }
            $matriz[] = $fila;
        }

        return $matriz;
    }

    private static function leerCsv(string $ruta): array
    {
        $contenido = (string) file_get_contents($ruta);

        // Excel en español guarda con ANSI y separador ";".
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = (string) mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        $primeraLinea = strtok($contenido, "\n") ?: '';
        $separador = substr_count($primeraLinea, ';') >= substr_count($primeraLinea, ',') ? ';' : ',';

        $matriz = [];
        $manejador = fopen('php://memory', 'r+');
        if ($manejador === false) {
            throw new \RuntimeException('No se pudo leer el archivo CSV.');
        }
        fwrite($manejador, $contenido);
        rewind($manejador);
        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            $matriz[] = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $fila);
        }
        fclose($manejador);

        return $matriz;
    }

    // ------------------------------------------------------------- normalización

    public static function normalizarEncabezado(string $titulo): string
    {
        $titulo = mb_strtolower(trim($titulo));
        $titulo = strtr($titulo, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $titulo = preg_replace('/[^a-z0-9]+/', '_', $titulo) ?? $titulo;
        return trim($titulo, '_');
    }

    private static function filaVacia(array $datos): bool
    {
        foreach ($datos as $valor) {
            if (is_string($valor) ? trim($valor) !== '' : $valor !== null) {
                return false;
            }
        }
        return true;
    }

    /** Deja cada campo como lo espera la base: fechas Y-m-d, importes string decimal. */
    private static function normalizarFila(array $datos): array
    {
        foreach (self::COLUMNAS as $campo) {
            $datos[$campo] = $datos[$campo] ?? null;
        }

        foreach (self::FECHAS as $campo) {
            $datos[$campo] = self::normalizarFecha($datos[$campo]);
        }
        foreach (self::IMPORTES as $campo) {
            $datos[$campo] = self::normalizarImporte($datos[$campo]);
        }
        $datos['alicuota_iva'] = self::normalizarAlicuota($datos['alicuota_iva']);

        foreach (['punto_venta', 'tipo_cbte', 'concepto', 'doc_tipo', 'condicion_iva_receptor', 'asoc_tipo', 'asoc_pto_vta', 'asoc_nro'] as $campo) {
            $valor = $datos[$campo];
            $datos[$campo] = ($valor === null || trim((string) $valor) === '')
                ? null
                : (int) preg_replace('/\D/', '', (string) $valor);
        }

        $datos['doc_nro'] = preg_replace('/\D/', '', (string) ($datos['doc_nro'] ?? '')) ?? '';
        $datos['moneda'] = strtoupper(trim((string) ($datos['moneda'] ?? ''))) ?: 'PES';
        if ($datos['cotizacion'] === null || $datos['cotizacion'] === '') {
            $datos['cotizacion'] = '1';
        }

        foreach (['id_externo', 'razon_social', 'domicilio', 'email', 'descripcion'] as $campo) {
            $datos[$campo] = trim((string) ($datos[$campo] ?? ''));
        }

        return $datos;
    }

    /** Acepta fecha de Excel, DD/MM/AAAA, AAAA-MM-DD y DD-MM-AAAA. */
    public static function normalizarFecha(mixed $valor): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        $texto = trim((string) $valor);

        // Número serial de Excel (más de 20000 = después de 1954).
        if (is_numeric($texto) && (float) $texto > 20000 && (float) $texto < 90000) {
            return self::desdeSerialExcel((float) $texto);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $texto, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})#', $texto, $m) === 1) {
            $anio = (int) $m[3];
            if ($anio < 100) {
                $anio += 2000;
            }
            return sprintf('%04d-%02d-%02d', $anio, (int) $m[2], (int) $m[1]);
        }
        $t = strtotime($texto);
        return $t === false ? $texto : date('Y-m-d', $t);
    }

    /**
     * Número de serie de Excel → "Y-m-d".
     * Se convierte en UTC a propósito: con la zona horaria local, un día de Excel
     * (medianoche UTC) se corre al día anterior.
     */
    private static function desdeSerialExcel(float $serial): string
    {
        return FechaExcel::excelToDateTimeObject($serial, new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** "1.234,56" / "1234.56" / "$ 1.234,56" → "1234.56" */
    public static function normalizarImporte(mixed $valor): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        if (is_float($valor) || is_int($valor)) {
            return number_format((float) $valor, 2, '.', '');
        }
        $texto = trim((string) $valor);
        $texto = (string) preg_replace('/[^\d,.\-]/', '', $texto);

        $ultimaComa = strrpos($texto, ',');
        $ultimoPunto = strrpos($texto, '.');

        if ($ultimaComa !== false && $ultimoPunto !== false) {
            // El separador decimal es el que está más a la derecha; el otro es de miles.
            // Primero se saca el de miles y después se convierte el decimal.
            $texto = $ultimaComa > $ultimoPunto
                ? str_replace(',', '.', str_replace('.', '', $texto))   // 1.234,56
                : str_replace(',', '', $texto);                          // 1,234.56
        } elseif ($ultimaComa !== false) {
            // Una sola coma: decimal si tiene 1 o 2 dígitos detrás, si no es de miles.
            $decimales = strlen($texto) - $ultimaComa - 1;
            $texto = $decimales <= 2 ? str_replace(',', '.', $texto) : str_replace(',', '', $texto);
        }

        return is_numeric($texto) ? number_format((float) $texto, 2, '.', '') : $texto;
    }

    /** "21" / "21,00" / "21%" → "21"; vacío → "" */
    public static function normalizarAlicuota(mixed $valor): string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return '';
        }
        $texto = str_replace([',', '%', ' '], ['.', '', ''], trim((string) $valor));
        if (!is_numeric($texto)) {
            return trim((string) $valor);
        }
        $texto = rtrim(rtrim(number_format((float) $texto, 2, '.', ''), '0'), '.');
        return $texto === '' ? '0' : $texto;
    }
}
