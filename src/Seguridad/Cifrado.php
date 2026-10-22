<?php
declare(strict_types=1);

namespace Facturador\Seguridad;

use Facturador\App;

/**
 * Cifrado simétrico AES-256-GCM con la app_key de config.php.
 * Se usa para la clave privada de los certificados: nunca se escribe en claro a disco.
 *
 * Formato del sobre: "FAC1." . base64(nonce[12] + tag[16] + cifrado)
 * El AAD ata el sobre a un contexto (CUIT + ambiente), así un archivo copiado
 * de otro emisor no se puede descifrar por error.
 */
final class Cifrado
{
    private const ALGORITMO = 'aes-256-gcm';
    private const VERSION = 'FAC1';
    private const LARGO_NONCE = 12;
    private const LARGO_TAG = 16;

    public static function cifrar(string $claro, string $aad = ''): string
    {
        $tag = '';
        $nonce = random_bytes(self::LARGO_NONCE);
        $cifrado = openssl_encrypt(
            $claro,
            self::ALGORITMO,
            self::clave(),
            \OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::LARGO_TAG
        );
        if ($cifrado === false) {
            throw new \RuntimeException('No se pudo cifrar: ' . self::errorOpenssl());
        }
        return self::VERSION . '.' . base64_encode($nonce . $tag . $cifrado);
    }

    public static function descifrar(string $sobre, string $aad = ''): string
    {
        [$version, $cuerpo] = array_pad(explode('.', trim($sobre), 2), 2, null);
        if ($version !== self::VERSION || !is_string($cuerpo) || $cuerpo === '') {
            throw new \RuntimeException('El archivo cifrado no tiene el formato esperado.');
        }
        $bruto = base64_decode($cuerpo, true);
        if ($bruto === false || strlen($bruto) <= self::LARGO_NONCE + self::LARGO_TAG) {
            throw new \RuntimeException('El archivo cifrado está incompleto o dañado.');
        }

        $nonce = substr($bruto, 0, self::LARGO_NONCE);
        $tag = substr($bruto, self::LARGO_NONCE, self::LARGO_TAG);
        $cifrado = substr($bruto, self::LARGO_NONCE + self::LARGO_TAG);

        $claro = openssl_decrypt($cifrado, self::ALGORITMO, self::clave(), \OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($claro === false) {
            // Pasa si cambió la app_key, si el archivo es de otro emisor o si fue alterado.
            throw new \RuntimeException(
                'No se pudo descifrar la clave privada: la app_key de config.php cambió, '
                . 'o el archivo es de otro CUIT o ambiente.'
            );
        }
        return $claro;
    }

    /** Cifra y guarda en un archivo, con permisos restrictivos. */
    public static function guardarArchivo(string $ruta, string $claro, string $aad = ''): void
    {
        if (file_put_contents($ruta, self::cifrar($claro, $aad), \LOCK_EX) === false) {
            throw new \RuntimeException("No se pudo escribir {$ruta}");
        }
        @chmod($ruta, 0600);
    }

    public static function leerArchivo(string $ruta, string $aad = ''): string
    {
        if (!is_file($ruta)) {
            throw new \RuntimeException("No se encontró el archivo cifrado {$ruta}");
        }
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new \RuntimeException("No se pudo leer {$ruta}");
        }
        return self::descifrar($contenido, $aad);
    }

    /** Contexto que se firma junto al dato cifrado. */
    public static function contexto(array $emisor): string
    {
        return 'cuit=' . ($emisor['cuit'] ?? '') . ';ambiente=' . ($emisor['ambiente'] ?? '');
    }

    private static function clave(): string
    {
        $hex = (string) App::config('app_key', '');
        $clave = strlen($hex) === 64 && ctype_xdigit($hex) ? hex2bin($hex) : false;
        if ($clave === false) {
            throw new \RuntimeException('La app_key de config.php tiene que ser de 64 caracteres hexadecimales.');
        }
        return $clave;
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
