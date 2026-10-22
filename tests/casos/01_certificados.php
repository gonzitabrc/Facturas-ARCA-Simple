<?php
// Prueba local del milestone 2: clave + CSR + cifrado + subida del .crt.
// Hace de cuenta que es ARCA firmando el CSR con una CA de juguete. No toca ARCA.
declare(strict_types=1);

require __DIR__ . '/../../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Seguridad\Certificados;
use Facturador\Seguridad\Cifrado;

App::iniciar();

$CUIT = '20123456786';        // CUIT de prueba con dígito verificador válido
$ok = 0;
$fallos = 0;

function chequear(string $titulo, callable $fn): void
{
    global $ok, $fallos;
    try {
        $resultado = $fn();
        if ($resultado === true) {
            echo "OK    {$titulo}\n";
            $ok++;
        } else {
            echo "FALLA {$titulo}: " . var_export($resultado, true) . "\n";
            $fallos++;
        }
    } catch (\Throwable $e) {
        echo "FALLA {$titulo}: " . $e->getMessage() . "\n";
        $fallos++;
    }
}

function configOpenssl(): array
{
    return [
        'config'           => App::storage('openssl.cnf'),
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'encrypt_key'      => false,
    ];
}

/** CA de juguete que hace de ARCA. */
function firmarComoArca(string $csrPem, int $dias = 730): string
{
    $cfg = configOpenssl();
    $caKey = openssl_pkey_new($cfg);
    $caCsr = openssl_csr_new(['countryName' => 'AR', 'commonName' => 'ZZTEST CA'], $caKey, $cfg);
    $caCrt = openssl_csr_sign($caCsr, null, $caKey, 3650, $cfg, 1);
    $firmado = openssl_csr_sign($csrPem, $caCrt, $caKey, $dias, $cfg, random_int(100, 99999));
    if ($firmado === false) {
        throw new RuntimeException('no se pudo firmar: ' . openssl_error_string());
    }
    $pem = '';
    openssl_x509_export($firmado, $pem);
    return $pem;
}

// ---------------------------------------------------------------- preparación
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);
$id = Db::insertar('emisores', [
    'cuit'          => $CUIT,
    'razon_social'  => 'ZZTEST Emisor SRL',
    'domicilio'     => 'Calle de Prueba 123, Bariloche',
    'condicion_iva' => 'MONO',
    'punto_venta'   => 5,
    'ambiente'      => 'homo',
]);
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
echo "Emisor de prueba id={$id} cuit={$CUIT}\n\n";

// ------------------------------------------------------------------- cifrado
chequear('Cifrado ida y vuelta', function (): bool {
    $sobre = Cifrado::cifrar('texto secreto', 'ctx');
    return str_starts_with($sobre, 'FAC1.') && Cifrado::descifrar($sobre, 'ctx') === 'texto secreto';
});

chequear('Cifrado rechaza otro contexto (AAD)', function (): bool {
    $sobre = Cifrado::cifrar('texto secreto', 'ctx-a');
    try {
        Cifrado::descifrar($sobre, 'ctx-b');
        return false;
    } catch (\Throwable) {
        return true;
    }
});

chequear('Cifrado detecta sobre alterado', function (): bool {
    $sobre = Cifrado::cifrar('texto secreto');
    $roto = substr($sobre, 0, -4) . 'AAAA';
    try {
        Cifrado::descifrar($roto);
        return false;
    } catch (\Throwable) {
        return true;
    }
});

// ------------------------------------------------------------- alias inválido
chequear('Rechaza alias con espacios', function () use ($emisor): bool {
    try {
        Certificados::generarPedido($emisor, 'octubre 2028');
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'letras y números');
    }
});

// ---------------------------------------------------------- generar el pedido
$pedido = Certificados::generarPedido($emisor, 'zztest2028');
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
$pendiente = Certificados::pendiente($emisor);

chequear('Genera CSR y clave pendiente', fn (): bool => $pendiente !== null
    && is_file($pendiente['csr_path'])
    && is_file($pendiente['key_path'])
    && str_contains($pedido['csr'], 'BEGIN CERTIFICATE REQUEST'));

chequear('La clave privada queda cifrada en disco', function () use ($pendiente): bool {
    $contenido = (string) file_get_contents($pendiente['key_path']);
    return str_starts_with($contenido, 'FAC1.') && !str_contains($contenido, 'PRIVATE KEY');
});

chequear('El CSR tiene el DN que espera ARCA', function () use ($pedido, $CUIT): bool {
    $datos = openssl_csr_get_subject($pedido['csr']);
    return $datos['C'] === 'AR'
        && $datos['O'] === 'ZZTEST Emisor SRL'
        && $datos['CN'] === 'zztest2028'
        && $datos['serialNumber'] === 'CUIT ' . $CUIT;
});

chequear('La clave del CSR es RSA 2048', function () use ($pedido): bool {
    $pub = openssl_pkey_get_details(openssl_csr_get_public_key($pedido['csr']));
    return $pub['bits'] === 2048 && $pub['type'] === OPENSSL_KEYTYPE_RSA;
});

// --------------------------------------------- subir el .crt "firmado por ARCA"
$crtPem = firmarComoArca($pedido['csr']);
$resultado = Certificados::guardarCertificado($emisor, $crtPem);
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);

chequear('Acepta el .crt y lo deja en uso', fn (): bool => $resultado['alias'] === 'zztest2028'
    && is_file((string) $emisor['crt_path'])
    && is_file((string) $emisor['key_path'])
    && str_ends_with((string) $emisor['key_path'], 'clave.key.enc')
    && $emisor['crt_vence'] === $resultado['vence']);

chequear('No queda pedido pendiente', fn (): bool => Certificados::pendiente($emisor) === null);

chequear('La clave se descifra para firmar', function () use ($emisor): bool {
    $pem = Certificados::clavePrivada($emisor);
    return str_contains($pem, 'PRIVATE KEY') && openssl_pkey_get_private($pem) !== false;
});

chequear('Certificado y clave son pareja', function () use ($emisor): bool {
    return openssl_x509_check_private_key(
        Certificados::certificadoPem($emisor),
        Certificados::clavePrivada($emisor)
    );
});

chequear('El estado muestra alias y vencimiento', function () use ($emisor): bool {
    $estado = Certificados::estado($emisor);
    return $estado['tiene_par'] === true && $estado['alias'] === 'zztest2028' && $estado['dias'] > 700;
});

// ----------------------------------------------------------- casos que fallan
chequear('Rechaza un .crt de otra clave', function () use ($emisor): bool {
    $cfg = configOpenssl();
    $otraClave = openssl_pkey_new($cfg);
    $otroCsr = openssl_csr_new([
        'countryName' => 'AR', 'organizationName' => 'ZZTEST Emisor SRL',
        'commonName' => 'zzajeno2028', 'serialNumber' => 'CUIT ' . $emisor['cuit'],
    ], $otraClave, $cfg);
    $pem = '';
    openssl_csr_export($otroCsr, $pem);
    try {
        Certificados::guardarCertificado($emisor, firmarComoArca($pem));
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'no es pareja de la clave privada');
    }
});

chequear('Rechaza un .crt de otro CUIT', function () use ($emisor): bool {
    $cfg = configOpenssl();
    $clave = openssl_pkey_new($cfg);
    $csr = openssl_csr_new([
        'countryName' => 'AR', 'organizationName' => 'Otra Empresa',
        'commonName' => 'zzotro2028', 'serialNumber' => 'CUIT 27999999994',
    ], $clave, $cfg);
    $pem = '';
    openssl_csr_export($csr, $pem);
    try {
        Certificados::guardarCertificado($emisor, firmarComoArca($pem));
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'es del CUIT 27999999994');
    }
});

chequear('Rechaza un archivo que no es certificado', function () use ($emisor): bool {
    try {
        Certificados::guardarCertificado($emisor, 'esto no es un certificado');
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'formato PEM');
    }
});

chequear('Rechaza un .crt vencido', function () use ($emisor, $id): bool {
    // Se genera un pedido nuevo y se firma con vigencia negativa.
    $nuevo = Certificados::generarPedido(Db::una('SELECT * FROM emisores WHERE id = ?', [$id]), 'zzvencido2028');
    $cfg = configOpenssl();
    $caKey = openssl_pkey_new($cfg);
    $caCsr = openssl_csr_new(['countryName' => 'AR', 'commonName' => 'ZZTEST CA'], $caKey, $cfg);
    $caCrt = openssl_csr_sign($caCsr, null, $caKey, 3650, $cfg, 1);
    // Días = 0: el certificado vence en el mismo instante en que se firma.
    // (Con días negativos, PHP 8.3 rechaza la llamada.) Se espera un segundo
    // para que la fecha de vencimiento quede efectivamente en el pasado.
    $firmado = openssl_csr_sign($nuevo['csr'], $caCrt, $caKey, 0, $cfg, 7);
    $pem = '';
    openssl_x509_export($firmado, $pem);
    sleep(1);
    try {
        Certificados::guardarCertificado(Db::una('SELECT * FROM emisores WHERE id = ?', [$id]), $pem);
        return false;
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), 'vencido');
    }
});

// ------------------------------------- renovación: el par en uso no se toca
chequear('Al renovar, el certificado en uso sigue intacto', function () use ($id): bool {
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
    $crtAntes = (string) file_get_contents((string) $emisor['crt_path']);
    Certificados::generarPedido($emisor, 'zzrenueva2030');
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
    $pend = Certificados::pendiente($emisor);
    return $pend !== null
        && $pend['alias'] === 'zzrenueva2030'
        && (string) file_get_contents((string) $emisor['crt_path']) === $crtAntes
        && openssl_x509_check_private_key(
            Certificados::certificadoPem($emisor),
            Certificados::clavePrivada($emisor)
        );
});

chequear('Al subir el .crt nuevo, reemplaza al viejo y guarda copia', function () use ($id): bool {
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
    $pend = Certificados::pendiente($emisor);
    $csr = (string) file_get_contents($pend['csr_path']);
    $res = Certificados::guardarCertificado($emisor, firmarComoArca($csr));
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
    $dir = Certificados::dir($emisor);
    return $res['alias'] === 'zzrenueva2030'
        && Certificados::pendiente($emisor) === null
        && glob($dir . '/certificado-anterior-*.crt') !== []
        && glob($dir . '/clave-anterior-*.key.enc') !== []
        && openssl_x509_check_private_key(
            Certificados::certificadoPem($emisor),
            Certificados::clavePrivada($emisor)
        );
});

// ------------------------------------------------------------------- limpieza
$dir = Certificados::dir(Db::una('SELECT * FROM emisores WHERE id = ?', [$id]));
foreach ((array) glob($dir . '/*') as $archivo) {
    @unlink((string) $archivo);
}
@rmdir($dir);
@rmdir(dirname($dir));
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);

echo "\nLimpieza: emisor de prueba borrado, certificados de prueba borrados (" . (is_dir($dir) ? 'QUEDÓ LA CARPETA' : 'carpeta eliminada') . ")\n";
echo "\n== {$ok} OK / {$fallos} fallas ==\n";
exit($fallos === 0 ? 0 : 1);
