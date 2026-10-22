<?php
/**
 * Prepara config/config.php en el servidor, sin editar nada a mano.
 *
 *     php herramientas/preparar_config.php https://facturas.ddw.com.ar
 *     php herramientas/preparar_config.php https://facturas.ddw.com.ar --usuario=gonza --clave=lamia
 *
 * Genera la app_key (la que cifra las claves privadas de los certificados) y el hash
 * de la clave de acceso. Si no se pasa --clave, inventa una y la muestra UNA vez:
 * así no queda en el historial del shell.
 *
 * Con --forzar sobreescribe un config.php que ya exista (hace copia antes).
 */
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

$raiz = dirname(__DIR__);
$destino = $raiz . '/config/config.php';
$plantilla = $raiz . '/config/config.example.php';

// ------------------------------------------------------------------ argumentos
$url = null;
$usuario = 'admin';
$clave = null;
$forzar = false;
$zona = 'America/Argentina/Buenos_Aires';

foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--usuario=(.+)$/', $argumento, $m) === 1) {
        $usuario = trim($m[1]);
    } elseif (preg_match('/^--clave=(.+)$/', $argumento, $m) === 1) {
        $clave = $m[1];
    } elseif (preg_match('/^--zona=(.+)$/', $argumento, $m) === 1) {
        $zona = trim($m[1]);
    } elseif ($argumento === '--forzar') {
        $forzar = true;
    } elseif (!str_starts_with($argumento, '--')) {
        $url = rtrim(trim($argumento), '/');
    }
}

function morir(string $mensaje): never
{
    fwrite(\STDERR, $mensaje . \PHP_EOL);
    exit(1);
}

if ($url === null || !preg_match('#^https?://[a-z0-9.\-]+#i', $url)) {
    morir("Falta la URL de la app.\n  php herramientas/preparar_config.php https://facturas.ddw.com.ar");
}
if (!str_starts_with($url, 'https://')) {
    fwrite(\STDOUT, "Aviso: la URL no es https. En producción el sitio va con SSL." . \PHP_EOL);
}
if (preg_match('/^[A-Za-z0-9._-]{3,32}$/', $usuario) !== 1) {
    morir('El usuario tiene que ser de 3 a 32 letras, números, punto, guión o guión bajo.');
}
if (!is_file($plantilla)) {
    morir('No se encontró config/config.example.php');
}
if (is_file($destino) && !$forzar) {
    morir("Ya existe config/config.php. Si querés reemplazarlo: agregá --forzar (se guarda una copia).\n"
        . "OJO: cambiar la app_key deja inservibles las claves privadas ya cargadas.");
}

// -------------------------------------------------------------------- valores
$appKey = bin2hex(random_bytes(32));

$claveGenerada = false;
if ($clave === null) {
    $alfabeto = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $clave = '';
    for ($i = 0; $i < 16; $i++) {
        $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    $claveGenerada = true;
} elseif (strlen($clave) < 8) {
    morir('La clave tiene que tener al menos 8 caracteres.');
}

$hash = password_hash($clave, \PASSWORD_DEFAULT);
if (!is_string($hash)) {
    morir('No se pudo generar el hash de la clave.');
}

$contenido = (string) file_get_contents($plantilla);
$reemplazos = [
    "'app_key' => 'CAMBIAR_POR_64_CARACTERES_HEX'" => "'app_key' => '{$appKey}'",
    "'app_url' => 'https://facturas.ejemplo.com.ar'" => "'app_url' => '{$url}'",
    "'admin' => '\$2y\$10\$REEMPLAZAR_POR_HASH'" => "'{$usuario}' => '{$hash}'",
    "'zona_horaria' => 'America/Argentina/Buenos_Aires'" => "'zona_horaria' => '{$zona}'",
];
foreach ($reemplazos as $de => $a) {
    if (!str_contains($contenido, $de)) {
        morir("config/config.example.php no tiene la línea esperada:\n  {$de}");
    }
    $contenido = str_replace($de, $a, $contenido);
}

// En un servidor el login va siempre.
$contenido = preg_replace("/'login_requerido' => (true|false),/", "'login_requerido' => true,", $contenido, 1) ?? $contenido;

if (is_file($destino)) {
    $copia = $destino . '.anterior-' . date('Ymd-His');
    if (!@copy($destino, $copia)) {
        morir('No se pudo hacer la copia del config.php anterior.');
    }
    echo "Copia del anterior: " . basename($copia) . \PHP_EOL;
}

if (file_put_contents($destino, $contenido, \LOCK_EX) === false) {
    morir("No se pudo escribir {$destino} — revisá los permisos de config/.");
}
@chmod($destino, 0640);

// ------------------------------------------------------------------- resultado
echo str_repeat('-', 56), \PHP_EOL;
echo "config/config.php listo", \PHP_EOL;
echo str_repeat('-', 56), \PHP_EOL;
echo "  URL:            {$url}", \PHP_EOL;
echo "  Usuario:        {$usuario}", \PHP_EOL;
if ($claveGenerada) {
    echo "  Clave:          {$clave}", \PHP_EOL;
    echo "                  (anotala ahora: no se vuelve a mostrar)", \PHP_EOL;
} else {
    echo "  Clave:          la que pasaste por --clave", \PHP_EOL;
}
echo "  Login:          exigido", \PHP_EOL;
echo "  Zona horaria:   {$zona}", \PHP_EOL;
echo "  app_key:        generada (64 hex)", \PHP_EOL;
echo \PHP_EOL;
echo "La app_key cifra las claves privadas de los certificados: si se pierde o cambia,", \PHP_EOL;
echo "hay que volver a cargar el certificado de cada emisor.", \PHP_EOL;
echo \PHP_EOL;
echo "Siguiente paso:  php tests/revisar_servidor.php", \PHP_EOL;
