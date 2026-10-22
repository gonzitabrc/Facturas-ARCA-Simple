<?php
declare(strict_types=1);

namespace Facturador\Seguridad;

use Facturador\App;
use Facturador\Db;

/**
 * Clave privada, pedido (CSR) y certificado (.crt) de cada emisor.
 *
 * Archivos en storage/certs/{cuit}/{ambiente}/ :
 *   clave.key.enc            clave privada EN USO, cifrada con AES-256-GCM
 *   certificado.crt          certificado EN USO
 *   pedido-{alias}.csr       pedido que generó el certificado en uso
 *   pendiente-{alias}.key.enc / pendiente-{alias}.csr   pedido nuevo, esperando el .crt de ARCA
 *   *-anterior-{fecha}.*     copia del par anterior, por si hay que volver atrás
 *
 * Mientras hay un pedido pendiente el par en uso sigue intacto: así, al renovar,
 * se puede seguir facturando con el certificado viejo hasta que el nuevo esté probado.
 */
final class Certificados
{
    /** Alias de ARCA: solo letras y números. */
    public const ALIAS_REGEX = '/^[A-Za-z0-9]{3,30}$/';

    /**
     * Genera clave privada RSA 2048 + CSR. La clave queda cifrada en disco y
     * el CSR disponible para descargar y subir a ARCA.
     *
     * @return array{alias:string, csr_path:string, csr:string}
     */
    public static function generarPedido(array $emisor, string $alias): array
    {
        $alias = trim($alias);
        if (preg_match(self::ALIAS_REGEX, $alias) !== 1) {
            throw new \RuntimeException('El alias tiene que ser de 3 a 30 letras y números, sin espacios ni símbolos. Por ejemplo: octubre2028.');
        }
        if (!App::cuitValido((string) $emisor['cuit'])) {
            throw new \RuntimeException('El CUIT del emisor no es válido: corregilo antes de generar el pedido.');
        }
        if (trim((string) $emisor['razon_social']) === '') {
            throw new \RuntimeException('Cargá el nombre o razón social antes de generar el pedido.');
        }

        $config = self::configOpenssl();

        $clave = openssl_pkey_new($config);
        if ($clave === false) {
            throw new \RuntimeException('No se pudo generar la clave privada: ' . self::errorOpenssl());
        }

        // ARCA espera este DN: país, razón social, el alias como CN y el CUIT en serialNumber.
        $dn = [
            'countryName'       => 'AR',
            'organizationName'  => (string) $emisor['razon_social'],
            'commonName'        => $alias,
            'serialNumber'      => 'CUIT ' . $emisor['cuit'],
        ];

        $pedido = openssl_csr_new($dn, $clave, $config);
        if ($pedido === false) {
            throw new \RuntimeException('No se pudo generar el pedido (CSR): ' . self::errorOpenssl());
        }

        $csrPem = '';
        $clavePem = '';
        if (!openssl_csr_export($pedido, $csrPem) || !openssl_pkey_export($clave, $clavePem, null, $config)) {
            throw new \RuntimeException('No se pudo exportar el pedido o la clave: ' . self::errorOpenssl());
        }

        $dir = self::dir($emisor);
        self::borrarPendientes($emisor);

        $rutaClave = $dir . '/pendiente-' . $alias . '.key.enc';
        $rutaCsr = $dir . '/pendiente-' . $alias . '.csr';

        Cifrado::guardarArchivo($rutaClave, $clavePem, Cifrado::contexto($emisor));
        if (file_put_contents($rutaCsr, $csrPem, \LOCK_EX) === false) {
            throw new \RuntimeException("No se pudo escribir {$rutaCsr}");
        }
        @chmod($rutaCsr, 0640);

        // La clave en claro solo existió en memoria.
        unset($clavePem, $clave);

        Db::actualizar('emisores', (int) $emisor['id'], ['csr_path' => $rutaCsr]);
        App::log("CSR generado para CUIT {$emisor['cuit']} ({$emisor['ambiente']}), alias {$alias}");

        return ['alias' => $alias, 'csr_path' => $rutaCsr, 'csr' => $csrPem];
    }

    /**
     * Guarda el .crt que devolvió ARCA, después de verificar que es pareja de la
     * clave privada y que corresponde a este CUIT.
     *
     * @return array{alias:string, vence:string, desde:string}
     */
    public static function guardarCertificado(array $emisor, string $crtPem): array
    {
        $crtPem = self::normalizarPem($crtPem);

        $x509 = @openssl_x509_read($crtPem);
        if ($x509 === false) {
            throw new \RuntimeException('El archivo no parece un certificado. Subí el .crt que descargaste de ARCA, sin abrirlo ni editarlo.');
        }

        $datos = openssl_x509_parse($x509);
        if (!is_array($datos)) {
            throw new \RuntimeException('No se pudieron leer los datos del certificado.');
        }

        // ¿De qué CUIT es? ARCA pone "CUIT 20123456789" en serialNumber.
        $serial = (string) ($datos['subject']['serialNumber'] ?? '');
        $cuitCert = preg_replace('/\D/', '', $serial) ?? '';
        if ($cuitCert !== '' && $cuitCert !== (string) $emisor['cuit']) {
            throw new \RuntimeException(
                'Este certificado es del CUIT ' . $cuitCert . ' y el emisor es ' . $emisor['cuit']
                . '. Descargá el certificado del alias correcto.'
            );
        }

        // ¿Con qué clave casa? Primero la pendiente (renovación), después la que está en uso.
        $pendiente = self::pendiente($emisor);
        $contexto = Cifrado::contexto($emisor);
        $claveUsada = null;

        if ($pendiente !== null) {
            $clavePem = Cifrado::leerArchivo($pendiente['key_path'], $contexto);
            if (openssl_x509_check_private_key($x509, $clavePem)) {
                $claveUsada = 'pendiente';
            }
        }
        if ($claveUsada === null && ($emisor['key_path'] ?? null) !== null && is_file((string) $emisor['key_path'])) {
            $clavePem = Cifrado::leerArchivo((string) $emisor['key_path'], $contexto);
            if (openssl_x509_check_private_key($x509, $clavePem)) {
                $claveUsada = 'actual';
            }
        }
        unset($clavePem);

        if ($claveUsada === null) {
            throw new \RuntimeException(
                'El certificado no es pareja de la clave privada de este emisor. '
                . 'Suele pasar cuando se descarga el .crt de otro alias: generá el pedido de nuevo o descargá el .crt del alias correcto.'
            );
        }

        $vence = date('Y-m-d', (int) ($datos['validTo_time_t'] ?? 0));
        $desde = date('Y-m-d', (int) ($datos['validFrom_time_t'] ?? 0));
        $alias = (string) ($datos['subject']['CN'] ?? 'sin-alias');

        if (($datos['validTo_time_t'] ?? 0) < time()) {
            throw new \RuntimeException('Ese certificado ya está vencido (venció el ' . date('d/m/Y', (int) $datos['validTo_time_t']) . ').');
        }

        $dir = self::dir($emisor);
        $sello = date('Ymd-His');

        // Copia del par anterior antes de reemplazarlo.
        foreach ([[$emisor['crt_path'] ?? null, "certificado-anterior-{$sello}.crt"],
                  [$emisor['key_path'] ?? null, "clave-anterior-{$sello}.key.enc"]] as [$origen, $nombre]) {
            if (is_string($origen) && is_file($origen)) {
                @copy($origen, $dir . '/' . $nombre);
            }
        }

        $rutaCrt = $dir . '/certificado.crt';
        $rutaClave = $dir . '/clave.key.enc';
        $rutaCsr = (string) ($emisor['csr_path'] ?? '');

        if ($claveUsada === 'pendiente' && $pendiente !== null) {
            // La clave pendiente pasa a ser la que está en uso.
            if (!@rename($pendiente['key_path'], $rutaClave)) {
                throw new \RuntimeException('No se pudo guardar la clave privada en su lugar definitivo.');
            }
            $rutaCsr = $dir . '/pedido-' . $pendiente['alias'] . '.csr';
            @rename($pendiente['csr_path'], $rutaCsr);
        }

        if (file_put_contents($rutaCrt, $crtPem, \LOCK_EX) === false) {
            throw new \RuntimeException("No se pudo escribir {$rutaCrt}");
        }
        @chmod($rutaCrt, 0640);

        Db::actualizar('emisores', (int) $emisor['id'], [
            'key_path'   => $rutaClave,
            'csr_path'   => $rutaCsr !== '' ? $rutaCsr : null,
            'crt_path'   => $rutaCrt,
            'crt_vence'  => $vence,
        ]);
        // El ticket de acceso cacheado se pidió con el certificado anterior.
        \Facturador\Arca\Wsaa::olvidarCache($emisor);
        App::log("Certificado guardado para CUIT {$emisor['cuit']} ({$emisor['ambiente']}), alias {$alias}, vence {$vence}");

        return ['alias' => $alias, 'vence' => $vence, 'desde' => $desde];
    }

    /**
     * Importa un par .crt + .key que ya existe (por ejemplo el que el emisor usa en
     * otro sistema). La clave se guarda cifrada igual que las que genera la app, y
     * el .key original no queda en el servidor.
     *
     * @return array{alias:string, vence:string, desde:string}
     */
    public static function importarPar(array $emisor, string $crtPem, string $clavePem, string $frase = ''): array
    {
        $crtPem = self::normalizarPem($crtPem);

        $x509 = @openssl_x509_read($crtPem);
        if ($x509 === false) {
            throw new \RuntimeException('El archivo de certificado no se pudo leer. Tiene que ser el .crt en formato PEM.');
        }

        $clave = @openssl_pkey_get_private($clavePem, $frase !== '' ? $frase : null);
        if ($clave === false) {
            throw new \RuntimeException(
                $frase === ''
                    ? 'La clave privada no se pudo leer. Si está protegida con contraseña, escribila abajo.'
                    : 'La clave privada no se pudo leer con esa contraseña.'
            );
        }

        if (!openssl_x509_check_private_key($x509, $clave)) {
            throw new \RuntimeException('El certificado y la clave privada no son pareja: revisá que sean los dos archivos del mismo alias.');
        }

        $datos = openssl_x509_parse($x509);
        if (!is_array($datos)) {
            throw new \RuntimeException('No se pudieron leer los datos del certificado.');
        }

        $cuitCert = preg_replace('/\D/', '', (string) ($datos['subject']['serialNumber'] ?? '')) ?? '';
        if ($cuitCert !== '' && $cuitCert !== (string) $emisor['cuit']) {
            throw new \RuntimeException(
                'Ese certificado es del CUIT ' . $cuitCert . ' y el emisor es ' . $emisor['cuit'] . '.'
            );
        }
        if ((int) ($datos['validTo_time_t'] ?? 0) < time()) {
            throw new \RuntimeException('Ese certificado está vencido (venció el ' . date('d/m/Y', (int) $datos['validTo_time_t']) . ').');
        }

        // Se reexporta sin contraseña: a disco va cifrado con la app_key.
        $clavePemLimpia = '';
        if (!openssl_pkey_export($clave, $clavePemLimpia, null, self::configOpenssl())) {
            throw new \RuntimeException('No se pudo preparar la clave privada: ' . self::errorOpenssl());
        }

        $dir = self::dir($emisor);
        $sello = date('Ymd-His');
        foreach ([[$emisor['crt_path'] ?? null, "certificado-anterior-{$sello}.crt"],
                  [$emisor['key_path'] ?? null, "clave-anterior-{$sello}.key.enc"]] as [$origen, $nombre]) {
            if (is_string($origen) && is_file($origen)) {
                @copy($origen, $dir . '/' . $nombre);
            }
        }

        $rutaClave = $dir . '/clave.key.enc';
        $rutaCrt = $dir . '/certificado.crt';
        Cifrado::guardarArchivo($rutaClave, $clavePemLimpia, Cifrado::contexto($emisor));
        if (file_put_contents($rutaCrt, $crtPem, \LOCK_EX) === false) {
            throw new \RuntimeException("No se pudo escribir {$rutaCrt}");
        }
        @chmod($rutaCrt, 0640);
        unset($clavePemLimpia, $clave);

        self::borrarPendientes($emisor);

        $vence = date('Y-m-d', (int) ($datos['validTo_time_t'] ?? 0));
        $alias = (string) ($datos['subject']['CN'] ?? 'sin-alias');

        Db::actualizar('emisores', (int) $emisor['id'], [
            'key_path'  => $rutaClave,
            'csr_path'  => null,     // el pedido lo generó otro sistema
            'crt_path'  => $rutaCrt,
            'crt_vence' => $vence,
        ]);
        \Facturador\Arca\Wsaa::olvidarCache($emisor);
        App::log("Certificado importado para CUIT {$emisor['cuit']} ({$emisor['ambiente']}), alias {$alias}, vence {$vence}");

        return ['alias' => $alias, 'vence' => $vence, 'desde' => date('Y-m-d', (int) ($datos['validFrom_time_t'] ?? 0))];
    }

    /**
     * Estado del certificado para la pantalla de configuración.
     *
     * @return array{tiene_par:bool, alias:?string, vence:?string, dias:?int, pendiente:?array{alias:string,csr_path:string}}
     */
    public static function estado(array $emisor): array
    {
        $estado = [
            'tiene_par'  => false,
            'alias'      => null,
            'vence'      => $emisor['crt_vence'] ?? null,
            'dias'       => null,
            'pendiente'  => self::pendiente($emisor),
        ];

        $crt = (string) ($emisor['crt_path'] ?? '');
        if ($crt !== '' && is_file($crt)) {
            $datos = @openssl_x509_parse((string) file_get_contents($crt));
            if (is_array($datos)) {
                $estado['tiene_par'] = ($emisor['key_path'] ?? null) !== null && is_file((string) $emisor['key_path']);
                $estado['alias'] = (string) ($datos['subject']['CN'] ?? '');
                $estado['vence'] = date('Y-m-d', (int) ($datos['validTo_time_t'] ?? 0));
            }
        }
        if (is_string($estado['vence']) && $estado['vence'] !== '') {
            $estado['dias'] = (int) floor((strtotime($estado['vence'] . ' 23:59:59') - time()) / 86400);
        }
        return $estado;
    }

    /** Clave privada en claro, solo para firmar el TRA de WSAA. No se escribe a disco. */
    public static function clavePrivada(array $emisor): string
    {
        $ruta = (string) ($emisor['key_path'] ?? '');
        if ($ruta === '' || !is_file($ruta)) {
            throw new \RuntimeException('Todavía no hay certificado cargado para este emisor.');
        }
        return Cifrado::leerArchivo($ruta, Cifrado::contexto($emisor));
    }

    public static function certificadoPem(array $emisor): string
    {
        $ruta = (string) ($emisor['crt_path'] ?? '');
        if ($ruta === '' || !is_file($ruta)) {
            throw new \RuntimeException('Todavía no hay certificado cargado para este emisor.');
        }
        return (string) file_get_contents($ruta);
    }

    /** Pedido pendiente de certificado, si hay uno. */
    public static function pendiente(array $emisor): ?array
    {
        $dir = self::dir($emisor);
        foreach ((array) glob($dir . '/pendiente-*.key.enc') as $key) {
            $alias = (string) preg_replace('/^pendiente-(.*)\.key\.enc$/', '$1', basename((string) $key));
            $csr = $dir . '/pendiente-' . $alias . '.csr';
            if (is_file($csr)) {
                return ['alias' => $alias, 'key_path' => (string) $key, 'csr_path' => $csr];
            }
        }
        return null;
    }

    /**
     * Borra del servidor el certificado y la clave privada de este emisor
     * (incluidas las copias de respaldo y los pedidos pendientes).
     * En ARCA el certificado sigue existiendo: esto solo lo saca de la app.
     *
     * @return int cuántos archivos se borraron
     */
    public static function quitar(array $emisor): int
    {
        $dir = self::dir($emisor);
        $borrados = 0;
        foreach ((array) glob($dir . '/*') as $archivo) {
            if (is_file((string) $archivo) && @unlink((string) $archivo)) {
                $borrados++;
            }
        }

        Db::actualizar('emisores', (int) $emisor['id'], [
            'key_path'  => null,
            'csr_path'  => null,
            'crt_path'  => null,
            'crt_vence' => null,
        ]);
        \Facturador\Arca\Wsaa::olvidarCache($emisor);
        App::log("Certificado quitado del servidor para CUIT {$emisor['cuit']} ({$emisor['ambiente']}): {$borrados} archivos");

        return $borrados;
    }

    public static function borrarPendientes(array $emisor): void
    {
        $dir = self::dir($emisor);
        foreach (array_merge((array) glob($dir . '/pendiente-*.key.enc'), (array) glob($dir . '/pendiente-*.csr')) as $archivo) {
            @unlink((string) $archivo);
        }
    }

    public static function dir(array $emisor): string
    {
        $cuit = preg_replace('/\D/', '', (string) ($emisor['cuit'] ?? '')) ?? '';
        $ambiente = ($emisor['ambiente'] ?? 'homo') === 'prod' ? 'prod' : 'homo';
        if (strlen($cuit) !== 11) {
            throw new \RuntimeException('El CUIT del emisor no es válido.');
        }
        return App::storage("certs/{$cuit}/{$ambiente}");
    }

    // ------------------------------------------------------------------ helpers

    /** Deja solo el bloque PEM del certificado, tolerando CRLF y texto alrededor. */
    private static function normalizarPem(string $contenido): string
    {
        $contenido = str_replace(["\r\n", "\r"], "\n", trim($contenido));
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $contenido, $m) !== 1) {
            throw new \RuntimeException(
                'El archivo no contiene un certificado en formato PEM. '
                . 'Subí el .crt tal como lo descargaste de ARCA.'
            );
        }
        return $m[0] . "\n";
    }

    /**
     * OpenSSL necesita un openssl.cnf. En algunos hostings (y en Windows) no está
     * configurado, así que la app escribe uno mínimo en storage/ y lo usa siempre.
     */
    private static function configOpenssl(): array
    {
        $ruta = App::storage('openssl.cnf');
        if (!is_file($ruta)) {
            $cnf = <<<CNF
            # Generado por la app para openssl_pkey_new / openssl_csr_new.
            [ req ]
            default_bits       = 2048
            default_md         = sha256
            distinguished_name = req_distinguished_name
            prompt             = no

            [ req_distinguished_name ]
            CNF;
            file_put_contents($ruta, preg_replace('/^\s+/m', '', $cnf) . "\n");
        }

        return [
            'config'            => $ruta,
            'digest_alg'        => 'sha256',
            'private_key_bits'  => 2048,
            'private_key_type'  => \OPENSSL_KEYTYPE_RSA,
            'encrypt_key'       => false,
        ];
    }

    private static function errorOpenssl(): string
    {
        $mensajes = [];
        while (($error = openssl_error_string()) !== false) {
            $mensajes[] = $error;
        }
        return $mensajes === [] ? 'error desconocido de OpenSSL' : implode(' | ', $mensajes);
    }
}
