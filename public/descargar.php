<?php
declare(strict_types=1);

/** Sirve el PDF de un comprobante por token: descargar.php?t=xxxxx */

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Seguridad\Auth;

App::iniciar();
Auth::exigirSesion();

$token = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    App::morir('Link inválido', 'Ese link de comprobante no es válido.', 404);
}

$comprobante = Db::una(
    'SELECT c.*, e.cuit FROM comprobantes c
       JOIN emisores e ON e.id = c.emisor_id
      WHERE c.pdf_token = ?',
    [$token]
);

if ($comprobante === null || ($comprobante['pdf_path'] ?? null) === null || !is_file((string) $comprobante['pdf_path'])) {
    App::morir('No encontramos el comprobante', 'El PDF de ese comprobante no está disponible. Podés regenerarlo desde la pantalla del lote.', 404);
}

$nombre = sprintf(
    '%s-%s-%s.pdf',
    \Facturador\Pdf\GeneradorPdf::LETRAS[(int) $comprobante['tipo_cbte']] ?? 'C',
    str_pad((string) $comprobante['punto_venta'], 4, '0', \STR_PAD_LEFT),
    str_pad((string) $comprobante['nro'], 8, '0', \STR_PAD_LEFT)
);

$ruta = (string) $comprobante['pdf_path'];
$enLinea = ($_GET['ver'] ?? '') === '1';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"');
header('Content-Length: ' . (string) filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($ruta);
exit;
