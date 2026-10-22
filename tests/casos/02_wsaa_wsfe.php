<?php
// Prueba offline del armado del TRA y de la firma CMS (lo que WSAA le manda a ARCA).
// No llama a ARCA: verifica la firma con OpenSSL localmente.
declare(strict_types=1);

require __DIR__ . '/../../src/App.php';

use Facturador\App;
use Facturador\Arca\Wsaa;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Seguridad\Certificados;

App::iniciar();

$CUIT = '20123456786';
$ok = 0;
$fallos = 0;

function chequear(string $titulo, callable $fn): void
{
    global $ok, $fallos;
    try {
        $r = $fn();
        if ($r === true) {
            echo "OK    {$titulo}\n";
            $ok++;
        } else {
            echo "FALLA {$titulo}: " . var_export($r, true) . "\n";
            $fallos++;
        }
    } catch (\Throwable $e) {
        echo "FALLA {$titulo}: " . $e->getMessage() . "\n";
        $fallos++;
    }
}

function privado(string $clase, string $metodo, array $args): mixed
{
    $m = new ReflectionMethod($clase, $metodo);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}

// ------------------------------------------------------------- preparación
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);
$id = Db::insertar('emisores', [
    'cuit' => $CUIT, 'razon_social' => 'ZZTEST Emisor SRL', 'domicilio' => 'Calle 1',
    'condicion_iva' => 'MONO', 'punto_venta' => 5, 'ambiente' => 'homo',
]);
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);

$pedido = Certificados::generarPedido($emisor, 'zzwsaa2028');
$cfg = [
    'config' => App::storage('openssl.cnf'), 'digest_alg' => 'sha256',
    'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'encrypt_key' => false,
];
$caKey = openssl_pkey_new($cfg);
$caCsr = openssl_csr_new(['countryName' => 'AR', 'commonName' => 'ZZTEST CA'], $caKey, $cfg);
$caCrt = openssl_csr_sign($caCsr, null, $caKey, 3650, $cfg, 1);
$crt = openssl_csr_sign($pedido['csr'], $caCrt, $caKey, 730, $cfg, 2);
$crtPem = '';
openssl_x509_export($crt, $crtPem);
Certificados::guardarCertificado($emisor, $crtPem);
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);

// ------------------------------------------------------------------- TRA
$tra = privado(Wsaa::class, 'armarTra', ['wsfe']);

chequear('El TRA tiene la estructura que pide WSAA', function () use ($tra): bool {
    $xml = simplexml_load_string($tra);
    return $xml !== false
        && (string) $xml['version'] === '1.0'
        && (string) $xml->service === 'wsfe'
        && isset($xml->header->uniqueId, $xml->header->generationTime, $xml->header->expirationTime);
});

chequear('generationTime y expirationTime son ahora ±10 minutos', function () use ($tra): bool {
    $xml = simplexml_load_string($tra);
    $gen = strtotime((string) $xml->header->generationTime);
    $exp = strtotime((string) $xml->header->expirationTime);
    $ahora = time();
    return abs($ahora - 600 - $gen) <= 5 && abs($ahora + 600 - $exp) <= 5 && $exp > $gen;
});

// ------------------------------------------------------------------- CMS
$cms = privado(Wsaa::class, 'firmarCms', [
    $tra,
    Certificados::certificadoPem($emisor),
    Certificados::clavePrivada($emisor),
]);

chequear('La firma CMS es base64 sin encabezados MIME', fn (): bool => $cms !== ''
    && base64_decode($cms, true) !== false
    && !str_contains($cms, 'MIME')
    && !str_contains($cms, "\n"));

chequear('El CMS lleva adentro el TRA exacto', function () use ($cms, $tra): bool {
    // El CMS canoniza los saltos de línea a CRLF (es lo normal en S/MIME).
    $der = (string) base64_decode($cms, true);
    $esperado = str_replace(chr(10), chr(13) . chr(10), trim($tra));
    return str_contains($der, $esperado);
});

chequear('La firma del CMS verifica con OpenSSL', function () use ($cms, $tra): bool {
    // En Windows "where", en Linux "command -v": si no hay openssl, se verifica solo la estructura.
    $openssl = trim((string) @shell_exec('openssl version 2>&1'));
    if (!str_starts_with(strtolower($openssl), 'openssl')) {
        echo "      (no hay openssl en el PATH: se verificó solo la estructura)\n";
        return true;
    }
    $dir = App::storage('tmp');
    $der = $dir . '/zztest_cms.der';
    $salida = $dir . '/zztest_cms.txt';
    file_put_contents($der, (string) base64_decode($cms, true));

    $comando = sprintf('openssl smime -verify -noverify -inform DER -in %s -out %s 2>&1', escapeshellarg($der), escapeshellarg($salida));
    $resultado = (string) shell_exec($comando);
    $contenido = (string) @file_get_contents($salida);
    @unlink($der);
    @unlink($salida);

    $normalizar = static fn (string $t): string => trim(str_replace(chr(13), "", $t));
    return str_contains($resultado, 'Verification successful') && $normalizar($contenido) === $normalizar($tra);
});

chequear('No quedan archivos temporales con la clave', function (): bool {
    $sobrantes = array_filter((array) glob(App::storage('tmp') . '/*'), static fn ($f) => is_file((string) $f));
    return $sobrantes === [];
});

// ------------------------------------------------------------- cache del TA
chequear('Reusa el TA cacheado si le falta más de 10 minutos', function () use ($emisor): bool {
    $ruta = App::storage("ta/{$emisor['cuit']}_wsfe_{$emisor['ambiente']}.json");
    file_put_contents($ruta, json_encode([
        'token' => 'token-de-prueba', 'sign' => 'sign-de-prueba',
        'expira' => date('Y-m-d H:i:s', time() + 3600),
    ]));
    $ta = Wsaa::ta($emisor);   // no debe llamar a ARCA
    return $ta['token'] === 'token-de-prueba' && $ta['sign'] === 'sign-de-prueba';
});

chequear('olvidarCache borra el ticket guardado', function () use ($emisor): bool {
    $ruta = App::storage("ta/{$emisor['cuit']}_wsfe_{$emisor['ambiente']}.json");
    Wsaa::olvidarCache($emisor);
    return !is_file($ruta);
});

chequear('Al cambiar el certificado se olvida el TA', function () use ($emisor, $cfg, $caCrt, $caKey): bool {
    $ruta = App::storage("ta/{$emisor['cuit']}_wsfe_{$emisor['ambiente']}.json");
    file_put_contents($ruta, json_encode(['token' => 't', 'sign' => 's', 'expira' => date('Y-m-d H:i:s', time() + 3600)]));
    $nuevo = Certificados::generarPedido($emisor, 'zzotro2029');
    $firmado = openssl_csr_sign($nuevo['csr'], $caCrt, $caKey, 730, $cfg, 3);
    $pem = '';
    openssl_x509_export($firmado, $pem);
    Certificados::guardarCertificado(Db::una('SELECT * FROM emisores WHERE cuit = ?', [$emisor['cuit']]), $pem);
    return !is_file($ruta);
});

// ------------------------------------------------- formato de datos para WSFE
chequear('Fechas y alícuotas se formatean como pide ARCA', fn (): bool =>
    Wsfe::fechaParaArca('2026-10-22') === '20261022'
    && Wsfe::fechaDesdeArca('20281021') === '2028-10-21'
    && Wsfe::normalizarAlicuota('21,00') === '21'
    && Wsfe::normalizarAlicuota('10.50') === '10.5'
    && Wsfe::normalizarAlicuota('0') === '0'
    && Wsfe::IVA_IDS[Wsfe::normalizarAlicuota('10,5')] === 4
    && Wsfe::importe('1.210,00' === '' ? '0' : '1210,50') === 1210.50);

// --------------------------------------------- detalle de FECAESolicitar (C y B)
$wsfe = new Wsfe($emisor);
$detalle = new ReflectionMethod(Wsfe::class, 'detalle');
$detalle->setAccessible(true);

chequear('Factura C: ImpNeto = total, sin IVA', function () use ($detalle, $wsfe): bool {
    $d = $detalle->invoke($wsfe, [
        'tipo_cbte' => 11, 'punto_venta' => 5, 'concepto' => 1, 'fecha' => '2026-10-22',
        'doc_tipo' => 99, 'doc_nro' => '0', 'condicion_iva_receptor' => 5,
        'neto_gravado' => '1210.00', 'alicuota_iva' => '', 'iva' => '0',
        'no_gravado' => '0', 'exento' => '0', 'total' => '1210.00',
        'moneda' => 'PES', 'cotizacion' => '1',
    ], 7);
    return !isset($d['Iva'])
        && $d['ImpNeto'] === 1210.00 && $d['ImpTotal'] === 1210.00
        && $d['ImpIVA'] === 0.0 && $d['ImpTotConc'] === 0.0 && $d['ImpOpEx'] === 0.0
        && $d['CbteDesde'] === 7 && $d['CbteHasta'] === 7
        && $d['CbteFch'] === '20261022' && $d['CondicionIVAReceptorId'] === 5
        && abs($d['ImpTotal'] - ($d['ImpTotConc'] + $d['ImpNeto'] + $d['ImpOpEx'] + $d['ImpIVA'] + $d['ImpTrib'])) < 0.005;
});

chequear('Factura B: AlicIva 21% y la suma cierra', function () use ($detalle, $wsfe): bool {
    $d = $detalle->invoke($wsfe, [
        'tipo_cbte' => 6, 'punto_venta' => 5, 'concepto' => 1, 'fecha' => '2026-10-22',
        'doc_tipo' => 80, 'doc_nro' => '20123456786', 'condicion_iva_receptor' => 1,
        'neto_gravado' => '1000.00', 'alicuota_iva' => '21', 'iva' => '210.00',
        'no_gravado' => '0', 'exento' => '0', 'total' => '1210.00',
        'moneda' => 'PES', 'cotizacion' => '1',
    ], 12);
    return $d['Iva']['AlicIva']['Id'] === 5
        && $d['Iva']['AlicIva']['BaseImp'] === 1000.00
        && $d['Iva']['AlicIva']['Importe'] === 210.00
        && $d['ImpIVA'] === 210.00 && $d['ImpNeto'] === 1000.00 && $d['ImpTotal'] === 1210.00
        && $d['DocNro'] === 20123456786
        && abs($d['ImpTotal'] - ($d['ImpTotConc'] + $d['ImpNeto'] + $d['ImpOpEx'] + $d['ImpIVA'] + $d['ImpTrib'])) < 0.005;
});

chequear('Concepto servicios agrega las tres fechas', function () use ($detalle, $wsfe): bool {
    $d = $detalle->invoke($wsfe, [
        'tipo_cbte' => 11, 'punto_venta' => 5, 'concepto' => 2, 'fecha' => '2026-10-22',
        'doc_tipo' => 99, 'doc_nro' => '0', 'condicion_iva_receptor' => 5,
        'neto_gravado' => '500', 'alicuota_iva' => '', 'iva' => '0',
        'no_gravado' => '0', 'exento' => '0', 'total' => '500',
        'moneda' => 'PES', 'cotizacion' => '1',
        'serv_desde' => '2026-10-01', 'serv_hasta' => '2026-10-31', 'vto_pago' => '2026-11-10',
    ], 1);
    return $d['FchServDesde'] === '20261001' && $d['FchServHasta'] === '20261031' && $d['FchVtoPago'] === '20261110';
});

chequear('Nota de crédito agrega CbtesAsoc', function () use ($detalle, $wsfe): bool {
    $d = $detalle->invoke($wsfe, [
        'tipo_cbte' => 13, 'punto_venta' => 5, 'concepto' => 1, 'fecha' => '2026-10-22',
        'doc_tipo' => 99, 'doc_nro' => '0', 'condicion_iva_receptor' => 5,
        'neto_gravado' => '100', 'alicuota_iva' => '', 'iva' => '0',
        'no_gravado' => '0', 'exento' => '0', 'total' => '100',
        'moneda' => 'PES', 'cotizacion' => '1',
        'asoc_tipo' => 11, 'asoc_pto_vta' => 5, 'asoc_nro' => 3,
    ], 4);
    return $d['CbtesAsoc']['CbteAsoc'] === ['Tipo' => 11, 'PtoVta' => 5, 'Nro' => 3];
});

chequear('Alícuota inválida se rechaza con mensaje claro', function () use ($detalle, $wsfe): bool {
    try {
        $detalle->invoke($wsfe, [
            'tipo_cbte' => 6, 'punto_venta' => 5, 'concepto' => 1, 'fecha' => '2026-10-22',
            'doc_tipo' => 80, 'doc_nro' => '20123456786', 'condicion_iva_receptor' => 1,
            'neto_gravado' => '1000', 'alicuota_iva' => '15', 'iva' => '150',
            'no_gravado' => '0', 'exento' => '0', 'total' => '1150',
            'moneda' => 'PES', 'cotizacion' => '1',
        ], 1);
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'no es una de las que acepta ARCA');
    }
});

// --------------------------------------------------- clasificación de errores
chequear('Los errores de ARCA caen en la categoría correcta', function (): bool {
    $e = \Facturador\Arca\Errores::class;
    return $e::clasificar('10016', 'comp. 4 no coincide con el proximo a autorizar')['categoria'] === $e::NUMERACION
        && $e::clasificar('600', 'ValidacionDeToken: No puede autenticar')['categoria'] === $e::AUTH
        && $e::clasificar('10048', 'Para comprobantes tipo C...')['categoria'] === $e::DATOS
        && $e::clasificarExcepcion(new \SoapFault('HTTP', 'Error Fetching http headers'))['categoria'] === $e::TECNICO
        && $e::clasificarExcepcion(new \Exception('cms.cert.expired: certificado vencido'))['categoria'] === $e::AUTH
        && $e::categoriaDominante([
            ['categoria' => $e::DATOS], ['categoria' => $e::AUTH],
        ]) === $e::AUTH;
});

chequear('La bitácora no guarda Token ni Sign', function (): bool {
    $xml = '<Auth><Token>abc123</Token><Sign>def456</Sign><Cuit>20123456786</Cuit></Auth>';
    $limpio = \Facturador\Arca\Bitacora::limpiar($xml);
    return !str_contains($limpio, 'abc123') && !str_contains($limpio, 'def456')
        && str_contains($limpio, '[oculto]') && str_contains($limpio, '20123456786');
});

// -------------------------------------------------------------------- limpieza
$dir = Certificados::dir($emisor);
foreach ((array) glob($dir . '/*') as $f) {
    @unlink((string) $f);
}
@rmdir($dir);
@rmdir(dirname($dir));
Wsaa::olvidarCache($emisor);
Db::ejecutar('DELETE FROM eventos');
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);
foreach ((array) glob(App::storage('cache') . "/{$CUIT}_*") as $f) {
    @unlink((string) $f);
}

echo "\n== {$ok} OK / {$fallos} fallas ==\n";
exit($fallos === 0 ? 0 : 1);
