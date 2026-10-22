<?php
declare(strict_types=1);

namespace Facturador\Arca;

use Facturador\App;

/**
 * WSFEv1: los métodos que usa la app, uno por operación.
 * Siempre un comprobante por llamada (CbteDesde = CbteHasta).
 *
 * Verificado contra el Manual del Desarrollador WSFEv1 v4.8 (revisión 1/12/2026).
 *
 * No es final a propósito: las pruebas la extienden para simular respuestas de ARCA
 * sin llamar al web service.
 */
class Wsfe
{
    /** Comprobantes clase C: no llevan IVA discriminado. */
    public const TIPOS_C = [11, 12, 13];

    /** Alícuota → Id de AlicIva (FEParamGetTiposIva). */
    public const IVA_IDS = ['0' => 3, '10.5' => 4, '21' => 5, '27' => 6, '5' => 8, '2.5' => 9];

    private ?\SoapClient $cliente = null;

    public function __construct(private array $emisor)
    {
    }

    // ------------------------------------------------------------------ estado

    /**
     * FEDummy: ¿está en pie el servicio? No usa credenciales.
     *
     * @return array{AppServer:string, DbServer:string, AuthServer:string, ok:bool}
     */
    public function dummy(): array
    {
        $respuesta = $this->llamar('FEDummy', [], 'FEDUMMY', false);
        $resultado = $respuesta->FEDummyResult ?? null;

        $estado = [
            'AppServer'  => (string) ($resultado->AppServer ?? ''),
            'DbServer'   => (string) ($resultado->DbServer ?? ''),
            'AuthServer' => (string) ($resultado->AuthServer ?? ''),
        ];
        $estado['ok'] = $estado['AppServer'] === 'OK' && $estado['DbServer'] === 'OK' && $estado['AuthServer'] === 'OK';

        return $estado;
    }

    /** Último número autorizado por ARCA para este punto de venta y tipo. */
    public function ultimoAutorizado(int $tipoCbte, ?int $puntoVenta = null): int
    {
        $puntoVenta ??= (int) $this->emisor['punto_venta'];

        $respuesta = $this->llamar('FECompUltimoAutorizado', [
            'PtoVta'    => $puntoVenta,
            'CbteTipo'  => $tipoCbte,
        ], 'ULTIMO_AUTORIZADO');

        $resultado = $respuesta->FECompUltimoAutorizadoResult ?? null;
        $this->revisarErrores($resultado);

        return (int) ($resultado->CbteNro ?? 0);
    }

    /**
     * Tabla de condiciones de IVA del receptor. Se cachea 24 h: la usa el validador
     * en cada fila y no cambia de un día para otro.
     */
    public function condicionesIvaReceptor(): array
    {
        return $this->cacheado('condicion_iva_receptor', 86400, function (): array {
            $respuesta = $this->llamar('FEParamGetCondicionIvaReceptor', [], 'PARAM_COND_IVA');
            $resultado = $respuesta->FEParamGetCondicionIvaReceptorResult ?? null;
            $this->revisarErrores($resultado);

            $lista = [];
            foreach (Soap::lista($resultado->ResultGet->CondicionIvaReceptor ?? null) as $item) {
                $lista[] = [
                    'id'    => (int) ($item->Id ?? 0),
                    'desc'  => (string) ($item->Desc ?? ''),
                    'clase' => (string) ($item->Cmp_Clase ?? ''),
                ];
            }
            return $lista;
        });
    }

    /**
     * Puntos de venta habilitados en ARCA para este CUIT.
     * Sirve para avisar si el punto de venta configurado no existe o está dado de baja.
     *
     * @return list<array{nro:int, tipo:string, bloqueado:bool, baja:?string}>
     */
    public function puntosVenta(): array
    {
        $respuesta = $this->llamar('FEParamGetPtosVenta', [], 'PARAM_PTOS_VENTA');
        $resultado = $respuesta->FEParamGetPtosVentaResult ?? null;
        $this->revisarErrores($resultado);

        $lista = [];
        foreach (Soap::lista($resultado->ResultGet->PtoVenta ?? null) as $item) {
            $lista[] = [
                'nro'       => (int) ($item->Nro ?? 0),
                'tipo'      => (string) ($item->EmisionTipo ?? ''),
                'bloqueado' => strtoupper((string) ($item->Bloqueado ?? 'N')) === 'S',
                'baja'      => isset($item->FchBaja) && (string) $item->FchBaja !== '' ? (string) $item->FchBaja : null,
            ];
        }
        return $lista;
    }

    /** Alícuotas de IVA vigentes según ARCA (para validar en vez de confiar en la tabla fija). */
    public function tiposIva(): array
    {
        return $this->cacheado('tipos_iva', 86400, function (): array {
            $respuesta = $this->llamar('FEParamGetTiposIva', [], 'PARAM_TIPOS_IVA');
            $resultado = $respuesta->FEParamGetTiposIvaResult ?? null;
            $this->revisarErrores($resultado);

            $lista = [];
            foreach (Soap::lista($resultado->ResultGet->IvaTipo ?? null) as $item) {
                $lista[] = [
                    'id'   => (int) ($item->Id ?? 0),
                    'desc' => (string) ($item->Desc ?? ''),
                ];
            }
            return $lista;
        });
    }

    // ----------------------------------------------------------- autorización

    /**
     * FECAESolicitar para UN comprobante.
     *
     * @param array $c   fila de `comprobantes` (o un array con esas mismas claves)
     * @param int   $nro número que se va a usar (el que se grabó como nro_esperado)
     *
     * @return array{resultado:string, cae:?string, vto_cae:?string, nro:int,
     *               observaciones:array, errores:array, categoria:?string,
     *               mensaje:?string, reproceso:?string}
     */
    public function solicitarCae(array $c, int $nro, ?int $comprobanteId = null, ?int $loteId = null): array
    {
        $tipo = (int) $c['tipo_cbte'];
        $pv = (int) ($c['punto_venta'] ?? $this->emisor['punto_venta']);

        $parametros = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg'  => 1,
                    'PtoVta'   => $pv,
                    'CbteTipo' => $tipo,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => $this->detalle($c, $nro),
                ],
            ],
        ];

        $respuesta = $this->llamar('FECAESolicitar', $parametros, 'SOLICITAR', true, $comprobanteId, $loteId);
        $resultado = $respuesta->FECAESolicitarResult ?? null;

        // Errores de esquema o de autenticación: no hay detalle que mirar.
        $errores = $this->clasificarErrores($resultado);
        $cabecera = $resultado->FeCabResp ?? null;
        $detalle = $resultado->FeDetResp->FECAEDetResponse ?? null;
        $detalle = is_array($detalle) ? ($detalle[0] ?? null) : $detalle;

        $observaciones = [];
        foreach (Soap::lista($detalle->Observaciones->Obs ?? null) as $obs) {
            $observaciones[] = Errores::clasificar((string) ($obs->Code ?? ''), (string) ($obs->Msg ?? ''));
        }

        $resultadoCbte = (string) ($detalle->Resultado ?? ($cabecera->Resultado ?? 'R'));
        $cae = (string) ($detalle->CAE ?? '');
        $vto = (string) ($detalle->CAEFchVto ?? '');

        $problemas = array_merge($errores, $resultadoCbte === 'A' ? [] : $observaciones);

        return [
            'resultado'     => $resultadoCbte,
            'cae'           => $cae !== '' ? $cae : null,
            'vto_cae'       => $vto !== '' ? self::fechaDesdeArca($vto) : null,
            'nro'           => (int) ($detalle->CbteDesde ?? $nro),
            'observaciones' => $observaciones,
            'errores'       => $errores,
            'categoria'     => $problemas === [] ? null : Errores::categoriaDominante($problemas),
            'mensaje'       => $problemas === [] ? null : Errores::resumir($problemas),
            'reproceso'     => isset($cabecera->Reproceso) ? (string) $cabecera->Reproceso : null,
        ];
    }

    /**
     * FECompConsultar: ¿existe este comprobante en ARCA? Es el corazón de la
     * reconciliación: después de un error técnico se pregunta en vez de reintentar.
     *
     * @return array{nro:int, cae:?string, vto_cae:?string, fecha:?string, doc_nro:?string,
     *               doc_tipo:?int, total:?string, resultado:?string}|null  null si no existe
     */
    public function consultarComprobante(int $tipoCbte, int $nro, ?int $puntoVenta = null): ?array
    {
        $puntoVenta ??= (int) $this->emisor['punto_venta'];

        $respuesta = $this->llamar('FECompConsultar', [
            'FeCompConsReq' => [
                'CbteModo' => 'CAE',
                'CbteNro'  => $nro,
                'CbteTipo' => $tipoCbte,
                'PtoVta'   => $puntoVenta,
            ],
        ], 'CONSULTAR');

        $resultado = $respuesta->FECompConsultarResult ?? null;

        // Si no existe, ARCA contesta con un error de "no existen datos": eso no es una falla.
        foreach (Soap::lista($resultado->Errors->Err ?? null) as $error) {
            $texto = mb_strtolower((string) ($error->Msg ?? ''));
            if (str_contains($texto, 'no existen datos') || str_contains($texto, 'no se encuentra')) {
                return null;
            }
        }
        $this->revisarErrores($resultado);

        $datos = $resultado->ResultGet ?? null;
        if ($datos === null || (int) ($datos->CbteDesde ?? 0) === 0) {
            return null;
        }

        return [
            'nro'       => (int) $datos->CbteDesde,
            'cae'       => isset($datos->CodAutorizacion) ? (string) $datos->CodAutorizacion : null,
            'vto_cae'   => isset($datos->FchVto) ? self::fechaDesdeArca((string) $datos->FchVto) : null,
            'fecha'     => isset($datos->CbteFch) ? self::fechaDesdeArca((string) $datos->CbteFch) : null,
            'doc_nro'   => isset($datos->DocNro) ? (string) $datos->DocNro : null,
            'doc_tipo'  => isset($datos->DocTipo) ? (int) $datos->DocTipo : null,
            'total'     => isset($datos->ImpTotal) ? number_format((float) $datos->ImpTotal, 2, '.', '') : null,
            'resultado' => isset($datos->Resultado) ? (string) $datos->Resultado : null,
        ];
    }

    // ------------------------------------------------------------- armado del detalle

    /** FECAEDetRequest, según el manual v4.8. */
    private function detalle(array $c, int $nro): array
    {
        $tipo = (int) $c['tipo_cbte'];
        $esC = in_array($tipo, self::TIPOS_C, true);
        $concepto = (int) $c['concepto'];

        $detalle = [
            'Concepto'   => $concepto,
            'DocTipo'    => (int) $c['doc_tipo'],
            'DocNro'     => (int) preg_replace('/\D/', '', (string) $c['doc_nro']),
            'CbteDesde'  => $nro,
            'CbteHasta'  => $nro,
            'CbteFch'    => self::fechaParaArca((string) $c['fecha']),
            'ImpTotal'   => self::importe($c['total']),
            'ImpTotConc' => $esC ? 0.0 : self::importe($c['no_gravado'] ?? '0'),
            'ImpNeto'    => self::importe($c['neto_gravado']),
            'ImpOpEx'    => $esC ? 0.0 : self::importe($c['exento'] ?? '0'),
            'ImpIVA'     => $esC ? 0.0 : self::importe($c['iva'] ?? '0'),
            'ImpTrib'    => 0.0,
            'MonId'      => (string) ($c['moneda'] ?? 'PES'),
            'MonCotiz'   => (float) ($c['cotizacion'] ?? 1),
        ];

        // Obligatorio desde el 1/12/2026 (RG 5616); si el valor no es válido, ARCA rechaza.
        if (($c['condicion_iva_receptor'] ?? null) !== null && (int) $c['condicion_iva_receptor'] > 0) {
            $detalle['CondicionIVAReceptorId'] = (int) $c['condicion_iva_receptor'];
        }

        // Servicios (2) o productos y servicios (3): fechas obligatorias.
        if ($concepto === 2 || $concepto === 3) {
            $detalle['FchServDesde'] = self::fechaParaArca((string) $c['serv_desde']);
            $detalle['FchServHasta'] = self::fechaParaArca((string) $c['serv_hasta']);
            $detalle['FchVtoPago']   = self::fechaParaArca((string) $c['vto_pago']);
        }

        // Notas de crédito y débito: comprobante asociado.
        if (($c['asoc_tipo'] ?? null) !== null && (int) $c['asoc_tipo'] > 0) {
            $asociado = [
                'Tipo'   => (int) $c['asoc_tipo'],
                'PtoVta' => (int) ($c['asoc_pto_vta'] ?? $this->emisor['punto_venta']),
                'Nro'    => (int) $c['asoc_nro'],
            ];
            if (($c['asoc_cuit'] ?? null) !== null && $c['asoc_cuit'] !== '') {
                $asociado['Cuit'] = (string) $c['asoc_cuit'];
            }
            if (($c['asoc_fecha'] ?? null) !== null && $c['asoc_fecha'] !== '') {
                $asociado['CbteFch'] = self::fechaParaArca((string) $c['asoc_fecha']);
            }
            $detalle['CbtesAsoc'] = ['CbteAsoc' => $asociado];
        }

        // Una alícuota por factura. En clase C no se manda Iva.
        if (!$esC) {
            $alicuota = self::normalizarAlicuota((string) ($c['alicuota_iva'] ?? ''));
            $id = self::IVA_IDS[$alicuota] ?? null;
            if ($id === null) {
                throw new ArcaExcepcion(
                    Errores::DATOS,
                    'La alícuota de IVA "' . $c['alicuota_iva'] . '" no es una de las que acepta ARCA (21, 10.5, 27, 5, 2.5 o 0).'
                );
            }
            $detalle['Iva'] = [
                'AlicIva' => [
                    'Id'      => $id,
                    'BaseImp' => self::importe($c['neto_gravado']),
                    'Importe' => self::importe($c['iva'] ?? '0'),
                ],
            ];
        }

        return $detalle;
    }

    // ------------------------------------------------------------------ interno

    /** Hace la llamada SOAP, registra el evento y traduce cualquier falla. */
    private function llamar(
        string $metodo,
        array $parametros,
        string $tipoEvento,
        bool $conAuth = true,
        ?int $comprobanteId = null,
        ?int $loteId = null,
    ): object {
        if ($conAuth) {
            $ta = Wsaa::ta($this->emisor);
            $parametros = ['Auth' => [
                'Token' => $ta['token'],
                'Sign'  => $ta['sign'],
                'Cuit'  => (int) $this->emisor['cuit'],
            ]] + $parametros;
        }

        $cliente = $this->cliente();
        $inicio = microtime(true);

        try {
            $this->simularFalla($metodo);
            $respuesta = $cliente->__soapCall($metodo, [$parametros]);
            $duracion = (int) round((microtime(true) - $inicio) * 1000);

            if (!is_object($respuesta)) {
                throw new ArcaExcepcion(Errores::TECNICO, 'ARCA devolvió una respuesta que no se pudo interpretar.');
            }

            Bitacora::registrar(
                $tipoEvento,
                $this->emisor,
                true,
                (string) $cliente->__getLastRequest(),
                (string) $cliente->__getLastResponse(),
                $metodo,
                $duracion,
                $comprobanteId,
                $loteId
            );

            return $respuesta;
        } catch (ArcaExcepcion $e) {
            Bitacora::registrar(
                $tipoEvento,
                $this->emisor,
                false,
                (string) $cliente->__getLastRequest(),
                (string) $cliente->__getLastResponse(),
                $e->paraLog(),
                (int) round((microtime(true) - $inicio) * 1000),
                $comprobanteId,
                $loteId
            );
            throw $e;
        } catch (\Throwable $e) {
            $duracion = (int) round((microtime(true) - $inicio) * 1000);
            $clasificado = Errores::clasificarExcepcion($e);

            Bitacora::registrar(
                $tipoEvento,
                $this->emisor,
                false,
                (string) $cliente->__getLastRequest(),
                (string) $cliente->__getLastResponse(),
                $clasificado['categoria'] . ' en ' . $metodo . ': ' . $clasificado['mensaje_arca'],
                $duracion,
                $comprobanteId,
                $loteId
            );

            throw new ArcaExcepcion(
                $clasificado['categoria'],
                $clasificado['mensaje'],
                $clasificado['codigo'],
                $clasificado['mensaje_arca'],
                $e
            );
        }
    }

    private function cliente(): \SoapClient
    {
        if ($this->cliente === null) {
            $url = (string) App::configArca((string) $this->emisor['ambiente'])['wsfe'];
            $this->cliente = Soap::cliente($url, (string) $this->emisor['ambiente'], \SOAP_1_2);
        }
        return $this->cliente;
    }

    /** @return array<int, array> errores de ARCA ya clasificados */
    private function clasificarErrores(?object $resultado): array
    {
        $errores = [];
        foreach (Soap::lista($resultado->Errors->Err ?? null) as $error) {
            $errores[] = Errores::clasificar((string) ($error->Code ?? ''), (string) ($error->Msg ?? ''));
        }
        return $errores;
    }

    /** Para los métodos donde un error es siempre una falla (no hay detalle por comprobante). */
    private function revisarErrores(?object $resultado): void
    {
        $errores = $this->clasificarErrores($resultado);
        if ($errores === []) {
            return;
        }
        throw new ArcaExcepcion(
            Errores::categoriaDominante($errores),
            Errores::resumir($errores),
            (string) ($errores[0]['codigo'] ?? ''),
            $errores[0]['mensaje_arca'] ?? null
        );
    }

    /**
     * Cache en storage/cache/ de las tablas de parámetros.
     * Si ARCA no responde y hay un cache viejo, se usa el viejo antes de frenar todo.
     */
    private function cacheado(string $nombre, int $segundos, callable $fn): array
    {
        $ruta = App::storage("cache/{$this->emisor['cuit']}_{$this->emisor['ambiente']}_{$nombre}.json");
        $guardado = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;

        if (is_array($guardado) && (int) ($guardado['hasta'] ?? 0) > time() && is_array($guardado['datos'] ?? null)) {
            return $guardado['datos'];
        }

        try {
            $datos = $fn();
            file_put_contents($ruta, json_encode(['hasta' => time() + $segundos, 'datos' => $datos]), \LOCK_EX);
            return $datos;
        } catch (\Throwable $e) {
            if (is_array($guardado) && is_array($guardado['datos'] ?? null)) {
                App::log("ARCA no respondió para {$nombre}, se usa el cache vencido: " . $e->getMessage());
                return $guardado['datos'];
            }
            throw $e;
        }
    }

    /** Solo para pruebas: fuerza un error técnico cada N envíos (config simular_timeout_cada_n). */
    private function simularFalla(string $metodo): void
    {
        $cada = (int) App::config('simular_timeout_cada_n', 0);
        if ($cada <= 0 || $metodo !== 'FECAESolicitar') {
            return;
        }
        $ruta = App::storage('cache/simulador.txt');
        $cuenta = ((int) @file_get_contents($ruta)) + 1;
        file_put_contents($ruta, (string) $cuenta, \LOCK_EX);

        if ($cuenta % $cada === 0) {
            throw new ArcaExcepcion(
                Errores::TECNICO,
                'Timeout simulado (simular_timeout_cada_n). Sirve para probar el flujo INCIERTO.',
                null,
                'timeout simulado'
            );
        }
    }

    // ------------------------------------------------------------------ formato

    public static function fechaParaArca(string $fecha): string
    {
        $t = strtotime($fecha);
        if ($t === false) {
            throw new ArcaExcepcion(Errores::DATOS, 'La fecha "' . $fecha . '" no se entiende.');
        }
        return date('Ymd', $t);
    }

    public static function fechaDesdeArca(string $fecha): string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($fecha), $m) === 1
            ? "{$m[1]}-{$m[2]}-{$m[3]}"
            : trim($fecha);
    }

    /** Los importes van como double en el SOAP, redondeados a 2 decimales. */
    public static function importe(string|float|int|null $valor): float
    {
        return round((float) str_replace(',', '.', (string) ($valor ?? '0')), 2);
    }

    /** "21,00" / "21.0" / "21" → "21" */
    public static function normalizarAlicuota(string $alicuota): string
    {
        $alicuota = str_replace(',', '.', trim($alicuota));
        if ($alicuota === '') {
            return '';
        }
        $texto = rtrim(rtrim(number_format((float) $alicuota, 2, '.', ''), '0'), '.');
        return $texto === '' ? '0' : $texto;   // "0,00" es la alícuota 0, no vacío
    }
}
