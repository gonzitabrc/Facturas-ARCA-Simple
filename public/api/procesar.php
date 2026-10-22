<?php
declare(strict_types=1);

/**
 * Procesa UNA factura por llamada y devuelve el progreso en JSON.
 * El navegador la llama en loop hasta que el lote termina o se pausa.
 */

require __DIR__ . '/../../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Lote\Procesador;
use Facturador\Seguridad\Auth;

App::iniciar();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    App::json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

if (!Auth::logueado() && Auth::loginRequerido()) {
    App::json(['ok' => false, 'error' => 'La sesión expiró. Recargá la página.'], 401);
}

// El token puede venir en el cuerpo JSON o en el encabezado X-CSRF-Token.
$cuerpo = json_decode((string) file_get_contents('php://input'), true);
$cuerpo = is_array($cuerpo) ? $cuerpo : [];
if (!isset($_POST['csrf']) && isset($cuerpo['csrf'])) {
    $_POST['csrf'] = (string) $cuerpo['csrf'];
}
Auth::exigirCsrf();

$emisor = App::emisor();
if ($emisor === null) {
    App::json(['ok' => false, 'error' => 'No hay emisor configurado.'], 400);
}

$loteId = (int) ($cuerpo['lote'] ?? 0);
$lote = Db::una('SELECT * FROM lotes WHERE id = ? AND emisor_id = ?', [$loteId, (int) $emisor['id']]);
if ($lote === null) {
    App::json(['ok' => false, 'error' => 'No se encontró ese lote.'], 404);
}

if (in_array((string) $lote['estado'], ['FINALIZADO', 'FINALIZADO_CON_ERRORES'], true)) {
    App::json(['ok' => true, 'termino' => true, 'pausado' => false, 'estado_lote' => $lote['estado']]);
}

// Un paso: toma el lock, reconcilia si hace falta y emite una factura.
$progreso = (new Procesador($emisor, $lote))->paso();

App::json(array_merge(['ok' => true], $progreso));
