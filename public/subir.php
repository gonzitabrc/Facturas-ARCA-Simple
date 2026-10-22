<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Lote\Importador;
use Facturador\Lote\Validador;
use Facturador\Seguridad\Auth;
use Facturador\Seguridad\Certificados;

App::iniciar();

// ------------------------------------------------------- descarga de la plantilla
// Va antes de pedir sesión: la plantilla es pública, como la ayuda, para que
// alguien pueda ver qué hay que completar antes de usar la app.
if (($_GET['descargar'] ?? '') === 'plantilla') {
    $ruta = App::raiz() . '/plantilla/plantilla_facturas.xlsx';
    if (!is_file($ruta)) {
        App::aviso('No se encontró la plantilla en el servidor.', 'error');
        App::redirigir('subir.php');
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_facturas.xlsx"');
    header('Content-Length: ' . (string) filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

Auth::exigirSesion();

$emisor = App::emisor();
if ($emisor === null) {
    App::aviso('Primero cargá los datos del emisor.', 'atencion');
    App::redirigir('configuracion.php');
}

$cert = Certificados::estado($emisor);

// ------------------------------------------------------------------ subir archivo
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'subir') {
    Auth::exigirCsrf();

    try {
        if (!$cert['tiene_par']) {
            throw new \RuntimeException('Antes de subir facturas hay que cargar el certificado de ARCA.');
        }

        $archivo = $_FILES['planilla'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Elegí el archivo con las facturas.');
        }
        if ((int) $archivo['error'] === \UPLOAD_ERR_INI_SIZE || (int) $archivo['error'] === \UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('El archivo es más grande de lo permitido.');
        }
        if ((int) $archivo['error'] !== \UPLOAD_ERR_OK) {
            throw new \RuntimeException('No se pudo subir el archivo. Probá de nuevo.');
        }

        $maximo = (int) App::config('upload.max_bytes', 5 * 1024 * 1024);
        if ((int) $archivo['size'] > $maximo) {
            throw new \RuntimeException('El archivo pesa más de ' . round($maximo / 1024 / 1024, 1) . ' MB.');
        }

        $nombreOriginal = (string) $archivo['name'];
        $extension = strtolower(pathinfo($nombreOriginal, \PATHINFO_EXTENSION));
        if (!in_array($extension, (array) App::config('upload.extensiones', ['xlsx', 'csv']), true)) {
            throw new \RuntimeException('El archivo tiene que ser .xlsx o .csv (descargá la plantilla si no la tenés).');
        }

        // Nombre aleatorio en disco: nunca el que trae el cliente.
        $sha = (string) hash_file('sha256', (string) $archivo['tmp_name']);
        $destino = App::storage('lotes/' . date('Y-m')) . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
        if (!move_uploaded_file((string) $archivo['tmp_name'], $destino)) {
            throw new \RuntimeException('No se pudo guardar el archivo en el servidor.');
        }
        @chmod($destino, 0640);

        $repetido = Db::una(
            'SELECT id, creado FROM lotes WHERE emisor_id = ? AND archivo_sha256 = ? ORDER BY id DESC LIMIT 1',
            [(int) $emisor['id'], $sha]
        );

        $lectura = Importador::leer($destino, $nombreOriginal);
        if ($lectura['filas'] === []) {
            throw new \RuntimeException('El archivo no tiene ninguna fila con datos.');
        }
        if ($lectura['faltantes'] !== []) {
            throw new \RuntimeException('Faltan columnas en la planilla: ' . implode(', ', $lectura['faltantes']) . '.');
        }

        // Tabla de condiciones de IVA (cacheada 24 h). Si ARCA no responde, se sigue sin esa validación.
        $condiciones = [];
        try {
            $condiciones = array_column((new Wsfe($emisor))->condicionesIvaReceptor(), 'id');
        } catch (\Throwable $e) {
            App::log('No se pudo traer la tabla de condiciones de IVA: ' . $e->getMessage());
        }

        $ultimasFechas = [];
        foreach (Db::todas(
            "SELECT tipo_cbte, MAX(fecha) AS fecha FROM comprobantes
              WHERE emisor_id = ? AND estado IN ('AUTORIZADO','PDF_OK') GROUP BY tipo_cbte",
            [(int) $emisor['id']]
        ) as $fila) {
            $ultimasFechas[(int) $fila['tipo_cbte']] = (string) $fila['fecha'];
        }

        $validadas = (new Validador($emisor, $condiciones, $ultimasFechas))->validar($lectura['filas']);

        $loteId = Db::enTransaccion(static function () use ($emisor, $nombreOriginal, $destino, $sha, $validadas): int {
            $loteId = Db::insertar('lotes', [
                'emisor_id'        => (int) $emisor['id'],
                'archivo_original' => $nombreOriginal,
                'archivo_path'     => $destino,
                'archivo_sha256'   => $sha,
                'estado'           => 'VALIDADO',
                'total_filas'      => count($validadas),
            ]);

            foreach ($validadas as $fila) {
                $datos = $fila['datos'];
                $conError = $fila['errores'] !== [];

                Db::insertar('comprobantes', [
                    'lote_id'                => $loteId,
                    'emisor_id'              => (int) $emisor['id'],
                    'fila_excel'             => $fila['fila_excel'],
                    'id_externo'             => (string) $datos['id_externo'],
                    'punto_venta'            => (int) ($datos['punto_venta'] ?? $emisor['punto_venta']),
                    'tipo_cbte'              => (int) ($datos['tipo_cbte'] ?? 0),
                    'concepto'               => (int) ($datos['concepto'] ?? 1),
                    'fecha'                  => (string) ($datos['fecha'] ?? date('Y-m-d')),
                    'doc_tipo'               => (int) ($datos['doc_tipo'] ?? 99),
                    'doc_nro'                => (string) ($datos['doc_nro'] ?? '0'),
                    'condicion_iva_receptor' => (int) ($datos['condicion_iva_receptor'] ?? 0),
                    'razon_social'           => $datos['razon_social'] !== '' ? $datos['razon_social'] : null,
                    'domicilio'              => $datos['domicilio'] !== '' ? $datos['domicilio'] : null,
                    'email'                  => $datos['email'] !== '' ? $datos['email'] : null,
                    'descripcion'            => (string) $datos['descripcion'],
                    'neto_gravado'           => (string) ($datos['neto_gravado'] ?? '0'),
                    'alicuota_iva'           => $datos['alicuota_iva'] !== '' ? (string) $datos['alicuota_iva'] : null,
                    'no_gravado'             => (string) ($datos['no_gravado'] ?? '0'),
                    'exento'                 => (string) ($datos['exento'] ?? '0'),
                    'iva'                    => (string) ($datos['iva'] ?? '0'),
                    'total'                  => (string) ($datos['total'] ?? '0'),
                    'moneda'                 => (string) ($datos['moneda'] ?? 'PES'),
                    'cotizacion'             => (string) ($datos['cotizacion'] ?? '1'),
                    'serv_desde'             => $datos['serv_desde'],
                    'serv_hasta'             => $datos['serv_hasta'],
                    'vto_pago'               => $datos['vto_pago'],
                    'asoc_tipo'              => $datos['asoc_tipo'],
                    'asoc_pto_vta'           => $datos['asoc_pto_vta'],
                    'asoc_nro'               => $datos['asoc_nro'],
                    'estado'                 => $conError ? 'ERROR_DATOS' : 'VALIDADO',
                    'error_msg'              => $conError ? Validador::mensaje($fila['fila_excel'], $fila['errores']) : null,
                    'observaciones_arca'     => $fila['advertencias'] === []
                        ? null
                        : json_encode(['advertencias' => $fila['advertencias']], \JSON_UNESCAPED_UNICODE),
                ]);
            }

            return $loteId;
        });

        if ($repetido !== null) {
            App::aviso(
                'Ojo: este mismo archivo ya se había subido el '
                . date('d/m/Y H:i', (int) strtotime((string) $repetido['creado']))
                . ' (lote ' . $repetido['id'] . '). Las facturas ya emitidas no se van a repetir.',
                'atencion'
            );
        }
        App::redirigir('subir.php?lote=' . $loteId);
    } catch (\Throwable $e) {
        App::log('Error subiendo planilla: ' . $e->getMessage());
        App::aviso($e->getMessage(), 'error');
        App::redirigir('subir.php');
    }
}

// --------------------------------------------------------- confirmar y emitir
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'emitir') {
    Auth::exigirCsrf();
    $loteId = (int) ($_POST['lote'] ?? 0);
    $lote = Db::una('SELECT * FROM lotes WHERE id = ? AND emisor_id = ?', [$loteId, (int) $emisor['id']]);
    if ($lote === null) {
        App::aviso('No se encontró ese lote.', 'error');
        App::redirigir('index.php');
    }
    Db::actualizar('lotes', $loteId, ['estado' => 'PROCESANDO', 'motivo_pausa' => null]);
    App::redirigir('lote.php?id=' . $loteId . '&arrancar=1');
}

// ------------------------------------------------------------------ vista previa
$loteId = (int) ($_GET['lote'] ?? 0);
$lote = $loteId > 0
    ? Db::una('SELECT * FROM lotes WHERE id = ? AND emisor_id = ?', [$loteId, (int) $emisor['id']])
    : null;

$filas = $lote === null ? [] : Db::todas(
    'SELECT * FROM comprobantes WHERE lote_id = ? ORDER BY fila_excel',
    [$loteId]
);

$listas = 0;
$conError = 0;
foreach ($filas as $fila) {
    if ($fila['estado'] === 'ERROR_DATOS') {
        $conError++;
    } else {
        $listas++;
    }
}

$contenido = App::capturar(static function () use ($lote, $filas, $listas, $conError, $cert, $emisor): void { ?>
    <?php if ($lote === null): ?>
        <div class="tarjeta">
            <h2>Subir la planilla</h2>
            <?php if (!$cert['tiene_par']): ?>
                <p class="aviso atencion">
                    Todavía no hay certificado de ARCA cargado.
                    <a href="configuracion.php">Cargalo en Configuración</a> antes de subir facturas.
                </p>
            <?php endif; ?>
            <p class="tenue">
                Una fila por factura, con los encabezados de la plantilla. Se aceptan .xlsx y .csv,
                hasta <?= round(((int) App::config('upload.max_bytes', 5242880)) / 1024 / 1024, 1) ?> MB.
            </p>
            <form method="post" enctype="multipart/form-data">
                <?= Auth::campoCsrf() ?>
                <input type="hidden" name="accion" value="subir">
                <label>Archivo de facturas
                    <input type="file" name="planilla" accept=".xlsx,.csv" required>
                </label>
                <button type="submit" class="boton">Subir y revisar</button>
                <a class="boton secundario" href="subir.php?descargar=plantilla">Descargar la plantilla</a>
            </form>
            <p class="tenue">
                Nada se emite al subir: primero vas a ver la revisión fila por fila.
                <a href="ayuda.php#manual">Cómo se llena la planilla</a>
            </p>
        </div>
    <?php else: ?>
        <div class="fila-acciones">
            <div>
                <strong><?= App::esc($lote['archivo_original']) ?></strong>
                <span class="tenue">
                    <?= count($filas) ?> fila<?= count($filas) === 1 ? '' : 's' ?> ·
                    <?= $listas ?> lista<?= $listas === 1 ? '' : 's' ?> para emitir ·
                    <?= $conError ?> para corregir
                </span>
            </div>
            <?php if ($listas > 0): ?>
                <form method="post">
                    <?= Auth::campoCsrf() ?>
                    <input type="hidden" name="accion" value="emitir">
                    <input type="hidden" name="lote" value="<?= (int) $lote['id'] ?>">
                    <button type="submit" class="boton">Emitir <?= $listas ?> factura<?= $listas === 1 ? '' : 's' ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($conError > 0): ?>
            <p class="aviso atencion">
                Las filas en rojo no se emiten. Podés emitir las verdes ahora y corregir las otras después,
                o corregir el archivo y volver a subirlo.
            </p>
        <?php endif; ?>

        <table class="tabla previa">
            <thead>
            <tr>
                <th>Fila</th>
                <th>Referencia</th>
                <th>Tipo</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $fila): ?>
                <?php
                $advertencias = [];
                if (($fila['observaciones_arca'] ?? null) !== null) {
                    $json = json_decode((string) $fila['observaciones_arca'], true);
                    $advertencias = is_array($json['advertencias'] ?? null) ? $json['advertencias'] : [];
                }
                $claseFila = $fila['estado'] === 'ERROR_DATOS' ? 'mal' : ($advertencias !== [] ? 'ojo' : 'bien');
                ?>
                <tr class="<?= $claseFila ?>">
                    <td><?= (int) $fila['fila_excel'] ?></td>
                    <td><?= App::esc($fila['id_externo']) ?></td>
                    <td><?= (int) $fila['tipo_cbte'] ?></td>
                    <td><?= App::esc(date('d/m/Y', (int) strtotime((string) $fila['fecha']))) ?></td>
                    <td>
                        <?= App::esc($fila['razon_social'] ?? 'Consumidor final') ?>
                        <span class="tenue"><?= (int) $fila['doc_tipo'] === 99 ? '' : App::esc($fila['doc_nro']) ?></span>
                    </td>
                    <td class="numero">$ <?= App::esc(number_format((float) $fila['total'], 2, ',', '.')) ?></td>
                    <td>
                        <?php if ($fila['estado'] === 'ERROR_DATOS'): ?>
                            <strong>Corregir:</strong> <?= App::esc($fila['error_msg']) ?>
                        <?php else: ?>
                            Lista
                            <?php foreach ($advertencias as $advertencia): ?>
                                <br><span class="tenue">Ojo: <?= App::esc($advertencia) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="tenue">
            <a href="subir.php">Subir otro archivo</a> ·
            <a href="index.php">Volver a los lotes</a>
        </p>
    <?php endif; ?>
<?php });

App::render($lote === null ? 'Subir facturas' : 'Revisión del archivo', $contenido, [
    'avisoCert' => App::avisoCertificado($emisor),
]);
