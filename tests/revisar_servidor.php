<?php
/**
 * Chequeo del servidor, para correr después de subir la app:
 *
 *     php tests/revisar_servidor.php
 *
 * No toca ARCA, no emite nada y no escribe en la base más allá de crearla si falta.
 * Revisa PHP, extensiones, configuración, permisos y que el storage esté protegido.
 */
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

$raiz = dirname(__DIR__);
$problemas = 0;
$avisos = 0;

function resultado(string $estado, string $titulo, string $detalle = ''): void
{
    global $problemas, $avisos;
    if ($estado === 'MAL') {
        $problemas++;
    } elseif ($estado === 'OJO') {
        $avisos++;
    }
    echo str_pad($estado, 6), $titulo;
    if ($detalle !== '') {
        echo ' — ', $detalle;
    }
    echo \PHP_EOL;
}

echo \PHP_EOL, 'Revisión del servidor', \PHP_EOL, str_repeat('=', 56), \PHP_EOL;

// ------------------------------------------------------------------------ PHP
echo \PHP_EOL, 'PHP', \PHP_EOL;
version_compare(\PHP_VERSION, '8.1.0', '>=')
    ? resultado('OK', 'Versión de PHP', \PHP_VERSION)
    : resultado('MAL', 'Versión de PHP', \PHP_VERSION . ' — hace falta 8.1 o superior (cPanel: Select PHP Version)');

foreach (['soap', 'openssl', 'pdo_sqlite', 'mbstring', 'zip', 'gd'] as $extension) {
    extension_loaded($extension)
        ? resultado('OK', 'Extensión ' . $extension)
        : resultado('MAL', 'Extensión ' . $extension, 'activarla en Select PHP Version → Extensions');
}

$maximo = (int) ini_get('upload_max_filesize');
$maximo >= 5
    ? resultado('OK', 'upload_max_filesize', ini_get('upload_max_filesize'))
    : resultado('OJO', 'upload_max_filesize', ini_get('upload_max_filesize') . ' — conviene 8M o más para las planillas');

// Lo que importa no es el ini sino si se puede leer el WSDL: la extensión soap
// usa su propio transporte y funciona incluso con allow_url_fopen en Off.
try {
    $cliente = new \SoapClient('https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL', [
        'connection_timeout' => 15,
        'cache_wsdl'         => \WSDL_CACHE_NONE,
        'exceptions'         => true,
    ]);
    resultado('OK', 'Lee el WSDL de ARCA', count($cliente->__getFunctions()) . ' métodos (homologación)');
} catch (\Throwable $e) {
    resultado('MAL', 'Lee el WSDL de ARCA', trim(substr($e->getMessage(), 0, 120))
        . ' — puede ser el firewall de salida del hosting o allow_url_fopen ('
        . (ini_get('allow_url_fopen') ? 'On' : 'Off') . ')');
}

// ------------------------------------------------------------------- composer
echo \PHP_EOL, 'Dependencias', \PHP_EOL;
is_file($raiz . '/vendor/autoload.php')
    ? resultado('OK', 'vendor/ instalado')
    : resultado('MAL', 'vendor/ instalado', 'correr composer install --no-dev, o subir la carpeta vendor');

foreach ([
    'PhpOffice\PhpSpreadsheet\IOFactory' => 'phpoffice/phpspreadsheet',
    'Dompdf\Dompdf' => 'dompdf/dompdf',
    'Endroid\QrCode\QrCode' => 'endroid/qr-code',
] as $clase => $paquete) {
    if (is_file($raiz . '/vendor/autoload.php')) {
        require_once $raiz . '/vendor/autoload.php';
        class_exists($clase)
            ? resultado('OK', 'Librería ' . $paquete)
            : resultado('MAL', 'Librería ' . $paquete, 'falta en vendor/');
    }
}

// --------------------------------------------------------------- configuración
echo \PHP_EOL, 'Configuración', \PHP_EOL;
if (!is_file($raiz . '/config/config.php')) {
    resultado('MAL', 'config/config.php', 'correr: php herramientas/preparar_config.php https://tu-subdominio');
    echo \PHP_EOL, 'Sin configuración no se puede seguir revisando.', \PHP_EOL;
    exit(1);
}
resultado('OK', 'config/config.php');

require $raiz . '/src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Seguridad\Certificados;

App::iniciar();

$url = (string) App::config('app_url', '');
str_starts_with($url, 'https://')
    ? resultado('OK', 'app_url', $url)
    : resultado('OJO', 'app_url', $url === '' ? 'vacía' : $url . ' — en producción va con https');

App::config('login_requerido', true)
    ? resultado('OK', 'login_requerido', 'exigido')
    : resultado('MAL', 'login_requerido', 'está en false: cualquiera que entre a la URL puede emitir facturas');

(int) App::config('simular_timeout_cada_n', 0) === 0
    ? resultado('OK', 'simular_timeout_cada_n', 'apagado')
    : resultado('MAL', 'simular_timeout_cada_n', 'está prendido: eso fuerza errores a propósito, va en 0');

$zona = date_default_timezone_get();
$zona === 'America/Argentina/Buenos_Aires'
    ? resultado('OK', 'Zona horaria', $zona . ' — hoy es ' . date('d/m/Y H:i'))
    : resultado('OJO', 'Zona horaria', $zona . ' — la fecha del comprobante sale de acá');

(float) App::config('reglas.monto_identificacion_cf', '0') > 0
    ? resultado('OK', 'monto_identificacion_cf', (string) App::config('reglas.monto_identificacion_cf'))
    : resultado('OJO', 'monto_identificacion_cf', 'en 0 (desactivado): cargar el valor vigente de ARCA antes de facturar a consumidor final');

// -------------------------------------------------------------------- permisos
echo \PHP_EOL, 'Permisos y carpetas', \PHP_EOL;
foreach (['', 'certs', 'ta', 'lotes', 'pdf', 'locks', 'logs', 'tmp', 'cache'] as $sub) {
    try {
        $ruta = App::storage($sub);
        is_writable($ruta)
            ? resultado('OK', 'storage/' . ($sub === '' ? '' : $sub) . ' escribible')
            : resultado('MAL', 'storage/' . $sub, 'no se puede escribir — chmod -R u+w storage');
    } catch (\Throwable $e) {
        resultado('MAL', 'storage/' . $sub, $e->getMessage());
    }
}

$prueba = App::storage('tmp') . '/zz_prueba_escritura.txt';
if (@file_put_contents($prueba, 'ok') !== false && @unlink($prueba)) {
    resultado('OK', 'Escritura real en storage/tmp');
} else {
    resultado('MAL', 'Escritura real en storage/tmp', 'PHP no puede crear archivos');
}

// -------------------------------------------------------------------- la base
echo \PHP_EOL, 'Base de datos', \PHP_EOL;
try {
    $pdo = Db::pdo();
    $tablas = array_column(Db::todas("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
    $faltan = array_diff(['emisores', 'lotes', 'comprobantes', 'eventos'], $tablas);
    $faltan === []
        ? resultado('OK', 'Tablas', implode(', ', $tablas))
        : resultado('MAL', 'Tablas', 'faltan: ' . implode(', ', $faltan));

    $journal = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
    strtolower($journal) === 'wal'
        ? resultado('OK', 'journal_mode', 'wal')
        : resultado('OJO', 'journal_mode', $journal . ' — anda igual, pero WAL aguanta mejor el loop de emisión');
} catch (\Throwable $e) {
    resultado('MAL', 'Base de datos', $e->getMessage());
}

// ----------------------------------------------------------------- el emisor
echo \PHP_EOL, 'Emisor y certificado', \PHP_EOL;
$emisor = Db::una('SELECT * FROM emisores ORDER BY id LIMIT 1');
if ($emisor === null) {
    resultado('OJO', 'Emisor', 'todavía no hay ninguno: cargalo en Configuración');
} else {
    resultado('OK', 'Emisor', $emisor['cuit'] . ' — ' . $emisor['razon_social']
        . ' — PV ' . $emisor['punto_venta']
        . ' — ' . ($emisor['ambiente'] === 'prod' ? 'PRODUCCIÓN' : 'homologación'));

    $cert = Certificados::estado($emisor);
    if (!$cert['tiene_par']) {
        resultado('OJO', 'Certificado', 'falta cargarlo o importarlo en Configuración');
    } elseif ((int) $cert['dias'] < 0) {
        resultado('MAL', 'Certificado', 'vencido el ' . $cert['vence']);
    } elseif ((int) $cert['dias'] <= 30) {
        resultado('OJO', 'Certificado', 'alias ' . $cert['alias'] . ', vence en ' . $cert['dias'] . ' días');
    } else {
        resultado('OK', 'Certificado', 'alias ' . $cert['alias'] . ', vence ' . $cert['vence'] . ' (' . $cert['dias'] . ' días)');
    }
}

// --------------------------------------------------- storage expuesto a la web
echo \PHP_EOL, 'Storage protegido', \PHP_EOL;
$documentRoot = realpath($raiz . '/public');
$storageReal = realpath(App::storage(''));
if ($documentRoot === false || $storageReal === false) {
    resultado('OJO', 'Ubicación de storage', 'no se pudo resolver');
} elseif (str_starts_with($storageReal, $documentRoot)) {
    resultado('MAL', 'storage/ está dentro de public/', 'moverlo afuera, o verificar que el .htaccess lo bloquee');
} else {
    resultado('OK', 'storage/ fuera del document root', $storageReal);
}

foreach (['storage', 'config', 'src', 'db', 'templates', 'tests'] as $carpeta) {
    is_file($raiz . '/' . $carpeta . '/.htaccess')
        ? resultado('OK', $carpeta . '/.htaccess presente')
        : resultado('OJO', $carpeta . '/.htaccess', 'falta el archivo que bloquea el acceso por web');
}

if ($url !== '') {
    $base = preg_replace('#/+$#', '', $url) ?? $url;
    echo \PHP_EOL, 'Probá a mano que esto NO se pueda abrir (tiene que dar 403 o 404):', \PHP_EOL;
    echo '  ', $base, '/../storage/facturador.sqlite', \PHP_EOL;
    echo '  ', $base, '/../config/config.php', \PHP_EOL;
}

// ------------------------------------------------------------------- resumen
echo \PHP_EOL, str_repeat('=', 56), \PHP_EOL;
if ($problemas === 0 && $avisos === 0) {
    echo 'Todo en orden. Siguiente: php tests/emitir_prueba.php', \PHP_EOL;
    exit(0);
}
if ($problemas === 0) {
    echo "Sin problemas graves, {$avisos} cosa(s) para mirar.", \PHP_EOL;
    echo 'Siguiente: php tests/emitir_prueba.php', \PHP_EOL;
    exit(0);
}
echo "{$problemas} problema(s) que hay que resolver antes de facturar", $avisos > 0 ? " y {$avisos} aviso(s)." : '.', \PHP_EOL;
exit(1);
