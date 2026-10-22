<?php
/**
 * Reemplaza el bloque 'usuarios' de config/config.php sin tocar el resto
 * (en especial la app_key, que si cambia deja inservibles los certificados).
 *
 *     php poner_usuarios.php usuario:clave [usuario:clave ...]
 *
 * Sin regex en el reemplazo: los hash de bcrypt tienen "$" y preg_replace
 * los interpreta como referencias ($1, $2y...).
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
if (!is_file($raiz . '/config/config.php')) {
    $raiz = getcwd();
}
$archivo = $raiz . '/config/config.php';

$pares = array_slice($argv, 1);
if ($pares === []) {
    fwrite(STDERR, "Uso: php poner_usuarios.php usuario:clave [usuario:clave ...]\n");
    exit(1);
}

$contenido = file_get_contents($archivo);
if ($contenido === false) {
    fwrite(STDERR, "No pude leer {$archivo}\n");
    exit(1);
}

preg_match("/'app_key' => '([a-f0-9]{64})'/", $contenido, $m);
$appKeyAntes = $m[1] ?? null;
if ($appKeyAntes === null) {
    fwrite(STDERR, "No encontré la app_key: no sigo.\n");
    exit(1);
}

// Límites del bloque, por posición y no por expresión regular.
$inicio = strpos($contenido, "'usuarios' => [");
if ($inicio === false) {
    fwrite(STDERR, "No encontré el bloque 'usuarios'.\n");
    exit(1);
}
$fin = strpos($contenido, '],', $inicio);
if ($fin === false) {
    fwrite(STDERR, "No encontré el final del bloque 'usuarios'.\n");
    exit(1);
}
$viejo = substr($contenido, $inicio, $fin - $inicio + 2);

$lineas = ["'usuarios' => ["];
$resumen = [];
foreach ($pares as $par) {
    [$usuario, $clave] = array_pad(explode(':', $par, 2), 2, null);
    $usuario = trim((string) $usuario);
    if ($usuario === '' || $clave === null || $clave === '') {
        fwrite(STDERR, "Par inválido: {$par}\n");
        exit(1);
    }
    $hash = password_hash($clave, PASSWORD_DEFAULT);
    $lineas[] = "        '{$usuario}' => '{$hash}',";
    $resumen[] = $usuario;
}
$lineas[] = '    ],';
$nuevo = implode("\n", $lineas);

$salida = str_replace($viejo, $nuevo, $contenido);   // literal, sin regex
if ($salida === $contenido) {
    fwrite(STDERR, "No se aplicó el cambio.\n");
    exit(1);
}
if (!str_contains($salida, "'app_key' => '{$appKeyAntes}'")) {
    fwrite(STDERR, "La app_key cambiaría: aborto.\n");
    exit(1);
}

file_put_contents($archivo, $salida);
@chmod($archivo, 0640);

// Control final: se vuelve a leer el archivo y se verifica cada clave.
$config = require $archivo;
foreach ($pares as $par) {
    [$usuario, $clave] = explode(':', $par, 2);
    $hash = $config['usuarios'][$usuario] ?? '';
    echo str_pad($usuario, 12), password_verify($clave, $hash) ? 'clave OK' : 'CLAVE MAL', PHP_EOL;
}
echo 'app_key intacta: ', ($config['app_key'] === $appKeyAntes ? 'sí' : 'NO'), PHP_EOL;
echo 'login_requerido: ', ($config['login_requerido'] ? 'sí' : 'no'), PHP_EOL;
