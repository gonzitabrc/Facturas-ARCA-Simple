<?php
declare(strict_types=1);

namespace Facturador\Arca;

use Facturador\App;

/**
 * Cliente SOAP con la configuración que usan los dos servicios de ARCA:
 * timeouts cortos, trace prendido (para guardar request/response en `eventos`)
 * y cache del WSDL en storage/ (en hosting compartido el directorio por defecto
 * de PHP no siempre se puede escribir).
 */
final class Soap
{
    public static function cliente(string $url, string $ambiente, int $version = \SOAP_1_1): \SoapClient
    {
        $arca = App::configArca($ambiente);

        ini_set('default_socket_timeout', (string) (int) $arca['timeout_respuesta_seg']);
        ini_set('soap.wsdl_cache_enabled', '1');
        ini_set('soap.wsdl_cache_dir', App::storage('cache/wsdl'));
        ini_set('soap.wsdl_cache_ttl', '86400');

        $wsdl = str_contains(strtoupper($url), 'WSDL') ? $url : $url . '?WSDL';

        try {
            return new \SoapClient($wsdl, [
                'soap_version'       => $version,
                'trace'              => true,
                'exceptions'         => true,
                'connection_timeout' => (int) $arca['timeout_conexion_seg'],
                'cache_wsdl'         => \WSDL_CACHE_DISK,
                'user_agent'         => 'Facturador ARCA (PHP ' . \PHP_VERSION . ')',
                'keep_alive'         => false,
            ]);
        } catch (\Throwable $e) {
            $clasificado = Errores::clasificarExcepcion($e);
            throw new ArcaExcepcion(
                $clasificado['categoria'],
                'No se pudo conectar con ARCA (' . $wsdl . '). ' . $clasificado['mensaje'],
                null,
                $clasificado['mensaje_arca'],
                $e
            );
        }
    }

    /** Lo que ARCA devuelve como "uno o varios": siempre lista. */
    public static function lista(mixed $valor): array
    {
        if ($valor === null) {
            return [];
        }
        if (is_object($valor)) {
            return [$valor];
        }
        if (is_array($valor)) {
            return array_is_list($valor) ? $valor : [$valor];
        }
        return [];
    }
}
