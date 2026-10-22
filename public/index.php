<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Seguridad\Auth;

App::iniciar();
Auth::exigirSesion();

$emisor = App::emisor();
$lotes = $emisor === null ? [] : Db::todas(
    'SELECT l.*,
            (SELECT COUNT(*) FROM comprobantes c WHERE c.lote_id = l.id AND c.estado IN (\'AUTORIZADO\',\'PDF_OK\')) AS autorizadas,
            (SELECT COUNT(*) FROM comprobantes c WHERE c.lote_id = l.id AND c.estado IN (\'ERROR_DATOS\',\'RECHAZADO\')) AS con_error
       FROM lotes l
      WHERE l.emisor_id = ?
      ORDER BY l.id DESC
      LIMIT 50',
    [(int) $emisor['id']]
);

$puedeSubir = is_file(__DIR__ . '/subir.php') && $emisor !== null && ($emisor['crt_path'] ?? null) !== null;

$contenido = App::capturar(static function () use ($emisor, $lotes, $puedeSubir): void { ?>
    <?php if ($emisor === null): ?>
        <div class="tarjeta">
            <h2>Primero, los datos del emisor</h2>
            <p>Cargá el CUIT, los datos que salen en el PDF y el punto de venta para empezar a facturar.</p>
            <p><a class="boton" href="configuracion.php">Cargar los datos</a></p>
        </div>
    <?php else: ?>
        <div class="fila-acciones">
            <div>
                <strong><?= App::esc($emisor['razon_social']) ?></strong>
                <span class="tenue">
                    CUIT <?= App::esc($emisor['cuit']) ?> ·
                    PV <?= str_pad((string) $emisor['punto_venta'], 4, '0', \STR_PAD_LEFT) ?> ·
                    <?= $emisor['ambiente'] === 'prod' ? 'Producción' : 'Homologación' ?>
                </span>
            </div>
            <div class="botones">
                <a class="boton secundario" href="subir.php?descargar=plantilla">Descargar la plantilla</a>
                <?php if ($puedeSubir): ?>
                    <a class="boton" href="subir.php">Subir archivo</a>
                <?php else: ?>
                    <a class="boton" href="configuracion.php">Terminar la configuración</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($lotes === []): ?>
            <div class="tarjeta vacio">
                <p>Todavía no subiste ningún archivo.</p>
                <p class="tenue">Cuando subas uno, acá vas a ver cada lote con su progreso, los CAE y las descargas.</p>
            </div>
        <?php else: ?>
            <table class="tabla">
                <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Subido</th>
                    <th>Filas</th>
                    <th>Autorizadas</th>
                    <th>Con problemas</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lotes as $lote): ?>
                    <tr>
                        <td><?= App::esc($lote['archivo_original']) ?></td>
                        <td><?= App::esc(date('d/m/Y H:i', (int) strtotime((string) $lote['creado']))) ?></td>
                        <td><?= (int) $lote['total_filas'] ?></td>
                        <td><?= (int) $lote['autorizadas'] ?></td>
                        <td><?= (int) $lote['con_error'] ?></td>
                        <td><span class="etiqueta <?= App::esc(strtolower((string) $lote['estado'])) ?>"><?= App::esc($lote['estado']) ?></span></td>
                        <td><a href="lote.php?id=<?= (int) $lote['id'] ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php });

App::render('Lotes', $contenido, ['avisoCert' => App::avisoCertificado($emisor)]);
