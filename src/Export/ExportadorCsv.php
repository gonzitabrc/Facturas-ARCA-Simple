<?php
declare(strict_types=1);

namespace Facturador\Export;

use Facturador\App;
use Facturador\Db;
use Facturador\Lote\Importador;

/**
 * Exportaciones del lote.
 *
 * CSV con separador ";", UTF-8 con BOM y decimales con coma, que es lo que abre
 * bien el Excel en español sin pasar por el asistente de importación.
 */
final class ExportadorCsv
{
    private const SEPARADOR = ';';
    private const BOM = "\xEF\xBB\xBF";

    /** Columnas que se agregan a la plantilla en emitidas.csv */
    private const EXTRA_EMITIDAS = ['estado', 'nro_comprobante', 'comprobante', 'cae', 'vto_cae', 'url_pdf', 'observaciones'];

    /** Las emitidas, con CAE y link al PDF: es lo que se vuelve a cargar en el sistema de gestión. */
    public static function emitidas(array $lote): string
    {
        $filas = Db::todas(
            "SELECT * FROM comprobantes WHERE lote_id = ? AND estado IN ('AUTORIZADO','PDF_OK') ORDER BY fila_excel",
            [(int) $lote['id']]
        );

        $salida = [array_merge(Importador::COLUMNAS, self::EXTRA_EMITIDAS)];
        foreach ($filas as $c) {
            $salida[] = array_merge(self::columnasPlantilla($c), [
                'PDF_OK' === $c['estado'] ? 'Emitida' : 'Emitida (sin PDF)',
                (string) $c['nro'],
                self::numero($c),
                (string) $c['cae'],
                self::fecha($c['vto_cae']),
                ($c['pdf_token'] ?? null) !== null ? App::url('descargar.php?t=' . $c['pdf_token']) : '',
                self::observaciones($c),
            ]);
        }

        return self::armar($salida);
    }

    /**
     * Las que no salieron, con las MISMAS columnas de la plantilla + motivo:
     * se corrige el archivo y se vuelve a subir tal cual.
     */
    public static function errores(array $lote): string
    {
        $filas = Db::todas(
            "SELECT * FROM comprobantes
              WHERE lote_id = ? AND estado IN ('ERROR_DATOS','RECHAZADO','REVISION_MANUAL','INCIERTO')
              ORDER BY fila_excel",
            [(int) $lote['id']]
        );

        $salida = [array_merge(Importador::COLUMNAS, ['motivo'])];
        foreach ($filas as $c) {
            $salida[] = array_merge(self::columnasPlantilla($c), [
                (string) ($c['error_msg'] ?? 'Pendiente de resolver en ARCA.'),
            ]);
        }

        return self::armar($salida);
    }

    /** ZIP con los PDF del lote. Devuelve la ruta del archivo temporal. */
    public static function pdfsZip(array $lote): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene la extensión zip de PHP.');
        }

        $filas = Db::todas(
            "SELECT * FROM comprobantes WHERE lote_id = ? AND pdf_path IS NOT NULL ORDER BY fila_excel",
            [(int) $lote['id']]
        );
        if ($filas === []) {
            throw new \RuntimeException('Todavía no hay PDF generados en este lote.');
        }

        $ruta = App::storage('tmp') . '/pdfs_lote' . (int) $lote['id'] . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo armar el ZIP.');
        }

        foreach ($filas as $c) {
            if (is_file((string) $c['pdf_path'])) {
                $zip->addFile((string) $c['pdf_path'], basename((string) $c['pdf_path']));
            }
        }
        $zip->close();

        return $ruta;
    }

    // ------------------------------------------------------------------ interno

    /** Los valores de la fila en el orden exacto de la plantilla. */
    private static function columnasPlantilla(array $c): array
    {
        $valores = [];
        foreach (Importador::COLUMNAS as $columna) {
            $valor = $c[$columna] ?? '';
            $valores[] = match ($columna) {
                'fecha', 'serv_desde', 'serv_hasta', 'vto_pago' => self::fecha($valor),
                'neto_gravado', 'no_gravado', 'exento', 'total', 'cotizacion' => self::decimal($valor),
                default => (string) ($valor ?? ''),
            };
        }
        return $valores;
    }

    private static function armar(array $filas): string
    {
        $salida = self::BOM;
        foreach ($filas as $fila) {
            $salida .= implode(self::SEPARADOR, array_map([self::class, 'celda'], $fila)) . "\r\n";
        }
        return $salida;
    }

    /** Entre comillas si hace falta; las comillas internas se duplican. */
    private static function celda(string|int|float|null $valor): string
    {
        $texto = (string) ($valor ?? '');
        if ($texto === '') {
            return '';
        }
        if (preg_match('/[";\r\n]/', $texto) === 1) {
            return '"' . str_replace('"', '""', $texto) . '"';
        }
        return $texto;
    }

    private static function decimal(string|int|float|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return number_format((float) $valor, 2, ',', '');
    }

    private static function fecha(string|null $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        $t = strtotime($valor);
        return $t === false ? $valor : date('d/m/Y', $t);
    }

    public static function numero(array $c): string
    {
        if (($c['nro'] ?? null) === null) {
            return '';
        }
        return str_pad((string) $c['punto_venta'], 4, '0', \STR_PAD_LEFT)
            . '-' . str_pad((string) $c['nro'], 8, '0', \STR_PAD_LEFT);
    }

    private static function observaciones(array $c): string
    {
        if (($c['observaciones_arca'] ?? null) === null) {
            return '';
        }
        $json = json_decode((string) $c['observaciones_arca'], true);
        if (!is_array($json)) {
            return '';
        }
        $textos = [];
        foreach ($json as $clave => $item) {
            if ($clave === 'advertencias' && is_array($item)) {
                $textos = array_merge($textos, $item);
                continue;
            }
            if (is_array($item)) {
                $textos[] = (string) ($item['mensaje'] ?? ($item['mensaje_arca'] ?? ''));
            }
        }
        return implode(' · ', array_filter($textos));
    }
}
