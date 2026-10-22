<?php
/**
 * Prueba de punta a punta contra ARCA, desde la línea de comandos.
 *
 * Sin --emitir solo mira (no autoriza nada):
 *     php tests/emitir_prueba.php
 *
 * Emitir una factura C de prueba en homologación:
 *     php tests/emitir_prueba.php --emitir --tipo=11 --total=1210
 *
 * Emitir en PRODUCCIÓN (factura real, con validez fiscal) hace falta el segundo flag:
 *     php tests/emitir_prueba.php --emitir --si-es-produccion --tipo=11 --total=1210
 *
 * Opciones: --cuit= --tipo= --total= --neto= --alicuota= --concepto= --doc-tipo= --doc=
 *           --cond-iva= --fecha= --desc= --ambos
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

// ------------------------------------------------------------------ argumentos
$opciones = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argumento, $m) === 1) {
        $opciones[$m[1]] = $m[2] ?? '1';
    }
}
$emitir = isset($opciones['emitir']);
$confirmaProduccion = isset($opciones['si-es-produccion']);

function linea(string $texto = ''): void
{
    echo $texto, \PHP_EOL;
}

function titulo(string $texto): void
{
    linea();
    linea($texto);
    linea(str_repeat('-', mb_strlen($texto)));
}

// --------------------------------------------------------------------- emisor
$emisor = isset($opciones['cuit'])
    ? Db::una('SELECT * FROM emisores WHERE cuit = ?', [preg_replace('/\D/', '', $opciones['cuit'])])
    : Db::una('SELECT * FROM emisores ORDER BY id LIMIT 1');

if ($emisor === null) {
    linea('No hay ningún emisor cargado. Cargalo en Configuración antes de probar.');
    exit(1);
}

$cert = Certificados::estado($emisor);
if (!$cert['tiene_par']) {
    linea('El emisor ' . $emisor['cuit'] . ' no tiene certificado cargado. Subilo o importalo en Configuración.');
    exit(1);
}

titulo('Emisor');
linea('CUIT:            ' . $emisor['cuit'] . ' — ' . $emisor['razon_social']);
linea('Punto de venta:  ' . $emisor['punto_venta']);
linea('Ambiente:        ' . ($emisor['ambiente'] === 'prod' ? 'PRODUCCIÓN (facturas reales)' : 'homologación (pruebas)'));
linea('Certificado:     alias ' . $cert['alias'] . ', vence ' . $cert['vence'] . ' (' . $cert['dias'] . ' días)');

$wsfe = new Wsfe($emisor);

try {
    // --------------------------------------------------------------- FEDummy
    titulo('1. FEDummy — ¿está en pie el servicio?');
    $estado = $wsfe->dummy();
    linea('Aplicación: ' . $estado['AppServer'] . ' · Base: ' . $estado['DbServer'] . ' · Autenticación: ' . $estado['AuthServer']);
    if (!$estado['ok']) {
        linea('ARCA tiene servicios caídos. No es problema de la app: esperar y volver a probar.');
        exit(1);
    }

    // ------------------------------------------------------------------ WSAA
    titulo('2. WSAA — ticket de acceso');
    $ta = Wsaa::ta($emisor);
    linea('Ticket válido hasta ' . $ta['expira'] . ' (el token y el sign no se muestran ni se loguean).');

    // ------------------------------------------- condiciones de IVA del receptor
    titulo('3. FEParamGetCondicionIvaReceptor');
    $condiciones = $wsfe->condicionesIvaReceptor();
    linea(count($condiciones) . ' valores. Los primeros:');
    foreach (array_slice($condiciones, 0, 6) as $c) {
        linea('  ' . str_pad((string) $c['id'], 3) . ' ' . $c['desc'] . ($c['clase'] !== '' ? ' (clase ' . $c['clase'] . ')' : ''));
    }

    // ------------------------------------------------------- qué se va a emitir
    $tipos = isset($opciones['ambos'])
        ? [11, 6]
        : [(int) ($opciones['tipo'] ?? 11)];

    titulo('4. FECompUltimoAutorizado');
    foreach ($tipos as $tipo) {
        linea('Tipo ' . $tipo . ': último autorizado = ' . $wsfe->ultimoAutorizado($tipo));
    }

    if (!$emitir) {
        titulo('Listo: todo lo de solo lectura funciona');
        linea('Para emitir una factura de prueba agregá --emitir');
        linea($emisor['ambiente'] === 'prod'
            ? '(en producción hace falta también --si-es-produccion, porque la factura es real)'
            : '(estás en homologación: las facturas no tienen validez fiscal)');
        exit(0);
    }

    // --------------------------------------------------------------- emisión
    foreach ($tipos as $tipo) {
        $esC = in_array($tipo, Wsfe::TIPOS_C, true);

        // Por defecto: $1.210 finales. En C el total es el neto; en B, neto 1000 + IVA 21%.
        $total = (string) ($opciones['total'] ?? '1210.00');
        if ($esC) {
            $neto = (string) ($opciones['neto'] ?? $total);
            $alicuota = '';
            $iva = '0';
        } else {
            $alicuota = (string) ($opciones['alicuota'] ?? '21');
            $neto = (string) ($opciones['neto'] ?? number_format((float) $total / (1 + (float) $alicuota / 100), 2, '.', ''));
            $iva = number_format((float) $neto * (float) $alicuota / 100, 2, '.', '');
            $total = number_format((float) $neto + (float) $iva, 2, '.', '');
        }

        $comprobante = [
            'tipo_cbte'              => $tipo,
            'punto_venta'            => (int) $emisor['punto_venta'],
            'concepto'               => (int) ($opciones['concepto'] ?? 1),
            'fecha'                  => (string) ($opciones['fecha'] ?? date('Y-m-d')),
            'doc_tipo'               => (int) ($opciones['doc-tipo'] ?? 99),
            'doc_nro'                => (string) ($opciones['doc'] ?? '0'),
            'condicion_iva_receptor' => (int) ($opciones['cond-iva'] ?? 5),   // 5 = Consumidor Final
            'razon_social'           => (string) ($opciones['razon'] ?? 'Consumidor Final'),
            'descripcion'            => (string) ($opciones['desc'] ?? 'Prueba de facturación electrónica'),
            'neto_gravado'           => $neto,
            'alicuota_iva'           => $alicuota,
            'iva'                    => $iva,
            'no_gravado'             => '0',
            'exento'                 => '0',
            'total'                  => $total,
            'moneda'                 => 'PES',
            'cotizacion'             => '1',
        ];
        if ($comprobante['concepto'] !== 1) {
            $comprobante['serv_desde'] = (string) ($opciones['serv-desde'] ?? date('Y-m-d'));
            $comprobante['serv_hasta'] = (string) ($opciones['serv-hasta'] ?? date('Y-m-d'));
            $comprobante['vto_pago']   = (string) ($opciones['vto-pago'] ?? date('Y-m-d'));
        }

        titulo('5. Esto es lo que se va a emitir' . ($esC ? ' (Factura C)' : ' (Factura B)'));
        foreach ($comprobante as $campo => $valor) {
            linea('  ' . str_pad($campo, 24) . ($valor === '' ? '(vacío)' : (string) $valor));
        }

        if ($emisor['ambiente'] === 'prod' && !$confirmaProduccion) {
            linea();
            linea('FRENO: el emisor está en PRODUCCIÓN y esta factura sería real, con validez fiscal.');
            linea('Si es lo que querés, repetí el comando agregando --si-es-produccion');
            exit(1);
        }

        // Regla de la spec: se graba ENVIANDO + nro_esperado ANTES de llamar a ARCA.
        $ultimo = $wsfe->ultimoAutorizado($tipo);
        $nro = $ultimo + 1;

        $loteId = Db::insertar('lotes', [
            'emisor_id'        => (int) $emisor['id'],
            'archivo_original' => 'prueba-manual',
            'archivo_path'     => '-',
            'archivo_sha256'   => hash('sha256', 'prueba-' . microtime(true)),
            'estado'           => 'PROCESANDO',
            'total_filas'      => 1,
        ]);

        $comprobanteId = Db::insertar('comprobantes', array_merge(
            array_intersect_key($comprobante, array_flip([
                'punto_venta', 'tipo_cbte', 'concepto', 'fecha', 'doc_tipo', 'doc_nro',
                'condicion_iva_receptor', 'razon_social', 'descripcion', 'neto_gravado',
                'alicuota_iva', 'no_gravado', 'exento', 'total', 'moneda', 'cotizacion',
                'serv_desde', 'serv_hasta', 'vto_pago', 'iva',
            ])),
            [
                'lote_id'     => $loteId,
                'emisor_id'   => (int) $emisor['id'],
                'fila_excel'  => 1,
                'id_externo'  => 'PRUEBA-' . date('YmdHis') . '-' . $tipo,
                'estado'      => 'ENVIANDO',
                'nro_esperado' => $nro,
                'intentos'    => 1,
            ]
        ));

        linea();
        linea('Número a usar: ' . str_pad((string) $emisor['punto_venta'], 4, '0', \STR_PAD_LEFT)
            . '-' . str_pad((string) $nro, 8, '0', \STR_PAD_LEFT)
            . ' (guardado como ENVIANDO antes de llamar a ARCA)');

        titulo('6. FECAESolicitar');
        $respuesta = $wsfe->solicitarCae($comprobante, $nro, $comprobanteId, $loteId);

        if ($respuesta['resultado'] === 'A') {
            Db::actualizar('comprobantes', $comprobanteId, [
                'estado'             => 'AUTORIZADO',
                'nro'                => $respuesta['nro'],
                'cae'                => $respuesta['cae'],
                'vto_cae'            => $respuesta['vto_cae'],
                'observaciones_arca' => $respuesta['observaciones'] === [] ? null : json_encode($respuesta['observaciones'], \JSON_UNESCAPED_UNICODE),
            ]);
            Db::actualizar('lotes', $loteId, ['estado' => 'FINALIZADO']);

            linea('AUTORIZADA');
            linea('  Comprobante: ' . str_pad((string) $emisor['punto_venta'], 4, '0', \STR_PAD_LEFT) . '-' . str_pad((string) $respuesta['nro'], 8, '0', \STR_PAD_LEFT));
            linea('  CAE:         ' . $respuesta['cae'] . ' (vence ' . $respuesta['vto_cae'] . ')');
            if ($respuesta['observaciones'] !== []) {
                linea('  Observaciones de ARCA (la factura vale igual):');
                foreach ($respuesta['observaciones'] as $obs) {
                    linea('    [' . $obs['codigo'] . '] ' . $obs['mensaje']);
                }
            }
        } else {
            Db::actualizar('comprobantes', $comprobanteId, [
                'estado'    => 'RECHAZADO',
                'error_cod' => $respuesta['errores'][0]['codigo'] ?? ($respuesta['observaciones'][0]['codigo'] ?? null),
                'error_msg' => $respuesta['mensaje'],
            ]);
            Db::actualizar('lotes', $loteId, ['estado' => 'FINALIZADO_CON_ERRORES', 'motivo_pausa' => $respuesta['mensaje']]);

            linea('RECHAZADA por ARCA (' . $respuesta['categoria'] . ')');
            linea('  ' . $respuesta['mensaje']);
            linea('  El número no se consumió: se corrige el dato y se vuelve a emitir.');
            continue;
        }

        titulo('7. FECompConsultar — confirmar contra ARCA');
        $consulta = $wsfe->consultarComprobante($tipo, (int) $respuesta['nro']);
        if ($consulta === null) {
            linea('ARCA todavía no lo devuelve en la consulta. Reintentá en unos segundos.');
        } else {
            linea('  Número:  ' . $consulta['nro']);
            linea('  CAE:     ' . $consulta['cae']);
            linea('  Fecha:   ' . $consulta['fecha']);
            linea('  Importe: ' . $consulta['total']);
            linea('  Receptor: tipo ' . $consulta['doc_tipo'] . ' nro ' . $consulta['doc_nro']);
            $coincide = $consulta['total'] === number_format((float) $comprobante['total'], 2, '.', '')
                && $consulta['fecha'] === $comprobante['fecha'];
            linea($coincide ? '  Coincide con lo que mandamos.' : '  OJO: no coincide con lo que mandamos, revisar.');
        }
    }

    titulo('Listo');
    linea('Quedó en el historial de la app (lote "prueba-manual").');
    if ($emisor['ambiente'] === 'prod') {
        linea('Era PRODUCCIÓN: si fue solo una prueba, anulala con una nota de crédito.');
    }
    exit(0);
} catch (ArcaExcepcion $e) {
    linea();
    linea('ERROR (' . $e->categoria . '): ' . $e->getMessage());
    if ($e->mensajeArca !== null) {
        linea('Lo que dijo ARCA: ' . $e->mensajeArca);
    }
    linea('Quedó registrado en la tabla eventos y en storage/logs/.');
    exit(1);
} catch (\Throwable $e) {
    linea();
    linea('ERROR inesperado: ' . $e->getMessage());
    linea($e->getFile() . ':' . $e->getLine());
    exit(1);
}
