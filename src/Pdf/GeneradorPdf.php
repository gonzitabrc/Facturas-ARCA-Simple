<?php
declare(strict_types=1);

namespace Facturador\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Facturador\App;
use Facturador\Db;

/**
 * Genera el PDF del comprobante autorizado.
 *
 * Si falla, el comprobante queda AUTORIZADO (el CAE ya existe y no se toca):
 * el PDF se puede regenerar después desde la pantalla del lote.
 */
final class GeneradorPdf
{
    public const LETRAS = [
        1 => 'A', 2 => 'A', 3 => 'A',
        6 => 'B', 7 => 'B', 8 => 'B',
        11 => 'C', 12 => 'C', 13 => 'C',
    ];

    public const NOMBRES = [
        1 => 'FACTURA', 2 => 'NOTA DE DÉBITO', 3 => 'NOTA DE CRÉDITO',
        6 => 'FACTURA', 7 => 'NOTA DE DÉBITO', 8 => 'NOTA DE CRÉDITO',
        11 => 'FACTURA', 12 => 'NOTA DE DÉBITO', 13 => 'NOTA DE CRÉDITO',
    ];

    /** Nombres de la condición de IVA del receptor que van impresos. */
    public const CONDICIONES_IVA = [
        1 => 'IVA Responsable Inscripto',
        4 => 'IVA Sujeto Exento',
        5 => 'Consumidor Final',
        6 => 'Responsable Monotributo',
        7 => 'Sujeto No Categorizado',
        8 => 'Proveedor del Exterior',
        9 => 'Cliente del Exterior',
        10 => 'IVA Liberado - Ley 19.640',
        13 => 'Monotributista Social',
        15 => 'IVA No Alcanzado',
        16 => 'Monotributo Trabajador Independiente Promovido',
    ];

    /**
     * Genera el PDF si el comprobante está autorizado y todavía no lo tiene.
     * Nunca lanza: devuelve true si al final hay PDF.
     */
    public static function generarSiFalta(array $emisor, array $comprobante): bool
    {
        if (!in_array((string) $comprobante['estado'], ['AUTORIZADO', 'PDF_OK'], true) || ($comprobante['cae'] ?? null) === null) {
            return false;
        }
        if (($comprobante['pdf_path'] ?? null) !== null && is_file((string) $comprobante['pdf_path'])) {
            if ((string) $comprobante['estado'] !== 'PDF_OK') {
                Db::actualizar('comprobantes', (int) $comprobante['id'], ['estado' => 'PDF_OK']);
            }
            return true;
        }

        try {
            self::generar($emisor, $comprobante);
            return true;
        } catch (\Throwable $e) {
            App::log('No se pudo generar el PDF del comprobante ' . $comprobante['id'] . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Genera (o regenera) el PDF y deja el comprobante en PDF_OK. */
    public static function generar(array $emisor, array $comprobante): string
    {
        if (($comprobante['cae'] ?? null) === null || ($comprobante['nro'] ?? null) === null) {
            throw new \RuntimeException('El comprobante todavía no tiene CAE: no se puede generar el PDF.');
        }

        $token = (string) ($comprobante['pdf_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        $letra = self::LETRAS[(int) $comprobante['tipo_cbte']] ?? 'C';
        $nombre = sprintf(
            '%s-%s-%s-%s.pdf',
            $letra,
            str_pad((string) $comprobante['punto_venta'], 4, '0', \STR_PAD_LEFT),
            str_pad((string) $comprobante['nro'], 8, '0', \STR_PAD_LEFT),
            $token
        );
        $destino = App::storage(
            'pdf/' . $emisor['cuit'] . '/' . date('Y-m', (int) strtotime((string) $comprobante['fecha']))
        ) . '/' . $nombre;

        $html = self::html($emisor, $comprobante);

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);        // nada de traer recursos de internet
        $opciones->set('isHtml5ParserEnabled', true);
        $opciones->set('defaultFont', 'DejaVu Sans');    // tiene acentos y el símbolo $
        $opciones->set('chroot', App::raiz());
        $opciones->set('tempDir', App::storage('tmp'));
        $opciones->set('fontDir', App::storage('tmp/fuentes'));
        $opciones->set('fontCache', App::storage('tmp/fuentes'));

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        if (App::esDemo()) {
            self::marcaDeAgua($dompdf);
        }

        $salida = $dompdf->output();
        if ($salida === null || $salida === '' || file_put_contents($destino, $salida, \LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el PDF en ' . $destino);
        }
        @chmod($destino, 0640);

        Db::actualizar('comprobantes', (int) $comprobante['id'], [
            'pdf_path'  => $destino,
            'pdf_token' => $token,
            'estado'    => 'PDF_OK',
        ]);

        return $destino;
    }

    /**
     * Sello cruzado en los PDF de una instalación de demostración, para que un
     * comprobante de ejemplo no se pueda confundir con uno real.
     */
    private static function marcaDeAgua(Dompdf $dompdf): void
    {
        try {
            $lienzo = $dompdf->getCanvas();
            $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'bold')
                ?? $dompdf->getFontMetrics()->getFont('sans-serif', 'bold');
            if ($fuente === null) {
                App::log('Marca de agua del demo: no se encontró una fuente para dibujarla.');
                return;
            }
            App::log('Marca de agua del demo: dibujando con ' . basename((string) $fuente));
            $rojo = [0.86, 0.42, 0.40];
            $metricas = $dompdf->getFontMetrics();

            // A4 en puntos: 595 x 842. La marca va en diagonal, centrada en la hoja:
            // se mide el ancho real del texto para que no se salga ni se pise.
            $angulo = 30.0;
            $rad = deg2rad($angulo);
            $centroX = 595 / 2;
            $centroY = 842 / 2;

            foreach ([
                ['COMPROBANTE NO VÁLIDO', 34.0, 0.0],
                ['demo · Facturador ARCA simple', 15.0, 58.0],
            ] as [$texto, $tamanio, $corrimiento]) {
                $ancho = (float) $metricas->getTextWidth($texto, $fuente, $tamanio);

                // Punto de arranque para que el texto quede centrado en la hoja...
                $x = $centroX - ($ancho * cos($rad)) / 2;
                $y = $centroY + ($ancho * sin($rad)) / 2;

                // ...y corrido en perpendicular, para la segunda línea.
                $x += $corrimiento * sin($rad);
                $y += $corrimiento * cos($rad);

                $lienzo->page_text($x, $y, $texto, $fuente, $tamanio, $rojo, 0, 0, $angulo);
            }
        } catch (\Throwable $e) {
            App::log('No se pudo poner la marca de agua del demo: ' . $e->getMessage());
        }
    }

    /** HTML del comprobante (templates/factura.php). */
    public static function html(array $emisor, array $comprobante): string
    {
        $datos = [
            'emisor'      => $emisor,
            'c'           => $comprobante,
            'letra'       => self::LETRAS[(int) $comprobante['tipo_cbte']] ?? 'C',
            'nombre'      => self::NOMBRES[(int) $comprobante['tipo_cbte']] ?? 'COMPROBANTE',
            'codigo'      => (int) $comprobante['tipo_cbte'],
            'numero'      => str_pad((string) $comprobante['punto_venta'], 4, '0', \STR_PAD_LEFT)
                . '-' . str_pad((string) $comprobante['nro'], 8, '0', \STR_PAD_LEFT),
            'qr'          => Qr::dataUri($emisor, $comprobante),
            'logo'        => self::logo($emisor),
            'condicion'   => self::CONDICIONES_IVA[(int) $comprobante['condicion_iva_receptor']] ?? '',
            'condEmisor'  => match ((string) $emisor['condicion_iva']) {
                'RI' => 'IVA Responsable Inscripto',
                'EXENTO' => 'IVA Sujeto Exento',
                default => 'Responsable Monotributo',
            },
        ];

        ob_start();
        try {
            extract($datos, \EXTR_SKIP);
            require App::raiz() . '/templates/factura.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** El logo se incrusta como data URI: dompdf no sale a buscar archivos. */
    private static function logo(array $emisor): ?string
    {
        $ruta = (string) ($emisor['logo_path'] ?? '');
        if ($ruta === '' || !is_file($ruta)) {
            return null;
        }
        $tipo = strtolower(pathinfo($ruta, \PATHINFO_EXTENSION));
        $mime = match ($tipo) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => null,
        };
        if ($mime === null) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($ruta));
    }

    /** Formato de importes para el comprobante: 1.234,56 */
    public static function plata(string|float|null $valor): string
    {
        return number_format((float) ($valor ?? 0), 2, ',', '.');
    }
}
