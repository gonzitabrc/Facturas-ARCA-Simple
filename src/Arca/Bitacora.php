<?php
declare(strict_types=1);

namespace Facturador\Arca;

use Facturador\App;
use Facturador\Db;

/**
 * Deja en la tabla `eventos` cada ida y vuelta con ARCA. Es lo primero que se mira
 * cuando algo falla. El Token y el Sign nunca se guardan.
 */
final class Bitacora
{
    public static function registrar(
        string $tipo,
        array $emisor,
        bool $ok,
        ?string $request = null,
        ?string $response = null,
        ?string $detalle = null,
        ?int $duracionMs = null,
        ?int $comprobanteId = null,
        ?int $loteId = null,
    ): void {
        try {
            Db::insertar('eventos', [
                'comprobante_id' => $comprobanteId,
                'lote_id'        => $loteId,
                'emisor_id'      => isset($emisor['id']) ? (int) $emisor['id'] : null,
                'tipo'           => $tipo,
                'request_xml'    => $request === null ? null : self::limpiar($request),
                'response_xml'   => $response === null ? null : self::limpiar($response),
                'ok'             => $ok ? 1 : 0,
                'detalle'        => $detalle,
                'duracion_ms'    => $duracionMs,
            ]);
        } catch (\Throwable $e) {
            // La bitácora no puede hacer fallar una emisión.
            App::log('No se pudo registrar el evento ' . $tipo . ': ' . $e->getMessage());
        }
    }

    /** Saca credenciales y recorta: los XML de ARCA pueden ser largos. */
    public static function limpiar(string $xml): string
    {
        $xml = preg_replace('#<(\w+:)?(Token|Sign)>.*?</(\w+:)?(Token|Sign)>#s', '<$2>[oculto]</$2>', $xml) ?? $xml;
        $xml = preg_replace('#(<\?xml.*?\?>)#s', '', $xml) ?? $xml;
        $xml = trim($xml);
        return mb_strlen($xml) > 20000 ? mb_substr($xml, 0, 20000) . "\n[... recortado]" : $xml;
    }
}
