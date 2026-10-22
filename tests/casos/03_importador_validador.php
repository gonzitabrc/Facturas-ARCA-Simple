<?php
// Prueba del importador y del validador con una planilla armada al vuelo.
// No toca ARCA ni emite nada.
declare(strict_types=1);

require __DIR__ . '/../../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Lote\Importador;
use Facturador\Lote\Validador;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

App::iniciar();

$CUIT = '20123456786';
$dir = App::storage('tmp');
$ok = 0;
$fallos = 0;

function chequear(string $titulo, callable $fn): void
{
    global $ok, $fallos;
    try {
        $r = $fn();
        if ($r === true) {
            echo "OK    {$titulo}\n";
            $ok++;
        } else {
            echo "FALLA {$titulo}: " . var_export($r, true) . "\n";
            $fallos++;
        }
    } catch (\Throwable $e) {
        echo "FALLA {$titulo}: " . $e->getMessage() . "\n";
        $fallos++;
    }
}

// ------------------------------------------------------------------- emisor
Db::ejecutar('DELETE FROM comprobantes');
Db::ejecutar('DELETE FROM lotes');
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);
$id = Db::insertar('emisores', [
    'cuit' => $CUIT, 'razon_social' => 'ZZTEST Emisor SRL', 'domicilio' => 'Calle 1',
    'condicion_iva' => 'RI', 'punto_venta' => 5, 'ambiente' => 'homo',
]);
$emisorRI = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
$emisorMono = array_merge($emisorRI, ['condicion_iva' => 'MONO']);

// --------------------------------------------------- normalización de formatos
chequear('Fechas en todos los formatos', fn (): bool =>
    Importador::normalizarFecha('31/12/2026') === '2026-12-31'
    && Importador::normalizarFecha('2026-01-05') === '2026-01-05'
    && Importador::normalizarFecha('5-1-26') === '2026-01-05'
    && Importador::normalizarFecha(46317) === '2026-10-22'   // serial de Excel para el 22/10/2026
    && Importador::normalizarFecha(46316) === '2026-10-21'
    && Importador::normalizarFecha('') === null);

chequear('Importes con coma, punto y símbolos', fn (): bool =>
    Importador::normalizarImporte('1.234,56') === '1234.56'
    && Importador::normalizarImporte('1,234.56') === '1234.56'
    && Importador::normalizarImporte('$ 121000') === '121000.00'
    && Importador::normalizarImporte('100000') === '100000.00'
    && Importador::normalizarImporte('12,5') === '12.50'
    && Importador::normalizarImporte(1210.5) === '1210.50'
    && Importador::normalizarImporte('') === null);

chequear('Alícuotas', fn (): bool =>
    Importador::normalizarAlicuota('21%') === '21'
    && Importador::normalizarAlicuota('10,50') === '10.5'
    && Importador::normalizarAlicuota('0') === '0'
    && Importador::normalizarAlicuota(null) === '');

chequear('Encabezados con acentos, mayúsculas y espacios', fn (): bool =>
    Importador::normalizarEncabezado('  Razón Social ') === 'razon_social'
    && Importador::normalizarEncabezado('ID EXTERNO') === 'id_externo'
    && Importador::normalizarEncabezado('Neto Gravado') === 'neto_gravado');

// --------------------------------------------------------- planilla de verdad
function escribirXlsx(string $ruta, array $filas, array $encabezados = null): void
{
    $libro = new Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Facturas');
    $hoja->fromArray($encabezados ?? Importador::COLUMNAS, null, 'A1');
    $f = 2;
    foreach ($filas as $fila) {
        $hoja->fromArray($fila, null, 'A' . $f);
        $f++;
    }
    (new Xlsx($libro))->save($ruta);
}

$hoy = date('Y-m-d');
$filasBuenas = [
    // id_externo, pv, tipo, concepto, fecha, doc_tipo, doc_nro, cond_iva, razon, dom, email, desc,
    // neto, alic, no_grav, exento, total, moneda, cotiz, serv_desde, serv_hasta, vto_pago, asoc...
    ['REF-001', 5, 6, 1, $hoy, 96, '30111222', 5, 'Juan Pérez', 'Mitre 123', 'j@e.com', 'Servicio web', '1.000,00', '21', 0, 0, '1.210,00', 'PES', 1],
    ['REF-002', 5, 1, 2, $hoy, 80, '30712345671', 1, 'Empresa SA', 'Av 742', 'a@e.com', 'Abono mensual', '100000', '21', 0, 0, '121000', 'PES', 1, $hoy, $hoy, $hoy],
];

$rutaXlsx = $dir . '/zztest_lote.xlsx';
escribirXlsx($rutaXlsx, $filasBuenas);

chequear('Lee el XLSX y normaliza las dos filas', function () use ($rutaXlsx): bool {
    $r = Importador::leer($rutaXlsx, 'lote.xlsx');
    if (count($r['filas']) !== 2 || $r['faltantes'] !== []) {
        return false;
    }
    $a = $r['filas'][0];
    $b = $r['filas'][1];
    return $a['fila_excel'] === 2
        && $a['datos']['id_externo'] === 'REF-001'
        && $a['datos']['neto_gravado'] === '1000.00'
        && $a['datos']['total'] === '1210.00'
        && $a['datos']['alicuota_iva'] === '21'
        && $a['datos']['tipo_cbte'] === 6
        && $b['datos']['doc_nro'] === '30712345671'
        && $b['datos']['serv_desde'] === date('Y-m-d');
});

chequear('Valida sin errores las filas buenas', function () use ($rutaXlsx, $emisorRI): bool {
    $r = Importador::leer($rutaXlsx, 'lote.xlsx');
    $v = (new Validador($emisorRI, [1, 4, 5, 6, 13], []))->validar($r['filas']);
    foreach ($v as $fila) {
        if ($fila['errores'] !== []) {
            echo '      errores: ' . json_encode($fila['errores'], JSON_UNESCAPED_UNICODE) . "\n";
            return false;
        }
    }
    return $v[0]['datos']['iva'] === '210.00' && $v[1]['datos']['iva'] === '21000.00';
});

// ------------------------------------------------------------ filas con errores
$rutaMalo = $dir . '/zztest_malo.xlsx';
escribirXlsx($rutaMalo, [
    ['', 5, 6, 1, $hoy, 96, '30111222', 5, 'Sin referencia', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-CUIT', 5, 1, 1, $hoy, 80, '20123456789', 1, 'CUIT mal', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-TOTAL', 5, 6, 1, $hoy, 96, '30111222', 5, 'Total mal', '', '', 'x', '1000', '21', 0, 0, '1000', 'PES', 1],
    ['REF-FECHA', 5, 6, 1, '2020-01-01', 96, '30111222', 5, 'Fecha vieja', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-SERV', 5, 6, 2, $hoy, 96, '30111222', 5, 'Sin fechas', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-ALIC', 5, 6, 1, $hoy, 96, '30111222', 5, 'Alicuota rara', '', '', 'x', '100', '15', 0, 0, '115', 'PES', 1],
    ['REF-PV', 9, 6, 1, $hoy, 96, '30111222', 5, 'Otro PV', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-NOTA', 5, 8, 1, $hoy, 96, '30111222', 5, 'NC sin asociado', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-COND', 5, 6, 1, $hoy, 96, '30111222', 99, 'Cond IVA inexistente', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-DESC', 5, 6, 1, $hoy, 96, '30111222', 5, 'Sin descripcion', '', '', '', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-001', 5, 6, 1, $hoy, 96, '30111222', 5, 'Repetida', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
    ['REF-001', 5, 6, 1, $hoy, 96, '30111222', 5, 'Repetida otra vez', '', '', 'x', '100', '21', 0, 0, '121', 'PES', 1],
]);

$validadas = (new Validador($emisorRI, [1, 4, 5, 6, 13], []))->validar(Importador::leer($rutaMalo, 'malo.xlsx')['filas']);
$porRef = [];
foreach ($validadas as $fila) {
    $porRef[$fila['datos']['id_externo'] ?: 'VACIO'][] = $fila;
}

function tieneError(array $filas, string $aguja): bool
{
    foreach ($filas as $fila) {
        foreach ($fila['errores'] as $error) {
            if (str_contains($error, $aguja)) {
                return true;
            }
        }
    }
    return false;
}

chequear('Detecta referencia vacía', fn (): bool => tieneError($porRef['VACIO'] ?? [], 'falta la referencia'));
chequear('Detecta CUIT con dígito verificador mal', fn (): bool => tieneError($porRef['REF-CUIT'] ?? [], 'no es válido (dígito verificador)'));
chequear('Detecta total que no cierra', fn (): bool => tieneError($porRef['REF-TOTAL'] ?? [], 'no coincide con el calculado'));
chequear('Detecta fecha fuera de tolerancia', fn (): bool => tieneError($porRef['REF-FECHA'] ?? [], 'ARCA acepta hasta'));
chequear('Detecta servicios sin fechas', fn (): bool => tieneError($porRef['REF-SERV'] ?? [], 'falta la fecha desde'));
chequear('Detecta alícuota inexistente', fn (): bool => tieneError($porRef['REF-ALIC'] ?? [], 'no existe en ARCA'));
chequear('Detecta punto de venta ajeno', fn (): bool => tieneError($porRef['REF-PV'] ?? [], 'no es el de esta app'));
chequear('Detecta nota de crédito sin asociado', fn (): bool => tieneError($porRef['REF-NOTA'] ?? [], 'necesita el comprobante asociado'));
chequear('Detecta condición de IVA fuera de la tabla', fn (): bool => tieneError($porRef['REF-COND'] ?? [], 'no está en la lista de ARCA'));
chequear('Detecta descripción vacía', fn (): bool => tieneError($porRef['REF-DESC'] ?? [], 'falta la descripción'));
chequear('Detecta referencia repetida en el archivo', fn (): bool => tieneError($porRef['REF-001'] ?? [], 'está repetida en la fila'));

chequear('El mensaje al cliente dice la fila y el motivo', function () use ($porRef): bool {
    $fila = ($porRef['REF-CUIT'] ?? [])[0] ?? null;
    $texto = $fila === null ? '' : Validador::mensaje($fila['fila_excel'], $fila['errores']);
    return str_starts_with($texto, 'Fila 3:') && str_contains($texto, '20-12345678-9') && str_ends_with($texto, '.');
});

// ------------------------------------------------- monotributo: solo clase C
chequear('Monotributo no puede emitir factura A o B', function () use ($rutaXlsx, $emisorMono): bool {
    $v = (new Validador($emisorMono, [1, 5], []))->validar(Importador::leer($rutaXlsx, 'x.xlsx')['filas']);
    return tieneError($v, 'siendo monotributo o exento se emiten comprobantes C');
});

$rutaC = $dir . '/zztest_c.xlsx';
escribirXlsx($rutaC, [
    ['REF-C1', 5, 11, 1, $hoy, 99, '0', 5, '', '', '', 'Factura C de prueba', '1210', '', 0, 0, '1210', 'PES', 1],
    ['REF-C2', 5, 11, 1, $hoy, 99, '0', 5, '', '', '', 'C con alicuota mal puesta', '1000', '21', 0, 0, '1210', 'PES', 1],
]);

$validadasC = (new Validador($emisorMono, [1, 5], []))->validar(Importador::leer($rutaC, 'c.xlsx')['filas']);

chequear('Factura C válida: neto = total y sin IVA', function () use ($validadasC): bool {
    $fila = $validadasC[0];
    return $fila['errores'] === []
        && $fila['datos']['iva'] === '0'
        && $fila['datos']['alicuota_iva'] === ''
        && $fila['datos']['neto_gravado'] === '1210.00';
});

chequear('Factura C con alícuota se rechaza', fn (): bool => tieneError([$validadasC[1]], 'en comprobante C no se informa alícuota'));

// ------------------------------------------------------------------------ CSV
$rutaCsv = $dir . '/zztest_lote.csv';
file_put_contents($rutaCsv, "\xEF\xBB\xBF" . implode(';', Importador::COLUMNAS) . "\n"
    . "REF-CSV;5;6;1;{$hoy};96;30111222;5;Cliente CSV;Calle;c@e.com;Desde CSV;1.000,00;21;0;0;1.210,00;PES;1\n"
    . ";;;;;;;;;;;;;;;;;;\n");

chequear('Lee CSV con BOM, punto y coma y salta la fila vacía', function () use ($rutaCsv, $emisorRI): bool {
    $r = Importador::leer($rutaCsv, 'lote.csv');
    if (count($r['filas']) !== 1) {
        return false;
    }
    $v = (new Validador($emisorRI, [1, 5], []))->validar($r['filas']);
    return $v[0]['errores'] === []
        && $v[0]['datos']['id_externo'] === 'REF-CSV'
        && $v[0]['datos']['total'] === '1210.00'
        && $v[0]['datos']['iva'] === '210.00';
});

chequear('Archivo sin los encabezados de la plantilla se rechaza', function () use ($dir): bool {
    $ruta = $dir . '/zztest_sinenc.csv';
    file_put_contents($ruta, "cosa;otra;mas\n1;2;3\n");
    try {
        Importador::leer($ruta, 'x.csv');
        return false;
    } catch (\Throwable $e) {
        @unlink($ruta);
        return str_contains($e->getMessage(), 'No se reconoció ninguna columna');
    }
});

chequear('Si falta una columna obligatoria se avisa cuál', function () use ($dir): bool {
    $ruta = $dir . '/zztest_falta.csv';
    file_put_contents($ruta, "id_externo;tipo_cbte;concepto;fecha;doc_tipo;doc_nro\nREF;6;1;2026-10-22;96;30111222\n");
    $r = Importador::leer($ruta, 'x.csv');
    @unlink($ruta);
    return $r['faltantes'] === ['total'];
});

// ------------------------------- referencia ya emitida en un lote anterior
chequear('Referencia ya facturada antes se rechaza', function () use ($emisorRI, $rutaXlsx): bool {
    $loteId = Db::insertar('lotes', [
        'emisor_id' => (int) $emisorRI['id'], 'archivo_original' => 'anterior.xlsx',
        'archivo_path' => '-', 'archivo_sha256' => 'x', 'estado' => 'FINALIZADO', 'total_filas' => 1,
    ]);
    Db::insertar('comprobantes', [
        'lote_id' => $loteId, 'emisor_id' => (int) $emisorRI['id'], 'fila_excel' => 2,
        'id_externo' => 'REF-001', 'punto_venta' => 5, 'tipo_cbte' => 6, 'concepto' => 1,
        'fecha' => date('Y-m-d'), 'doc_tipo' => 96, 'doc_nro' => '30111222',
        'condicion_iva_receptor' => 5, 'descripcion' => 'ya emitida', 'neto_gravado' => '1000',
        'total' => '1210', 'iva' => '210', 'estado' => 'PDF_OK', 'nro' => 77,
    ]);
    $v = (new Validador($emisorRI, [1, 5], []))->validar(Importador::leer($rutaXlsx, 'x.xlsx')['filas']);
    Db::ejecutar('DELETE FROM comprobantes WHERE lote_id = ?', [$loteId]);
    Db::ejecutar('DELETE FROM lotes WHERE id = ?', [$loteId]);
    return tieneError($v, 'ya se facturó (comprobante 00000077)');
});

// -------------------------------------------------------------------- limpieza
foreach (['zztest_lote.xlsx', 'zztest_malo.xlsx', 'zztest_c.xlsx', 'zztest_lote.csv'] as $f) {
    @unlink($dir . '/' . $f);
}
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);

echo "\n== {$ok} OK / {$fallos} fallas ==\n";
exit($fallos === 0 ? 0 : 1);
