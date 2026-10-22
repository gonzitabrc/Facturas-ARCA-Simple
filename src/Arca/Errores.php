<?php
declare(strict_types=1);

namespace Facturador\Arca;

use Facturador\App;

/**
 * Traduce los errores de ARCA a una categoría y a una frase que diga qué corregir.
 *
 * Categorías:
 *   DATOS      → la fila está mal: se rechaza esa factura y el lote sigue.
 *   NUMERACION → el número no es el que esperaba ARCA: se reconsulta y se reintenta una vez.
 *   AUTH       → certificado o permisos: se pausa el lote con una instrucción concreta.
 *   TECNICO    → red, timeout, 5xx, respuesta ilegible: INCIERTO y lote pausado.
 *
 * Todo código que no esté en el mapa se muestra con el código y el texto original de ARCA,
 * y se loguea para agregarlo después.
 */
final class Errores
{
    public const DATOS = 'DATOS';
    public const NUMERACION = 'NUMERACION';
    public const AUTH = 'AUTH';
    public const TECNICO = 'TECNICO';

    /** @var array<string, array{0:string, 1:string}> código => [categoría, mensaje claro] */
    private const MAPA = [
        // --- numeración
        '10016' => [self::NUMERACION, 'El número de comprobante no es el que sigue en ARCA para este punto de venta.'],

        // --- autenticación y permisos
        '600' => [self::AUTH, 'ARCA no aceptó la autenticación. Revisá que el certificado esté vigente y autorizado para Facturación Electrónica.'],
        '601' => [self::AUTH, 'ARCA no reconoce al CUIT para este certificado. Falta autorizar el alias en Administrador de Relaciones.'],
        '602' => [self::AUTH, 'El certificado no tiene permiso para el servicio de Facturación Electrónica. Hay que autorizar el alias en Administrador de Relaciones.'],

        // --- datos de la fila
        '10013' => [self::DATOS, 'En factura A el receptor tiene que estar identificado con CUIT.'],
        '10015' => [self::DATOS, 'Por el importe, este comprobante necesita que el receptor esté identificado con CUIT o DNI.'],
        '10018' => [self::DATOS, 'Falta la alícuota de IVA: en factura A y B hay que informarla.'],
        '10019' => [self::DATOS, 'El tipo de comprobante no coincide con los importes informados.'],
        '10020' => [self::DATOS, 'Para concepto Servicios faltan las fechas del período (desde, hasta) o el vencimiento de pago.'],
        '10021' => [self::DATOS, 'El importe de IVA informado no coincide con la base imponible y la alícuota.'],
        '10026' => [self::DATOS, 'La fecha del comprobante está fuera de los días que acepta ARCA.'],
        '10048' => [self::DATOS, 'En factura C el importe total tiene que ser igual al neto más los tributos: no se informa IVA.'],
        '10070' => [self::DATOS, 'El documento del receptor no es válido para este tipo de comprobante.'],
        '10071' => [self::DATOS, 'El documento del receptor no puede ser el mismo que el del emisor.'],
        '10242' => [self::DATOS, 'La condición frente al IVA del receptor no es válida.'],
        '10243' => [self::DATOS, 'La condición frente al IVA del receptor no corresponde para este receptor.'],
        '10246' => [self::DATOS, 'La condición frente al IVA del receptor no es válida para este tipo de comprobante.'],
        '10270' => [self::DATOS, 'El tipo de comprobante, el documento y la condición de IVA del receptor no son coherentes entre sí.'],
    ];

    /**
     * Clasifica un error devuelto por el web service (Errors u Observaciones).
     *
     * @return array{categoria:string, codigo:string, mensaje:string, mensaje_arca:string}
     */
    public static function clasificar(int|string $codigo, string $mensajeArca): array
    {
        $codigo = trim((string) $codigo);
        $mensajeArca = trim($mensajeArca);

        if (isset(self::MAPA[$codigo])) {
            [$categoria, $mensaje] = self::MAPA[$codigo];
            return [
                'categoria'    => $categoria,
                'codigo'       => $codigo,
                'mensaje'      => $mensaje,
                'mensaje_arca' => $mensajeArca,
            ];
        }

        // Sin mapear: se muestra tal cual y se loguea para agregarlo.
        App::log("Código de ARCA sin mapear: {$codigo} — {$mensajeArca}", 'arca-sin-mapear');
        return [
            'categoria'    => self::categoriaPorTexto($mensajeArca),
            'codigo'       => $codigo,
            'mensaje'      => 'ARCA rechazó el comprobante (' . $codigo . '): ' . $mensajeArca,
            'mensaje_arca' => $mensajeArca,
        ];
    }

    /**
     * Clasifica una excepción de transporte (SoapFault, red, respuesta ilegible).
     * Casi todo acá es TECNICO; los problemas de certificado son AUTH.
     */
    public static function clasificarExcepcion(\Throwable $e): array
    {
        $texto = $e->getMessage();
        $categoria = self::categoriaPorTexto($texto);

        $mensaje = match ($categoria) {
            self::AUTH => 'ARCA no aceptó el certificado: ' . self::limpiar($texto),
            default    => 'ARCA no respondió. Puede ser una caída del servicio o un problema de red; se reintenta sin duplicar nada.',
        };

        return [
            'categoria'    => $categoria,
            'codigo'       => null,
            'mensaje'      => $mensaje,
            'mensaje_arca' => self::limpiar($texto),
        ];
    }

    /** Arma una sola frase con todos los errores u observaciones que devolvió ARCA. */
    public static function resumir(array $items): string
    {
        $frases = [];
        foreach ($items as $item) {
            $frases[] = $item['mensaje'] ?? '';
        }
        $frases = array_filter(array_unique($frases));
        return implode(' ', $frases);
    }

    /** La categoría más grave de un conjunto de errores manda. */
    public static function categoriaDominante(array $items): string
    {
        $orden = [self::AUTH, self::NUMERACION, self::TECNICO, self::DATOS];
        foreach ($orden as $categoria) {
            foreach ($items as $item) {
                if (($item['categoria'] ?? '') === $categoria) {
                    return $categoria;
                }
            }
        }
        return self::DATOS;
    }

    /** Deduce la categoría por el texto cuando no hay código conocido. */
    private static function categoriaPorTexto(string $texto): string
    {
        $t = mb_strtolower($texto);

        $sonAuth = [
            'cms.sign.invalid', 'cms.cert.untrusted', 'cms.cert.expired', 'cms.cert.notfound',
            'certificado', 'certificate', 'no autorizado', 'not authorized', 'sin permisos',
            'computador no autorizado', 'no está autorizado', 'no esta autorizado',
            'validaciondetoken', 'token', 'cuit no relacionado', 'wsaa',
        ];
        foreach ($sonAuth as $aguja) {
            if (str_contains($t, $aguja)) {
                return self::AUTH;
            }
        }

        $sonTecnicos = [
            'could not connect', 'connection', 'timed out', 'timeout', 'socket',
            'error fetching http headers', 'looks like we got no xml', 'http error',
            'service unavailable', '500 internal', '502 ', '503 ', '504 ', 'dns',
        ];
        foreach ($sonTecnicos as $aguja) {
            if (str_contains($t, $aguja)) {
                return self::TECNICO;
            }
        }

        return self::TECNICO;
    }

    private static function limpiar(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
        return mb_substr($texto, 0, 300);
    }
}
