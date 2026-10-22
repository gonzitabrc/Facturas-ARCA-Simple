<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Export\ExportadorCsv;
use Facturador\Pdf\GeneradorPdf;
use Facturador\Seguridad\Auth;

App::iniciar();
Auth::exigirSesion();

$emisor = App::emisor();
if ($emisor === null) {
    App::redirigir('configuracion.php');
}

$loteId = (int) ($_GET['id'] ?? ($_POST['lote'] ?? 0));
$lote = Db::una('SELECT * FROM lotes WHERE id = ? AND emisor_id = ?', [$loteId, (int) $emisor['id']]);
if ($lote === null) {
    App::aviso('No encontramos ese lote.', 'error');
    App::redirigir('index.php');
}

// ------------------------------------------------------------------- acciones
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'continuar') {
        Db::actualizar('lotes', $loteId, ['estado' => 'PROCESANDO', 'motivo_pausa' => null]);
        App::redirigir('lote.php?id=' . $loteId . '&arrancar=1');
    }

    if ($accion === 'pausar') {
        Db::actualizar('lotes', $loteId, ['estado' => 'PAUSADO', 'motivo_pausa' => 'Lo pausaste vos.']);
        App::aviso('Lote pausado. Las facturas ya emitidas quedan como están.');
        App::redirigir('lote.php?id=' . $loteId);
    }

    if ($accion === 'regenerar_pdf') {
        $id = (int) ($_POST['comprobante'] ?? 0);
        $comprobante = Db::una('SELECT * FROM comprobantes WHERE id = ? AND lote_id = ?', [$id, $loteId]);
        try {
            if ($comprobante === null) {
                throw new \RuntimeException('No encontramos esa fila.');
            }
            GeneradorPdf::generar($emisor, $comprobante);
            App::aviso('PDF regenerado.');
        } catch (\Throwable $e) {
            App::log('Error regenerando PDF: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('lote.php?id=' . $loteId);
    }

    // Revisión manual: el usuario ya miró en ARCA y decide.
    if ($accion === 'reintentar_fila' || $accion === 'descartar_fila') {
        $id = (int) ($_POST['comprobante'] ?? 0);
        $comprobante = Db::una('SELECT * FROM comprobantes WHERE id = ? AND lote_id = ?', [$id, $loteId]);
        if ($comprobante === null) {
            App::aviso('No encontramos esa fila.', 'error');
            App::redirigir('lote.php?id=' . $loteId);
        }
        if (($comprobante['cae'] ?? null) !== null) {
            // Un CAE no se revierte nunca.
            App::aviso('Esa factura ya tiene CAE: no se puede reintentar ni descartar. Si hay que corregirla va una nota de crédito.', 'error');
            App::redirigir('lote.php?id=' . $loteId);
        }

        if ($accion === 'reintentar_fila') {
            Db::actualizar('comprobantes', $id, [
                'estado'       => 'VALIDADO',
                'nro_esperado' => null,
                'error_cod'    => null,
                'error_msg'    => null,
            ]);
            Db::actualizar('lotes', $loteId, ['estado' => 'PROCESANDO', 'motivo_pausa' => null]);
            App::aviso('La fila ' . $comprobante['fila_excel'] . ' vuelve a la cola.');
            App::redirigir('lote.php?id=' . $loteId . '&arrancar=1');
        }

        Db::actualizar('comprobantes', $id, [
            'estado'    => 'RECHAZADO',
            'error_msg' => 'Descartada a mano: ' . ($comprobante['error_msg'] ?? 'sin emitir'),
        ]);
        Db::actualizar('lotes', $loteId, ['estado' => 'PROCESANDO', 'motivo_pausa' => null]);
        App::aviso('La fila ' . $comprobante['fila_excel'] . ' quedó descartada. Va a salir en errores.csv.');
        App::redirigir('lote.php?id=' . $loteId);
    }
}

// --------------------------------------------------------------------- datos
$filas = Db::todas('SELECT * FROM comprobantes WHERE lote_id = ? ORDER BY fila_excel', [$loteId]);

$cuentas = ['total' => count($filas), 'emitidas' => 0, 'pendientes' => 0, 'errores' => 0, 'en_duda' => 0, 'sin_pdf' => 0];
foreach ($filas as $fila) {
    match ((string) $fila['estado']) {
        'AUTORIZADO', 'PDF_OK' => $cuentas['emitidas']++,
        'VALIDADO' => $cuentas['pendientes']++,
        'ERROR_DATOS', 'RECHAZADO' => $cuentas['errores']++,
        default => $cuentas['en_duda']++,
    };
    if ((string) $fila['estado'] === 'AUTORIZADO') {
        $cuentas['sin_pdf']++;
    }
}

$arrancar = ($_GET['arrancar'] ?? '') === '1' && $cuentas['pendientes'] + $cuentas['en_duda'] > 0;
$puedeSeguir = $cuentas['pendientes'] + $cuentas['en_duda'] > 0;

$etiquetas = [
    'VALIDADO'        => 'Lista para emitir',
    'ENVIANDO'        => 'Enviando a ARCA',
    'AUTORIZADO'      => 'Emitida (falta el PDF)',
    'PDF_OK'          => 'Emitida',
    'RECHAZADO'       => 'Rechazada por ARCA',
    'ERROR_DATOS'     => 'Hay que corregir',
    'INCIERTO'        => 'Sin respuesta de ARCA',
    'REVISION_MANUAL' => 'Revisión manual',
    'CARGADO'         => 'Cargada',
];

$contenido = App::capturar(static function () use ($lote, $filas, $cuentas, $arrancar, $puedeSeguir, $etiquetas, $emisor): void { ?>
    <div class="fila-acciones">
        <div>
            <strong><?= App::esc($lote['archivo_original']) ?></strong>
            <span class="tenue">
                subido el <?= App::esc(date('d/m/Y H:i', (int) strtotime((string) $lote['creado']))) ?> ·
                <?= $cuentas['total'] ?> fila<?= $cuentas['total'] === 1 ? '' : 's' ?> ·
                <span class="etiqueta <?= App::esc(strtolower((string) $lote['estado'])) ?>"><?= App::esc($lote['estado']) ?></span>
            </span>
        </div>
        <div class="botones">
            <?php if ($puedeSeguir): ?>
                <form method="post" class="en-linea" data-formulario-emitir>
                    <?= Auth::campoCsrf() ?>
                    <input type="hidden" name="accion" value="continuar">
                    <input type="hidden" name="lote" value="<?= (int) $lote['id'] ?>">
                    <button type="submit" class="boton">
                        <?= $cuentas['emitidas'] > 0 || $cuentas['en_duda'] > 0 ? 'Continuar' : 'Emitir' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($lote['motivo_pausa'] ?? null) !== null): ?>
        <p class="aviso atencion">
            <strong>Lote pausado.</strong> <?= App::esc($lote['motivo_pausa']) ?>
        </p>
    <?php endif; ?>

    <!-- Panel de progreso: lo maneja app.js llamando a api/procesar.php de a una factura -->
    <div class="tarjeta progreso" id="progreso"
         data-lote="<?= (int) $lote['id'] ?>"
         data-arrancar="<?= $arrancar ? '1' : '0' ?>"
         <?= $puedeSeguir ? '' : 'hidden' ?>>
        <?= Auth::campoCsrf() ?>
        <div class="barra"><div class="relleno" style="width: <?= $cuentas['total'] > 0 ? (int) round(($cuentas['emitidas'] + $cuentas['errores']) / $cuentas['total'] * 100) : 0 ?>%"></div></div>
        <p class="estado">
            <span data-texto>
                <?= $cuentas['emitidas'] ?> de <?= $cuentas['total'] ?> emitidas<?= $cuentas['errores'] > 0 ? ' · ' . $cuentas['errores'] . ' con problemas' : '' ?>
            </span>
        </p>
        <p class="tenue" data-detalle></p>
        <button type="button" class="boton secundario" data-parar hidden>Pausar</button>
    </div>

    <table class="tabla previa">
        <thead>
        <tr>
            <th>Fila</th>
            <th>Referencia</th>
            <th>Cliente</th>
            <th>Total</th>
            <th>Comprobante</th>
            <th>Estado</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $fila): ?>
            <?php
            $estado = (string) $fila['estado'];
            $clase = match ($estado) {
                'PDF_OK', 'AUTORIZADO' => 'bien',
                'ERROR_DATOS', 'RECHAZADO' => 'mal',
                'INCIERTO', 'REVISION_MANUAL', 'ENVIANDO' => 'ojo',
                default => '',
            };
            ?>
            <tr class="<?= $clase ?>">
                <td><?= (int) $fila['fila_excel'] ?></td>
                <td><?= App::esc($fila['id_externo']) ?></td>
                <td>
                    <?= App::esc($fila['razon_social'] ?? 'Consumidor final') ?>
                    <?php if ((int) $fila['doc_tipo'] !== 99): ?>
                        <span class="tenue"><?= App::esc($fila['doc_nro']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="numero">$ <?= App::esc(number_format((float) $fila['total'], 2, ',', '.')) ?></td>
                <td>
                    <?php if (($fila['nro'] ?? null) !== null): ?>
                        <?= App::esc(ExportadorCsv::numero($fila)) ?><br>
                        <span class="tenue">CAE <?= App::esc((string) $fila['cae']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= App::esc($etiquetas[$estado] ?? $estado) ?>
                    <?php if (($fila['error_msg'] ?? null) !== null): ?>
                        <br><span class="tenue"><?= App::esc($fila['error_msg']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="acciones">
                    <?php if (($fila['pdf_token'] ?? null) !== null && ($fila['pdf_path'] ?? null) !== null): ?>
                        <a href="descargar.php?t=<?= App::esc($fila['pdf_token']) ?>&amp;ver=1" target="_blank">Ver PDF</a>
                    <?php endif; ?>

                    <?php if ($estado === 'AUTORIZADO'): ?>
                        <form method="post" class="en-linea">
                            <?= Auth::campoCsrf() ?>
                            <input type="hidden" name="accion" value="regenerar_pdf">
                            <input type="hidden" name="lote" value="<?= (int) $lote['id'] ?>">
                            <input type="hidden" name="comprobante" value="<?= (int) $fila['id'] ?>">
                            <button type="submit" class="enlace">Generar el PDF</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($estado, ['REVISION_MANUAL', 'INCIERTO'], true) && ($fila['cae'] ?? null) === null): ?>
                        <form method="post" class="en-linea">
                            <?= Auth::campoCsrf() ?>
                            <input type="hidden" name="accion" value="reintentar_fila">
                            <input type="hidden" name="lote" value="<?= (int) $lote['id'] ?>">
                            <input type="hidden" name="comprobante" value="<?= (int) $fila['id'] ?>">
                            <button type="submit" class="enlace">Volver a intentar</button>
                        </form>
                        <form method="post" class="en-linea">
                            <?= Auth::campoCsrf() ?>
                            <input type="hidden" name="accion" value="descartar_fila">
                            <input type="hidden" name="lote" value="<?= (int) $lote['id'] ?>">
                            <input type="hidden" name="comprobante" value="<?= (int) $fila['id'] ?>">
                            <button type="submit" class="enlace">Descartar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tarjeta">
        <h2>Descargas</h2>
        <p class="tenue">
            <code>emitidas.csv</code> es lo que se vuelve a cargar en el sistema de gestión;
            <code>errores.csv</code> tiene las mismas columnas de la planilla más el motivo, para corregir y volver a subir.
        </p>
        <p>
            <?php if ($cuentas['emitidas'] > 0): ?>
                <a class="boton secundario" href="exportar.php?lote=<?= (int) $lote['id'] ?>&amp;que=emitidas">emitidas.csv</a>
                <a class="boton secundario" href="exportar.php?lote=<?= (int) $lote['id'] ?>&amp;que=pdfs">pdfs.zip</a>
            <?php endif; ?>
            <?php if ($cuentas['errores'] + $cuentas['en_duda'] > 0): ?>
                <a class="boton secundario" href="exportar.php?lote=<?= (int) $lote['id'] ?>&amp;que=errores">errores.csv</a>
            <?php endif; ?>
            <a class="boton secundario" href="subir.php">Subir otro archivo</a>
        </p>
    </div>
<?php });

App::render('Lote ' . $loteId, $contenido, ['avisoCert' => App::avisoCertificado($emisor)]);
