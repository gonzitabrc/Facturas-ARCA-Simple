<?php
declare(strict_types=1);

namespace Facturador\Pdf;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Facturador\App;

/**
 * Código QR del comprobante, según la especificación de ARCA (RG 4892):
 * la URL lleva en el parámetro ?p= un JSON en base64 con los datos del comprobante.
 *
 * Verificar la especificación vigente en arca.gob.ar/fe/qr antes de producción.
 */
final class Qr
{
    private const BASE = 'https://www.afip.gob.ar/fe/qr/?p=';

    /** @param array $emisor fila de emisores; @param array $c fila de comprobantes autorizada */
    public static function url(array $emisor, array $c): string
    {
        $datos = [
            'ver'        => 1,
            'fecha'      => (string) $c['fecha'],                       // AAAA-MM-DD
            'cuit'       => (int) $emisor['cuit'],
            'ptoVta'     => (int) $c['punto_venta'],
            'tipoCmp'    => (int) $c['tipo_cbte'],
            'nroCmp'     => (int) $c['nro'],
            'importe'    => (float) $c['total'],
            'moneda'     => (string) ($c['moneda'] ?? 'PES'),
            'ctz'        => (float) ($c['cotizacion'] ?? 1),
            'tipoDocRec' => (int) $c['doc_tipo'],
            'nroDocRec'  => (int) preg_replace('/\D/', '', (string) $c['doc_nro']),
            'tipoCodAut' => 'E',                                        // E = CAE
            'codAut'     => (int) $c['cae'],
        ];

        // PRESERVE_ZERO_FRACTION para que el importe viaje como decimal (1210.0 y no 1210).
        return self::BASE . base64_encode((string) json_encode(
            $datos,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /** PNG del QR como data URI, listo para el <img> del PDF. */
    public static function dataUri(array $emisor, array $c, int $tamanio = 220): string
    {
        $qr = new QrCode(
            data: self::url($emisor, $c),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $tamanio,
            margin: 0
        );

        try {
            return (new PngWriter())->write($qr)->getDataUri();
        } catch (\Throwable $e) {
            App::log('No se pudo generar el QR: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo generar el código QR del comprobante.', 0, $e);
        }
    }
}
