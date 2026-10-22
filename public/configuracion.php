<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Arca\ArcaExcepcion;
use Facturador\Arca\Wsaa;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Seguridad\Auth;
use Facturador\Seguridad\Certificados;

App::iniciar();
Auth::exigirSesion();

/** Lee un archivo subido y devuelve su contenido, con mensajes claros si algo falla. */
$leerSubida = static function (string $campo, array $extensiones, string $queEs, bool $obligatorio = true): string {
    $archivo = $_FILES[$campo] ?? null;
    if (!is_array($archivo) || ($archivo['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_NO_FILE) {
        if (!$obligatorio) {
            return '';
        }
        throw new \RuntimeException('Elegí el archivo ' . $queEs . '.');
    }
    if ((int) $archivo['error'] !== \UPLOAD_ERR_OK) {
        throw new \RuntimeException('No se pudo subir ' . $queEs . '. Probá de nuevo.');
    }
    if ((int) $archivo['size'] > 64 * 1024) {
        throw new \RuntimeException('Ese archivo es demasiado grande para ser ' . $queEs . '.');
    }
    $extension = strtolower(pathinfo((string) $archivo['name'], \PATHINFO_EXTENSION));
    if (!in_array($extension, $extensiones, true)) {
        throw new \RuntimeException($queEs . ' tiene que ser un archivo ' . implode(' / ', array_map(static fn ($e) => '.' . $e, $extensiones)) . '.');
    }
    return (string) file_get_contents((string) $archivo['tmp_name']);
};

$emisor = App::emisor();

// --------------------------------------------------------- descarga del CSR
if (($_GET['descargar'] ?? '') === 'csr') {
    $pendiente = $emisor === null ? null : Certificados::pendiente($emisor);
    $ruta = $pendiente['csr_path'] ?? (string) ($emisor['csr_path'] ?? '');
    if ($ruta === '' || !is_file($ruta)) {
        App::aviso('No hay ningún pedido de certificado para descargar.', 'error');
        App::redirigir('configuracion.php');
    }
    $nombre = 'pedido-' . ($pendiente['alias'] ?? 'certificado') . '-' . $emisor['cuit'] . '.csr';
    header('Content-Type: application/pkcs10');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . (string) filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

$errores = [];
$datos = [
    'cuit'               => (string) ($emisor['cuit'] ?? ''),
    'razon_social'       => (string) ($emisor['razon_social'] ?? ''),
    'domicilio'          => (string) ($emisor['domicilio'] ?? ''),
    'condicion_iva'      => (string) ($emisor['condicion_iva'] ?? 'MONO'),
    'iibb'               => (string) ($emisor['iibb'] ?? ''),
    'inicio_actividades' => (string) ($emisor['inicio_actividades'] ?? ''),
    'punto_venta'        => (string) ($emisor['punto_venta'] ?? ''),
    'ambiente'           => (string) ($emisor['ambiente'] ?? 'homo'),
];

$condiciones = [
    'MONO'   => 'Monotributo',
    'RI'     => 'Responsable Inscripto',
    'EXENTO' => 'Exento',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? 'emisor');

    // ------------------------------------------------- datos del emisor
    if ($accion === 'emisor') {
        foreach (array_keys($datos) as $campo) {
            $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
        }
        $datos['cuit'] = preg_replace('/\D/', '', $datos['cuit']) ?? '';

        if (!App::cuitValido($datos['cuit'])) {
            $errores['cuit'] = 'El CUIT no es válido: revisá los 11 dígitos (el dígito verificador no cierra).';
        }
        if ($datos['razon_social'] === '') {
            $errores['razon_social'] = 'Poné el nombre o razón social tal como figura en ARCA.';
        }
        if ($datos['domicilio'] === '') {
            $errores['domicilio'] = 'El domicilio sale impreso en la factura.';
        }
        if (!isset($condiciones[$datos['condicion_iva']])) {
            $errores['condicion_iva'] = 'Elegí la condición frente al IVA.';
        }
        if ((int) $datos['punto_venta'] < 1 || (int) $datos['punto_venta'] > 99999) {
            $errores['punto_venta'] = 'El punto de venta es un número de 1 a 99999, el que creaste en ARCA para esta app.';
        }
        if ($datos['inicio_actividades'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['inicio_actividades'])) {
            $errores['inicio_actividades'] = 'La fecha de inicio de actividades va como AAAA-MM-DD.';
        }
        if (!in_array($datos['ambiente'], ['homo', 'prod'], true)) {
            $errores['ambiente'] = 'Elegí el ambiente.';
        }

        // El CUIT no se puede cambiar si ya se emitió: el certificado y la numeración cuelgan de él.
        if ($emisor !== null && $datos['cuit'] !== (string) $emisor['cuit']) {
            $yaEmitio = (int) Db::valor(
                'SELECT COUNT(*) FROM comprobantes WHERE emisor_id = ? AND nro IS NOT NULL',
                [(int) $emisor['id']]
            );
            if ($yaEmitio > 0) {
                $errores['cuit'] = 'No se puede cambiar el CUIT: ya hay comprobantes emitidos con este emisor.';
            }
        }

        if ($errores === []) {
            $fila = [
                'cuit'               => $datos['cuit'],
                'razon_social'       => $datos['razon_social'],
                'domicilio'          => $datos['domicilio'],
                'condicion_iva'      => $datos['condicion_iva'],
                'iibb'               => $datos['iibb'] !== '' ? $datos['iibb'] : null,
                'inicio_actividades' => $datos['inicio_actividades'] !== '' ? $datos['inicio_actividades'] : null,
                'punto_venta'        => (int) $datos['punto_venta'],
                'ambiente'           => $datos['ambiente'],
            ];
            if ($emisor === null) {
                $_SESSION['emisor_id'] = Db::insertar('emisores', $fila);
                App::aviso('Datos del emisor guardados. El paso que sigue es el certificado.');
            } else {
                // Cambiar de ambiente cambia de certificado: cada ambiente tiene el suyo.
                if ($datos['ambiente'] !== (string) $emisor['ambiente']) {
                    $otro = Certificados::estado(array_merge($emisor, ['ambiente' => $datos['ambiente']]));
                    $fila['key_path'] = null;
                    $fila['csr_path'] = null;
                    $fila['crt_path'] = null;
                    $fila['crt_vence'] = null;
                    if ($otro['tiene_par']) {
                        // Ya había un par guardado para ese ambiente: se vuelve a usar.
                        $dir = Certificados::dir(array_merge($emisor, ['ambiente' => $datos['ambiente']]));
                        $fila['key_path'] = $dir . '/clave.key.enc';
                        $fila['crt_path'] = $dir . '/certificado.crt';
                        $fila['crt_vence'] = $otro['vence'];
                    }
                    App::aviso($otro['tiene_par']
                        ? 'Ambiente cambiado. Se volvió a usar el certificado que ya tenías para ese ambiente.'
                        : 'Ambiente cambiado. Para este ambiente hay que generar el pedido y subir su certificado.');
                } else {
                    App::aviso('Datos del emisor actualizados.');
                }
                Db::actualizar('emisores', (int) $emisor['id'], $fila);
            }
            App::redirigir('configuracion.php');
        }
    }

    // ------------------------------------------------- generar clave + CSR
    if ($accion === 'generar_csr' && $emisor !== null) {
        try {
            $resultado = Certificados::generarPedido($emisor, (string) ($_POST['alias'] ?? ''));
            App::aviso('Pedido generado con el alias "' . $resultado['alias'] . '". Descargalo y subilo a ARCA.');
        } catch (\Throwable $e) {
            App::log('Error generando CSR: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('configuracion.php');
    }

    // ------------------------------------------------- subir el .crt
    if ($accion === 'subir_crt' && $emisor !== null) {
        try {
            $contenido = $leerSubida('crt', ['crt', 'pem', 'cer', 'txt'], 'el certificado .crt que descargaste de ARCA');
            $resultado = Certificados::guardarCertificado($emisor, $contenido);
            App::aviso(
                'Certificado "' . $resultado['alias'] . '" cargado. Vence el '
                . date('d/m/Y', (int) strtotime($resultado['vence'])) . '.'
            );
        } catch (\Throwable $e) {
            App::log('Error subiendo certificado: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('configuracion.php');
    }

    // ------------------------- importar un par .crt + .key que ya existe
    if ($accion === 'importar_par' && $emisor !== null) {
        try {
            $crt = $leerSubida('crt_par', ['crt', 'pem', 'cer', 'txt'], 'el certificado .crt');
            $key = $leerSubida('key_par', ['key', 'pem', 'txt'], 'la clave privada .key');
            $resultado = Certificados::importarPar($emisor, $crt, $key, (string) ($_POST['frase'] ?? ''));
            App::aviso(
                'Certificado "' . $resultado['alias'] . '" importado. Vence el '
                . date('d/m/Y', (int) strtotime($resultado['vence'])) . '. La clave quedó cifrada en el servidor.'
            );
        } catch (\Throwable $e) {
            App::log('Error importando certificado: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('configuracion.php');
    }

    // --------------------------------- quitar el certificado del servidor
    if ($accion === 'quitar_cert' && $emisor !== null) {
        try {
            $resultado = Certificados::quitar($emisor);
            App::aviso($resultado === 0
                ? 'No había certificado para quitar.'
                : 'Certificado y clave privada borrados del servidor (' . $resultado . ' archivos). En ARCA el certificado sigue existiendo.');
        } catch (\Throwable $e) {
            App::log('Error quitando el certificado: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('configuracion.php');
    }

    // ------------------------------------------------- probar con ARCA
    if ($accion === 'probar_arca' && $emisor !== null) {
        try {
            $wsfe = new Wsfe($emisor);
            $estado = $wsfe->dummy();
            if (!$estado['ok']) {
                App::aviso(
                    'ARCA contesta pero tiene servicios caídos (aplicación: ' . $estado['AppServer']
                    . ', base: ' . $estado['DbServer'] . ', autenticación: ' . $estado['AuthServer']
                    . '). No es problema de la app: hay que esperar.',
                    'atencion'
                );
            } else {
                $ta = Wsaa::ta($emisor);
                $tipo = in_array((string) $emisor['condicion_iva'], ['MONO', 'EXENTO'], true) ? 11 : 6;
                $ultimo = $wsfe->ultimoAutorizado($tipo);
                $condiciones = count($wsfe->condicionesIvaReceptor());
                App::aviso(sprintf(
                    'Conexión con ARCA OK. Ticket de acceso válido hasta %s. Último comprobante tipo %d '
                    . 'en el punto de venta %d: %s. Tabla de condiciones de IVA: %d valores.',
                    date('d/m/Y H:i', (int) strtotime($ta['expira'])),
                    $tipo,
                    (int) $emisor['punto_venta'],
                    $ultimo === 0 ? 'ninguno todavía' : (string) $ultimo,
                    $condiciones
                ));
            }
        } catch (ArcaExcepcion $e) {
            App::log('Prueba de conexión con ARCA: ' . $e->paraLog());
            App::aviso($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            App::log('Prueba de conexión con ARCA: ' . $e->getMessage());
            App::aviso($e->getMessage(), 'error');
        }
        App::redirigir('configuracion.php');
    }
}

$emisor = App::emisor();
$cert = $emisor === null ? null : Certificados::estado($emisor);

// Alias sugerido: el mes y año en que va a vencer el certificado nuevo (duran 2 años).
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dentroDeDosAnios = (int) strtotime('+2 years');
$aliasSugerido = $meses[(int) date('n', $dentroDeDosAnios) - 1] . date('Y', $dentroDeDosAnios);

$contenido = App::capturar(static function () use ($datos, $errores, $condiciones, $emisor, $cert, $aliasSugerido): void { ?>
    <form method="post" class="tarjeta">
        <?= Auth::campoCsrf() ?>
        <input type="hidden" name="accion" value="emisor">
        <?php if ($errores !== []): ?>
            <p class="aviso error">Revisá los campos marcados.</p>
        <?php endif; ?>

        <h2>Datos del emisor</h2>
        <p class="tenue">Salen impresos en cada factura, así que tienen que coincidir con los de ARCA.</p>

        <div class="grilla">
            <label>CUIT
                <input type="text" name="cuit" value="<?= App::esc($datos['cuit']) ?>" inputmode="numeric" placeholder="20123456789" required>
                <?php if (isset($errores['cuit'])): ?><small class="error"><?= App::esc($errores['cuit']) ?></small><?php endif; ?>
            </label>

            <label>Nombre o razón social
                <input type="text" name="razon_social" value="<?= App::esc($datos['razon_social']) ?>" required>
                <?php if (isset($errores['razon_social'])): ?><small class="error"><?= App::esc($errores['razon_social']) ?></small><?php endif; ?>
            </label>

            <label class="ancho">Domicilio
                <input type="text" name="domicilio" value="<?= App::esc($datos['domicilio']) ?>" required>
                <?php if (isset($errores['domicilio'])): ?><small class="error"><?= App::esc($errores['domicilio']) ?></small><?php endif; ?>
            </label>

            <label>Condición frente al IVA
                <select name="condicion_iva">
                    <?php foreach ($condiciones as $valor => $texto): ?>
                        <option value="<?= App::esc($valor) ?>"<?= $datos['condicion_iva'] === $valor ? ' selected' : '' ?>><?= App::esc($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Ingresos Brutos <span class="tenue">(opcional)</span>
                <input type="text" name="iibb" value="<?= App::esc($datos['iibb']) ?>">
            </label>

            <label>Inicio de actividades <span class="tenue">(opcional)</span>
                <input type="date" name="inicio_actividades" value="<?= App::esc($datos['inicio_actividades']) ?>">
                <?php if (isset($errores['inicio_actividades'])): ?><small class="error"><?= App::esc($errores['inicio_actividades']) ?></small><?php endif; ?>
            </label>

            <label>Punto de venta
                <input type="number" name="punto_venta" value="<?= App::esc($datos['punto_venta']) ?>" min="1" max="99999" required>
                <small class="tenue">El de tipo “Web Services” que creaste en ARCA, exclusivo para esta app.</small>
                <?php if (isset($errores['punto_venta'])): ?><small class="error"><?= App::esc($errores['punto_venta']) ?></small><?php endif; ?>
            </label>

            <label>Ambiente
                <select name="ambiente">
                    <option value="homo"<?= $datos['ambiente'] === 'homo' ? ' selected' : '' ?>>Homologación (pruebas)</option>
                    <option value="prod"<?= $datos['ambiente'] === 'prod' ? ' selected' : '' ?>>Producción</option>
                </select>
                <small class="tenue">Cada ambiente usa su propio certificado.</small>
            </label>
        </div>

        <button type="submit" class="boton">Guardar</button>
    </form>

    <div class="tarjeta">
        <h2>Certificado de ARCA</h2>

        <?php if ($emisor === null): ?>
            <p>Guardá primero los datos del emisor.</p>

        <?php else: ?>
            <?php if ($cert['tiene_par']): ?>
                <table class="tabla datos">
                    <tr><th>Alias</th><td><?= App::esc($cert['alias']) ?></td></tr>
                    <tr><th>Ambiente</th><td><?= $emisor['ambiente'] === 'prod' ? 'Producción' : 'Homologación' ?></td></tr>
                    <tr>
                        <th>Vence</th>
                        <td>
                            <?= App::esc(date('d/m/Y', (int) strtotime((string) $cert['vence']))) ?>
                            <?php if ($cert['dias'] !== null): ?>
                                <span class="tenue">
                                    <?= $cert['dias'] < 0 ? '(vencido)' : '(en ' . $cert['dias'] . ' día' . ($cert['dias'] === 1 ? '' : 's') . ')' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <p>Todavía no hay certificado en uso para este CUIT en este ambiente.</p>
            <?php endif; ?>

            <?php if ($cert['tiene_par']): ?>
                <p class="botones">
                    <form method="post" class="en-linea">
                        <?= Auth::campoCsrf() ?>
                        <input type="hidden" name="accion" value="probar_arca">
                        <button type="submit" class="boton secundario">Probar conexión con ARCA</button>
                    </form>
                    <form method="post" class="en-linea"
                          onsubmit="return confirm('Se borran del servidor el certificado y la clave privada de este CUIT. En ARCA el certificado sigue existiendo. ¿Seguro?');">
                        <?= Auth::campoCsrf() ?>
                        <input type="hidden" name="accion" value="quitar_cert">
                        <button type="submit" class="boton secundario">Quitar el certificado</button>
                    </form>
                </p>
                <p class="tenue">
                    “Probar conexión” le pregunta a ARCA si está en pie, pide el ticket de acceso con este
                    certificado y consulta el último comprobante del punto de venta. No emite nada.
                </p>
            <?php endif; ?>

            <div class="bloque">
                <h3>Subir o actualizar el certificado</h3>
                <p class="tenue">
                    Si ya tenés el certificado de ARCA (porque lo usás en otro sistema o lo acabás de
                    descargar), subí el <code>.crt</code> y su <code>.key</code> juntos. La app verifica que
                    sean pareja y que el CUIT coincida, guarda la clave cifrada y deja de usar el certificado
                    anterior (del que queda una copia de respaldo).
                </p>
                <form method="post" enctype="multipart/form-data">
                    <?= Auth::campoCsrf() ?>
                    <input type="hidden" name="accion" value="importar_par">
                    <div class="grilla">
                        <label>Certificado (.crt)
                            <input type="file" name="crt_par" accept=".crt,.pem,.cer,.txt" required>
                        </label>
                        <label>Clave privada (.key)
                            <input type="file" name="key_par" accept=".key,.pem,.txt" required>
                        </label>
                        <label>Contraseña de la clave <span class="tenue">(solo si la tiene)</span>
                            <input type="password" name="frase" autocomplete="off">
                        </label>
                    </div>
                    <button type="submit" class="boton"><?= $cert['tiene_par'] ? 'Actualizar el certificado' : 'Subir el certificado' ?></button>
                </form>
            </div>

            <?php if ($cert['pendiente'] !== null): ?>
                <div class="bloque">
                    <h3>Pedido pendiente: <?= App::esc($cert['pendiente']['alias']) ?></h3>
                    <ol class="pasos">
                        <li><a href="configuracion.php?descargar=csr">Descargar el pedido (.csr)</a></li>
                        <li>Subirlo en ARCA → <em>Administración de Certificados Digitales</em> → <em>Agregar alias</em>, con el mismo alias.</li>
                        <li>Autorizar el alias para <em>Facturación Electrónica</em> en <em>Administrador de Relaciones</em>.</li>
                        <li>Descargar el <code>.crt</code> y subirlo acá abajo.</li>
                    </ol>
                    <p class="tenue"><a href="ayuda.php#certificado">Ver los pasos con detalle</a></p>

                    <form method="post" enctype="multipart/form-data">
                        <?= Auth::campoCsrf() ?>
                        <input type="hidden" name="accion" value="subir_crt">
                        <label>Certificado firmado por ARCA (.crt)
                            <input type="file" name="crt" accept=".crt,.pem,.cer,.txt" required>
                        </label>
                        <button type="submit" class="boton">Subir certificado</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="bloque">
                <h3><?= $cert['tiene_par'] ? 'Renovar: generar un pedido nuevo' : 'No tengo certificado: generar el pedido (CSR)' ?></h3>
                <p class="tenue">
                    <?php if ($cert['tiene_par']): ?>
                        Se genera un pedido nuevo con otro alias. El certificado que está en uso sigue
                        funcionando hasta que subas el nuevo.
                    <?php else: ?>
                        La app genera la clave privada (queda cifrada en el servidor, no se descarga) y el
                        pedido que se sube a ARCA.
                    <?php endif; ?>
                    <?php if ($cert['pendiente'] !== null): ?>
                        <strong>Ojo:</strong> ya hay un pedido pendiente
                        (<?= App::esc($cert['pendiente']['alias']) ?>); si generás otro, ese queda sin efecto.
                    <?php endif; ?>
                </p>
                <form method="post">
                    <?= Auth::campoCsrf() ?>
                    <input type="hidden" name="accion" value="generar_csr">
                    <label>Alias
                        <input type="text" name="alias" value="<?= App::esc($aliasSugerido) ?>"
                               pattern="[A-Za-z0-9]{3,30}" maxlength="30" required>
                        <small class="tenue">Solo letras y números. Conviene que diga cuándo vence, por ejemplo <code><?= App::esc($aliasSugerido) ?></code>.</small>
                    </label>
                    <button type="submit" class="boton <?= $cert['tiene_par'] ? 'secundario' : '' ?>">
                        <?= $cert['tiene_par'] ? 'Generar pedido nuevo' : 'Generar pedido de certificado' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <p><a href="ayuda.php#certificado">Cómo generar o renovar el certificado, paso a paso</a></p>
    </div>
<?php });

App::render('Configuración', $contenido, ['avisoCert' => App::avisoCertificado($emisor)]);
