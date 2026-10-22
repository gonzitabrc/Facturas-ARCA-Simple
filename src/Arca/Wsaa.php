<?php
declare(strict_types=1);

namespace Facturador\Arca;

use Facturador\App;
use Facturador\Seguridad\Certificados;

/**
 * WSAA: cambia el certificado del emisor por un ticket de acceso (TA) con Token y Sign.
 *
 * El TA dura 12 horas y se cachea en storage/ta/{cuit}_{servicio}_{ambiente}.json.
 * Se reusa mientras le falten más de 10 minutos para vencer: si se pide uno nuevo
 * teniendo uno vigente, ARCA contesta "El CEE ya posee un TA valido" y no da otro.
 */
final class Wsaa
{
    /** Margen antes del vencimiento para dejar de usar el TA cacheado. */
    private const MARGEN_SEG = 600;

    /**
     * @return array{token:string, sign:string, expira:string}
     */
    public static function ta(array $emisor, string $servicio = 'wsfe'): array
    {
        // Una instalación de demostración no habla con ARCA ni por error.
        if (App::esDemo()) {
            throw new ArcaExcepcion(Errores::AUTH, App::MENSAJE_DEMO);
        }

        $cacheado = self::leerCache($emisor, $servicio);
        if ($cacheado !== null) {
            return $cacheado;
        }

        // Dos procesos pidiendo TA a la vez = rechazo de ARCA. Se serializa con un lock.
        $lock = fopen(App::storage('locks/wsaa_' . $emisor['cuit'] . '_' . $emisor['ambiente'] . '.lock'), 'c+');
        if ($lock === false) {
            throw new ArcaExcepcion(Errores::TECNICO, 'No se pudo tomar el lock para pedir el ticket de acceso.');
        }

        try {
            if (!flock($lock, \LOCK_EX)) {
                throw new ArcaExcepcion(Errores::TECNICO, 'Otro proceso está pidiendo el ticket de acceso a ARCA. Probá de nuevo en unos segundos.');
            }
            // Puede haberlo pedido el proceso que tenía el lock.
            $cacheado = self::leerCache($emisor, $servicio);
            if ($cacheado !== null) {
                return $cacheado;
            }
            return self::pedirTa($emisor, $servicio);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /** Borra el TA cacheado (para forzar uno nuevo después de cambiar el certificado). */
    public static function olvidarCache(array $emisor, string $servicio = 'wsfe'): void
    {
        @unlink(self::rutaCache($emisor, $servicio));
    }

    // ---------------------------------------------------------------- interno

    private static function pedirTa(array $emisor, string $servicio): array
    {
        $tra = self::armarTra($servicio);
        $cms = self::firmarCms(
            $tra,
            Certificados::certificadoPem($emisor),
            Certificados::clavePrivada($emisor)
        );

        $url = (string) App::configArca((string) $emisor['ambiente'])['wsaa'];
        $inicio = microtime(true);

        try {
            $cliente = Soap::cliente($url, (string) $emisor['ambiente']);
            $respuesta = $cliente->loginCms(['in0' => $cms]);
            $xml = is_object($respuesta) ? ($respuesta->loginCmsReturn ?? '') : ($respuesta['loginCmsReturn'] ?? '');
            $duracion = (int) round((microtime(true) - $inicio) * 1000);

            if (!is_string($xml) || trim($xml) === '') {
                throw new ArcaExcepcion(Errores::TECNICO, 'ARCA devolvió una respuesta vacía al pedir el ticket de acceso.');
            }

            $ta = self::parsearTa($xml);
            self::guardarCache($emisor, $servicio, $ta);

            Bitacora::registrar(
                'WSAA_LOGIN',
                $emisor,
                true,
                'loginCms (CMS firmado, no se guarda)',
                'TA válido hasta ' . $ta['expira'],
                'servicio ' . $servicio,
                $duracion
            );
            App::log("TA de WSAA obtenido para {$emisor['cuit']} ({$emisor['ambiente']}), vence {$ta['expira']}");

            return $ta;
        } catch (ArcaExcepcion $e) {
            Bitacora::registrar('WSAA_LOGIN', $emisor, false, 'loginCms', null, $e->paraLog(), (int) round((microtime(true) - $inicio) * 1000));
            throw $e;
        } catch (\Throwable $e) {
            $duracion = (int) round((microtime(true) - $inicio) * 1000);
            $texto = $e->getMessage();

            // Caso típico: ya hay un TA vigente del lado de ARCA pero el cache local se perdió.
            if (stripos($texto, 'ya posee un TA valido') !== false || stripos($texto, 'ya posee un TA válido') !== false) {
                Bitacora::registrar('WSAA_LOGIN', $emisor, false, 'loginCms', null, 'TA vigente sin cache local', $duracion);
                throw new ArcaExcepcion(
                    Errores::TECNICO,
                    'ARCA dice que este certificado ya tiene un ticket de acceso vigente y no entrega otro. '
                    . 'Esperá unos minutos (hasta 10) y volvé a intentar.',
                    null,
                    $texto,
                    $e
                );
            }

            $clasificado = Errores::clasificarExcepcion($e);
            Bitacora::registrar('WSAA_LOGIN', $emisor, false, 'loginCms', null, $clasificado['categoria'] . ': ' . $clasificado['mensaje_arca'], $duracion);
            throw new ArcaExcepcion(
                $clasificado['categoria'],
                $clasificado['categoria'] === Errores::AUTH
                    ? 'ARCA no aceptó el certificado: ' . rtrim($clasificado['mensaje_arca'], ' .')
                      . '. Revisá que el alias esté autorizado para Facturación Electrónica y que el certificado no esté vencido.'
                    : 'No se pudo obtener el ticket de acceso de ARCA: ' . $clasificado['mensaje_arca'],
                null,
                $clasificado['mensaje_arca'],
                $e
            );
        }
    }

    /** TRA: el pedido que se firma con el certificado. */
    private static function armarTra(string $servicio): string
    {
        $ahora = time();
        $tra = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"></loginTicketRequest>'
        );
        $encabezado = $tra->addChild('header');
        // uniqueId tiene que ser creciente; generationTime y expirationTime, ±10 minutos.
        $encabezado->addChild('uniqueId', (string) $ahora);
        $encabezado->addChild('generationTime', date('c', $ahora - self::MARGEN_SEG));
        $encabezado->addChild('expirationTime', date('c', $ahora + self::MARGEN_SEG));
        $tra->addChild('service', $servicio);

        return (string) $tra->asXML();
    }

    /**
     * Firma el TRA en formato CMS (PKCS#7) y devuelve el base64 que espera loginCms.
     * openssl_pkcs7_sign solo trabaja con archivos: se usan temporales en storage/
     * y se borran siempre, incluso si algo falla.
     */
    private static function firmarCms(string $tra, string $certPem, string $clavePem): string
    {
        $dir = App::storage('tmp');
        $archivoTra = tempnam($dir, 'tra');
        $archivoCms = tempnam($dir, 'cms');
        if ($archivoTra === false || $archivoCms === false) {
            throw new ArcaExcepcion(Errores::TECNICO, 'No se pudieron crear los archivos temporales para firmar el pedido.');
        }

        try {
            file_put_contents($archivoTra, $tra);
            // flags = 0: el contenido va dentro del CMS (no detached), que es lo que espera WSAA.
            // Ojo: en PHP 8 no se puede pasar !PKCS7_DETACHED (es bool y la función pide int).
            $firmado = openssl_pkcs7_sign(
                $archivoTra,
                $archivoCms,
                $certPem,
                $clavePem,
                [],
                0
            );
            if ($firmado === false) {
                throw new ArcaExcepcion(
                    Errores::AUTH,
                    'No se pudo firmar el pedido con el certificado. Verificá que el certificado y la clave sean pareja.',
                    null,
                    self::errorOpenssl()
                );
            }

            $contenido = (string) file_get_contents($archivoCms);
            // El archivo viene con encabezados MIME: el CMS es lo que va después de la línea en blanco.
            $partes = preg_split("/\r?\n\r?\n/", $contenido, 2);
            $cuerpo = $partes[1] ?? '';
            $cms = preg_replace('/\s+/', '', $cuerpo) ?? '';
            if ($cms === '' || base64_decode($cms, true) === false) {
                throw new ArcaExcepcion(Errores::TECNICO, 'La firma del pedido salió mal (CMS vacío o ilegible).');
            }
            return $cms;
        } finally {
            // La clave en claro y el pedido firmado no quedan en disco.
            @unlink($archivoTra);
            @unlink($archivoCms);
        }
    }

    /** @return array{token:string, sign:string, expira:string} */
    private static function parsearTa(string $xml): array
    {
        $previo = libxml_use_internal_errors(true);
        try {
            $ta = simplexml_load_string($xml);
            if ($ta === false || !isset($ta->credentials->token, $ta->credentials->sign)) {
                throw new ArcaExcepcion(Errores::TECNICO, 'No se pudo leer el ticket de acceso que devolvió ARCA.');
            }
            $expira = (string) ($ta->header->expirationTime ?? '');
            return [
                'token'  => (string) $ta->credentials->token,
                'sign'   => (string) $ta->credentials->sign,
                'expira' => $expira !== '' ? date('Y-m-d H:i:s', (int) strtotime($expira)) : date('Y-m-d H:i:s', time() + 3600),
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
    }

    private static function rutaCache(array $emisor, string $servicio): string
    {
        return App::storage("ta/{$emisor['cuit']}_{$servicio}_{$emisor['ambiente']}.json");
    }

    private static function leerCache(array $emisor, string $servicio): ?array
    {
        $ruta = self::rutaCache($emisor, $servicio);
        if (!is_file($ruta)) {
            return null;
        }
        $datos = json_decode((string) @file_get_contents($ruta), true);
        if (!is_array($datos) || !isset($datos['token'], $datos['sign'], $datos['expira'])) {
            return null;
        }
        if ((int) strtotime((string) $datos['expira']) - time() <= self::MARGEN_SEG) {
            return null;   // está por vencer: mejor pedir uno nuevo
        }
        return [
            'token'  => (string) $datos['token'],
            'sign'   => (string) $datos['sign'],
            'expira' => (string) $datos['expira'],
        ];
    }

    private static function guardarCache(array $emisor, string $servicio, array $ta): void
    {
        $ruta = self::rutaCache($emisor, $servicio);
        file_put_contents($ruta, json_encode($ta + ['generado' => date('Y-m-d H:i:s')]), \LOCK_EX);
        @chmod($ruta, 0600);
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
