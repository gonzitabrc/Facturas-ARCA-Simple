<?php
/**
 * Corre todas las pruebas automáticas (las que no tocan ARCA).
 *
 *     php tests/verificar.php
 *     php tests/verificar.php 03          (solo el grupo que empieza con 03)
 *
 * Cada grupo corre en su propio proceso: trabaja con un emisor de prueba
 * (CUIT 20123456786, nombres con ZZTEST) y lo borra al terminar.
 * El detalle de lo que cubre cada uno está en tests/casos.md.
 */
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

// En una instalación de demostración la app bloquea las operaciones a propósito,
// así que las pruebas no tienen sentido ahí: se corren en una instalación normal.
require __DIR__ . '/../src/App.php';
\Facturador\App::iniciar(false);
if (\Facturador\App::esDemo()) {
    echo "Esta instalación está en modo demostración ('modo_demo' => true).", \PHP_EOL;
    echo 'Las pruebas se corren en una instalación normal, no acá.', \PHP_EOL;
    exit(0);
}

$filtro = $argv[1] ?? '';
$archivos = glob(__DIR__ . '/casos/*.php') ?: [];
sort($archivos);

$php = \PHP_BINARY;
$fallaron = [];
$totalOk = 0;
$totalFallas = 0;

foreach ($archivos as $archivo) {
    $nombre = basename($archivo, '.php');
    if ($filtro !== '' && !str_starts_with($nombre, $filtro)) {
        continue;
    }

    echo "\n", str_repeat('=', 60), "\n", $nombre, "\n", str_repeat('=', 60), "\n";

    $comando = escapeshellarg($php) . ' ' . escapeshellarg($archivo) . ' 2>&1';
    $salida = [];
    $codigo = 0;
    exec($comando, $salida, $codigo);

    foreach ($salida as $linea) {
        echo $linea, "\n";
        if (preg_match('/== (\d+) OK \/ (\d+) fallas ==/', $linea, $m) === 1) {
            $totalOk += (int) $m[1];
            $totalFallas += (int) $m[2];
        }
    }

    if ($codigo !== 0) {
        $fallaron[] = $nombre;
    }
}

echo "\n", str_repeat('=', 60), "\n";
if ($fallaron === []) {
    echo "TODO BIEN — {$totalOk} chequeos\n";
    exit(0);
}

echo "HAY FALLAS ({$totalFallas} de " . ($totalOk + $totalFallas) . " chequeos) en: " . implode(', ', $fallaron) . "\n";
exit(1);
