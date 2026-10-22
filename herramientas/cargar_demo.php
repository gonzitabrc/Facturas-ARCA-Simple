<?php
/**
 * Carga los datos de ejemplo de una instalación de demostración: un emisor
 * ficticio con un certificado autofirmado, dos lotes y sus comprobantes, con
 * los PDF generados.
 *
 *     php herramientas/cargar_demo.php          # carga si está vacío
 *     php herramientas/cargar_demo.php --rehacer  # borra lo que haya y vuelve a cargar
 *
 * Solo corre si config.php tiene 'modo_demo' => true: nunca toca una instalación
 * que factura de verdad. Nada de esto habla con ARCA.
 */
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Pdf\GeneradorPdf;
use Facturador\Seguridad\Certificados;

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

App::iniciar();

if (!App::esDemo()) {
    fwrite(\STDERR, "Esta instalación no está en modo demo ('modo_demo' => true en config.php). No hago nada.\n");
    exit(1);
}

$rehacer = in_array('--rehacer', $argv, true);

function linea(string $t = ''): void
{
    echo $t, \PHP_EOL;
}

$CUIT = '20123456786';

// ------------------------------------------------------------------ limpieza
if ($rehacer) {
    foreach (Db::todas('SELECT pdf_path FROM comprobantes WHERE pdf_path IS NOT NULL') as $fila) {
        @unlink((string) $fila['pdf_path']);
    }
    Db::ejecutar('DELETE FROM eventos');
    Db::ejecutar('DELETE FROM comprobantes');
    Db::ejecutar('DELETE FROM lotes');
    Db::ejecutar('DELETE FROM emisores');
    linea('Datos anteriores borrados.');
}

if ((int) Db::valor('SELECT COUNT(*) FROM lotes') > 0) {
    linea('Ya hay datos cargados. Usá --rehacer para empezar de cero.');
    exit(0);
}

// -------------------------------------------------------------------- emisor
$emisor = Db::una('SELECT * FROM emisores WHERE cuit = ?', [$CUIT]);
if ($emisor === null) {
    $id = Db::insertar('emisores', [
        'cuit'               => $CUIT,
        'razon_social'       => 'Distribuidora del Valle SRL',
        'domicilio'          => 'Av. Siempreviva 742, San Carlos de Bariloche',
        'condicion_iva'      => 'MONO',
        'iibb'               => '901-234567-8',
        'inicio_actividades' => '2019-04-01',
        'punto_venta'        => 4,
        'ambiente'           => 'prod',
    ]);
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
    linea('Emisor de ejemplo creado: ' . $emisor['razon_social'] . ' (CUIT ' . $CUIT . ')');
}

// ------------------------------------------- certificado ficticio autofirmado
if (!Certificados::estado($emisor)['tiene_par']) {
    $pedido = Certificados::generarPedido($emisor, 'octubre2028');

    $cfg = [
        'config'           => App::storage('openssl.cnf'),
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        'encrypt_key'      => false,
    ];
    $caClave = openssl_pkey_new($cfg);
    $caPedido = openssl_csr_new(['countryName' => 'AR', 'commonName' => 'Certificadora de ejemplo'], $caClave, $cfg);
    $caCert = openssl_csr_sign($caPedido, null, $caClave, 3650, $cfg, 1);
    $firmado = openssl_csr_sign($pedido['csr'], $caCert, $caClave, 700, $cfg, 2);

    $pem = '';
    openssl_x509_export($firmado, $pem);
    $resultado = Certificados::guardarCertificado($emisor, $pem);
    $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [(int) $emisor['id']]);

    linea('Certificado de ejemplo (autofirmado, no sirve para ARCA): alias ' . $resultado['alias'] . ', vence ' . $resultado['vence']);
}

// --------------------------------------------------------------------- lotes
/** Inserta un comprobante y devuelve su id. */
function comprobante(array $emisor, int $loteId, int $fila, array $datos): int
{
    return Db::insertar('comprobantes', array_merge([
        'lote_id'                => $loteId,
        'emisor_id'              => (int) $emisor['id'],
        'fila_excel'             => $fila,
        'punto_venta'            => (int) $emisor['punto_venta'],
        'tipo_cbte'              => 11,
        'concepto'               => 2,
        'fecha'                  => date('Y-m-d', strtotime('-6 days')),
        'doc_tipo'               => 99,
        'doc_nro'                => '0',
        'condicion_iva_receptor' => 5,
        'razon_social'           => 'Consumidor Final',
        'alicuota_iva'           => null,
        'no_gravado'             => '0',
        'exento'                 => '0',
        'iva'                    => '0',
        'moneda'                 => 'PES',
        'cotizacion'             => '1',
        'serv_desde'             => date('Y-m-01', strtotime('-1 month')),
        'serv_hasta'             => date('Y-m-t', strtotime('-1 month')),
        'vto_pago'               => date('Y-m-10'),
        'estado'                 => 'VALIDADO',
    ], $datos));
}

$hace = static fn (int $dias): string => date('Y-m-d', strtotime("-{$dias} days"));

// Lote 1: terminado, con una fila rechazada por ARCA.
$lote1 = Db::insertar('lotes', [
    'emisor_id'        => (int) $emisor['id'],
    'archivo_original' => 'facturas-septiembre.xlsx',
    'archivo_path'     => '-',
    'archivo_sha256'   => hash('sha256', 'demo-1'),
    'estado'           => 'FINALIZADO_CON_ERRORES',
    'total_filas'      => 5,
    'creado'           => $hace(6) . ' 10:12:00',
    'actualizado'      => $hace(6) . ' 10:19:00',
]);

$facturas = [
    [2, 'HOT-1041', 'Hotel Las Cumbres SRL', 80, '30712345671', 1, 'Abono mensual sistema de reservas', '121000.00', 118],
    [3, 'HOT-1042', 'Agencia Sur SA', 80, '30714567892', 1, 'Desarrollo de landing page institucional', '86350.00', 119],
    [4, 'HOT-1043', 'María López', 96, '28456123', 5, 'Capacitación en herramientas digitales (3 h)', '45000.00', 120],
    [5, 'HOT-1044', 'Consumidor Final', 99, '0', 5, 'Servicio de soporte técnico mensual', '18150.00', 121],
];

foreach ($facturas as [$fila, $ref, $nombre, $docTipo, $docNro, $cond, $descripcion, $total, $nro]) {
    $id = comprobante($emisor, $lote1, $fila, [
        'id_externo'             => $ref,
        'razon_social'           => $nombre,
        'doc_tipo'               => $docTipo,
        'doc_nro'                => $docNro,
        'condicion_iva_receptor' => $cond,
        'descripcion'            => $descripcion,
        'neto_gravado'           => $total,
        'total'                  => $total,
        'estado'                 => 'AUTORIZADO',
        'nro_esperado'           => $nro,
        'nro'                    => $nro,
        'cae'                    => '7510' . str_pad((string) $nro, 10, '0', \STR_PAD_LEFT),
        'vto_cae'                => date('Y-m-d', strtotime('+4 days')),
        'intentos'               => 1,
    ]);
    $guardado = Db::una('SELECT * FROM comprobantes WHERE id = ?', [$id]);
    GeneradorPdf::generarSiFalta($emisor, $guardado);
}

comprobante($emisor, $lote1, 6, [
    'id_externo'  => 'HOT-1045',
    'razon_social' => 'Transportes del Lago SRL',
    'doc_tipo'    => 80,
    'doc_nro'     => '30999999999',
    'condicion_iva_receptor' => 1,
    'descripcion' => 'Servicio de mantenimiento web',
    'neto_gravado' => '64000.00',
    'total'       => '64000.00',
    'estado'      => 'RECHAZADO',
    'error_cod'   => '10016',
    'error_msg'   => 'Fila 6: el CUIT 30-99999999-9 no es válido (dígito verificador).',
    'intentos'    => 1,
]);

linea('Lote 1 cargado: 4 facturas emitidas con su PDF y 1 rechazada.');

// Lote 2: recién subido, esperando que lo emitan.
$lote2 = Db::insertar('lotes', [
    'emisor_id'        => (int) $emisor['id'],
    'archivo_original' => 'facturas-octubre.xlsx',
    'archivo_path'     => '-',
    'archivo_sha256'   => hash('sha256', 'demo-2'),
    'estado'           => 'VALIDADO',
    'total_filas'      => 3,
    'creado'           => $hace(1) . ' 09:40:00',
    'actualizado'      => $hace(1) . ' 09:40:00',
]);

comprobante($emisor, $lote2, 2, [
    'id_externo'   => 'HOT-1051',
    'razon_social' => 'Hotel Las Cumbres SRL',
    'doc_tipo'     => 80,
    'doc_nro'      => '30712345671',
    'condicion_iva_receptor' => 1,
    'descripcion'  => 'Abono mensual sistema de reservas',
    'neto_gravado' => '121000.00',
    'total'        => '121000.00',
    'fecha'        => $hace(1),
]);

comprobante($emisor, $lote2, 3, [
    'id_externo'   => 'HOT-1052',
    'razon_social' => 'Cabañas del Bosque',
    'doc_tipo'     => 80,
    'doc_nro'      => '30714567892',
    'condicion_iva_receptor' => 1,
    'descripcion'  => 'Alta de tienda online y capacitación',
    'neto_gravado' => '215000.00',
    'total'        => '215000.00',
    'fecha'        => $hace(1),
]);

comprobante($emisor, $lote2, 4, [
    'id_externo'   => 'HOT-1053',
    'razon_social' => 'Excursiones Nahuel',
    'doc_tipo'     => 80,
    'doc_nro'      => '30711111111',
    'condicion_iva_receptor' => 1,
    'descripcion'  => 'Rediseño del sitio institucional',
    'neto_gravado' => '180000.00',
    'total'        => '195000.00',
    'fecha'        => $hace(1),
    'estado'       => 'ERROR_DATOS',
    'error_msg'    => 'Fila 4: el CUIT 30-71111111-1 no es válido (dígito verificador); el total informado (195000.00) no coincide con el calculado (180000.00 = neto 180000.00 + IVA 0.00).',
]);

linea('Lote 2 cargado: 2 filas listas para emitir y 1 para corregir.');

linea();
linea('Demo listo. Nada de esto tocó ARCA: el certificado es autofirmado y los CAE son inventados.');
