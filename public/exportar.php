<?php
declare(strict_types=1);

/** exportar.php?lote=N&que=emitidas|errores|pdfs */

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Export\ExportadorCsv;
use Facturador\Seguridad\Auth;

App::iniciar();
Auth::exigirSesion();

$emisor = App::emisor();
if ($emisor === null) {
    App::redirigir('configuracion.php');
}

$loteId = (int) ($_GET['lote'] ?? 0);
$lote = Db::una('SELECT * FROM lotes WHERE id = ? AND emisor_id = ?', [$loteId, (int) $emisor['id']]);
if ($lote === null) {
    App::morir('No encontramos el lote', 'Ese lote no existe o es de otro emisor.', 404);
}

$que = (string) ($_GET['que'] ?? 'emitidas');

try {
    if ($que === 'pdfs') {
        $ruta = ExportadorCsv::pdfsZip($lote);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="pdfs_lote' . $loteId . '.zip"');
        header('Content-Length: ' . (string) filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        @unlink($ruta);   // era temporal
        exit;
    }

    $contenido = $que === 'errores'
        ? ExportadorCsv::errores($lote)
        : ExportadorCsv::emitidas($lote);

    $nombre = ($que === 'errores' ? 'errores' : 'emitidas') . '_lote' . $loteId . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . (string) strlen($contenido));
    header('X-Content-Type-Options: nosniff');
    echo $contenido;
    exit;
} catch (\Throwable $e) {
    App::log('Error exportando: ' . $e->getMessage());
    App::aviso($e->getMessage(), 'error');
    App::redirigir('lote.php?id=' . $loteId);
}
