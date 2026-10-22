<?php
/**
 * Carga (o actualiza) el emisor y, si le pasás las rutas, importa el certificado.
 * Con --probar le hace a ARCA solo consultas de lectura: no emite nada.
 *
 *     php herramientas/preparar_emisor.php --cuit=20123456789 --razon="Nombre o razón social" \
 *         --domicilio="Dirección, Provincia" --clase=MONO --pv=4 --ambiente=prod \
 *         --crt=/ruta/certificado.crt --key=/ruta/clave.key --probar
 *
 * Opciones: --cuit --razon --domicilio --clase (MONO|RI|EXENTO) --pv --ambiente (homo|prod)
 *           --iibb --inicio --crt --key --frase --probar
 */
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Arca\ArcaExcepcion;
use Facturador\Arca\Wsaa;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Seguridad\Certificados;

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

App::iniciar();

function linea(string $t = ''): void
{
    echo $t, \PHP_EOL;
}

function morir(string $t): never
{
    fwrite(\STDERR, $t . \PHP_EOL);
    exit(1);
}

$o = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argumento, $m) === 1) {
        $o[$m[1]] = $m[2] ?? '1';
    }
}

// ------------------------------------------------------------------- emisor
$cuit = preg_replace('/\D/', '', (string) ($o['cuit'] ?? '')) ?? '';
if (!App::cuitValido($cuit)) {
    morir('Pasá un CUIT válido con --cuit=20123456789');
}

$clase = strtoupper((string) ($o['clase'] ?? 'MONO'));
if (!in_array($clase, ['MONO', 'RI', 'EXENTO'], true)) {
    morir('--clase tiene que ser MONO, RI o EXENTO');
}
$ambiente = (string) ($o['ambiente'] ?? 'homo') === 'prod' ? 'prod' : 'homo';
$pv = (int) ($o['pv'] ?? 0);

$emisor = Db::una('SELECT * FROM emisores WHERE cuit = ?', [$cuit]);

if ($emisor === null) {
    if ($pv < 1) {
        morir('Para crear el emisor hace falta el punto de venta: --pv=NUMERO');
    }
    $id = Db::insertar('emisores', [
        'cuit'               => $cuit,
        'razon_social'       => (string) ($o['razon'] ?? $cuit),
        'domicilio'          => (string) ($o['domicilio'] ?? '-'),
        'condicion_iva'      => $clase,
        'punto_venta'        => $pv,
        'ambiente'           => $ambiente,
        'iibb'               => isset($o['iibb']) ? (string) $o['iibb'] : null,
        'inicio_actividades' => isset($o['inicio']) ? (string) $o['inicio'] : null,
    ]);
    linea('Emisor creado (id ' . $id . ')');
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
} else {
    $cambios = [];
    foreach ([
        'razon_social'       => $o['razon'] ?? null,
        'domicilio'          => $o['domicilio'] ?? null,
        'iibb'               => $o['iibb'] ?? null,
        'inicio_actividades' => $o['inicio'] ?? null,
    ] as $columna => $valor) {
        if ($valor !== null) {
            $cambios[$columna] = (string) $valor;
        }
    }
    if ($pv > 0) {
        $cambios['punto_venta'] = $pv;
    }
    if (isset($o['clase'])) {
        $cambios['condicion_iva'] = $clase;
    }
    if (isset($o['ambiente'])) {
        $cambios['ambiente'] = $ambiente;
    }
    if ($cambios !== []) {
        Db::actualizar('emisores', (int) $emisor['id'], $cambios);
        linea('Emisor actualizado: ' . implode(', ', array_keys($cambios)));
        $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [(int) $emisor['id']]);
    } else {
        linea('Emisor ya estaba cargado (id ' . $emisor['id'] . ')');
    }
}

linea('  CUIT ' . $emisor['cuit'] . ' · ' . $emisor['razon_social']
    . ' · ' . $emisor['condicion_iva']
    . ' · punto de venta ' . $emisor['punto_venta']
    . ' · ' . ($emisor['ambiente'] === 'prod' ? 'PRODUCCIÓN' : 'homologación'));

// -------------------------------------------------------------- certificado
if (isset($o['crt']) || isset($o['key'])) {
    foreach (['crt', 'key'] as $cual) {
        if (!isset($o[$cual]) || !is_readable((string) $o[$cual])) {
            morir('No puedo leer el archivo de --' . $cual . ': ' . (string) ($o[$cual] ?? '(falta)'));
        }
    }
    try {
        $resultado = Certificados::importarPar(
            $emisor,
            (string) file_get_contents((string) $o['crt']),
            (string) file_get_contents((string) $o['key']),
            (string) ($o['frase'] ?? '')
        );
        linea('Certificado importado: alias ' . $resultado['alias'] . ', vence ' . $resultado['vence']);
        $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [(int) $emisor['id']]);
    } catch (\Throwable $e) {
        morir('No se pudo importar el certificado: ' . $e->getMessage());
    }
}

// ------------------------------------------------- generar un pedido (CSR)
// No toca el certificado en uso: queda como "pendiente" hasta que se suba el .crt.
if (isset($o['generar-csr'])) {
    try {
        $pedido = Certificados::generarPedido($emisor, (string) $o['generar-csr']);
        linea('Pedido generado para el alias "' . $pedido['alias'] . '"');
        linea('  Archivo a subir a ARCA: ' . $pedido['csr_path']);
        linea('  El certificado que está en uso no se tocó.');
    } catch (\Throwable $e) {
        morir('No se pudo generar el pedido: ' . $e->getMessage());
    }
}

$cert = Certificados::estado($emisor);
if ($cert['tiene_par']) {
    $crudo = (string) file_get_contents((string) $emisor['key_path']);
    linea('  Certificado: alias ' . $cert['alias'] . ', vence ' . $cert['vence'] . ' (' . $cert['dias'] . ' días)');
    linea('  Clave privada en disco: ' . (str_starts_with($crudo, 'FAC1.') && !str_contains($crudo, 'PRIVATE KEY')
        ? 'cifrada (AES-256-GCM)'
        : 'ATENCIÓN: no quedó cifrada'));
} else {
    linea('  Certificado: falta. Subilo desde Configuración o pasá --crt y --key.');
}

// -------------------------------------------------------- consultas a ARCA
if (!isset($o['probar'])) {
    linea();
    linea('Para probar la conexión con ARCA (solo lectura): agregá --probar');
    exit(0);
}
if (!$cert['tiene_par']) {
    morir('Sin certificado no se puede probar la conexión.');
}

$wsfe = new Wsfe($emisor);

linea();
linea('1. FEDummy');
try {
    $estado = $wsfe->dummy();
    linea('   App ' . $estado['AppServer'] . ' · Base ' . $estado['DbServer'] . ' · Autenticación ' . $estado['AuthServer']);
    if (!$estado['ok']) {
        morir('   ARCA tiene servicios caídos: esperá un rato.');
    }
} catch (\Throwable $e) {
    morir('   FALLA: ' . $e->getMessage());
}

linea();
linea('2. WSAA: ticket de acceso');
try {
    $ta = Wsaa::ta($emisor);
    linea('   Válido hasta ' . $ta['expira'] . ' (el token y el sign no se muestran)');
} catch (ArcaExcepcion $e) {
    linea('   ' . $e->categoria . ': ' . $e->getMessage());
    if ($e->mensajeArca !== null) {
        linea('   ARCA dijo: ' . $e->mensajeArca);
    }
    exit(2);
}

linea();
linea('3. Puntos de venta habilitados');
try {
    $puntos = $wsfe->puntosVenta();
    if ($puntos === []) {
        linea('   ARCA no devolvió ninguno: hay que crear un punto de venta tipo Web Services.');
    }
    foreach ($puntos as $punto) {
        linea('   PV ' . str_pad((string) $punto['nro'], 5)
            . ' ' . str_pad($punto['tipo'], 24)
            . ($punto['bloqueado'] ? 'BLOQUEADO ' : '')
            . ($punto['baja'] !== null ? 'baja ' . $punto['baja'] : '')
            . ((int) $punto['nro'] === (int) $emisor['punto_venta'] ? '  <-- el configurado acá' : ''));
    }
} catch (\Throwable $e) {
    linea('   FALLA: ' . $e->getMessage());
}

linea();
linea('4. Último comprobante autorizado en el punto de venta ' . $emisor['punto_venta']);
foreach ([11 => 'Factura C', 13 => 'Nota de crédito C', 6 => 'Factura B', 1 => 'Factura A'] as $tipo => $nombre) {
    if ($emisor['condicion_iva'] === 'MONO' && !in_array($tipo, [11, 13], true)) {
        continue;
    }
    if ($emisor['condicion_iva'] !== 'MONO' && in_array($tipo, [11, 13], true)) {
        continue;
    }
    try {
        linea('   ' . str_pad($nombre, 18) . 'último ' . $wsfe->ultimoAutorizado($tipo, (int) $emisor['punto_venta']));
    } catch (\Throwable $e) {
        linea('   ' . str_pad($nombre, 18) . 'FALLA ' . $e->getMessage());
    }
}

linea();
linea('5. Tabla de condiciones de IVA del receptor: ' . count($wsfe->condicionesIvaReceptor()) . ' valores');

linea();
linea('Listo. Ninguna de estas consultas emitió un comprobante.');
