<?php
/**
 * Arma una planilla de una sola fila, lista para subir, y la pasa por el mismo
 * validador que usa la app: si sale verde acá, sale verde al subirla.
 *
 * Factura de prueba a consumidor final por $1.210:
 *     php herramientas/armar_planilla.php --total=1210
 *
 * A un cliente con CUIT:
 *     php herramientas/armar_planilla.php --total=121000 --doc-tipo=80 --doc=30712345671 \
 *         --razon="Empresa Ejemplo SA" --cond-iva=1 --desc="Desarrollo web octubre 2026"
 *
 * Nota de crédito para anular un comprobante que ya emitió la app:
 *     php herramientas/armar_planilla.php --nota-de-credito=123
 *
 * Otras opciones: --tipo= --alicuota= --concepto= --fecha= --referencia= --pv= --salida=
 */
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Db;
use Facturador\Lote\Importador;
use Facturador\Lote\Validador;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (\PHP_SAPI !== 'cli') {
    exit('Este script se corre desde la línea de comandos.');
}

App::iniciar();

function morir(string $mensaje): never
{
    fwrite(\STDERR, $mensaje . \PHP_EOL);
    exit(1);
}

// ------------------------------------------------------------------ argumentos
$o = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argumento, $m) === 1) {
        $o[$m[1]] = $m[2] ?? '1';
    }
}

// --------------------------------------------------------------------- emisor
$emisor = Db::una('SELECT * FROM emisores ORDER BY id LIMIT 1');
if ($emisor === null) {
    // Todavía no se cargó el emisor (por ejemplo, armando la planilla antes del deploy).
    $emisor = [
        'id'            => 0,
        'cuit'          => (string) ($o['cuit'] ?? '20000000001'),
        'razon_social'  => 'Emisor',
        'condicion_iva' => strtoupper((string) ($o['clase'] ?? 'MONO')),
        'punto_venta'   => (int) ($o['pv'] ?? 1),
        'ambiente'      => 'homo',
    ];
    $sinEmisor = true;
} else {
    $sinEmisor = false;
    if (isset($o['pv'])) {
        $emisor['punto_venta'] = (int) $o['pv'];
    }
}

$esMono = in_array((string) $emisor['condicion_iva'], ['MONO', 'EXENTO'], true);

// ------------------------------------------------------- ¿factura o nota de crédito?
$notaDe = isset($o['nota-de-credito']) ? (int) $o['nota-de-credito'] : 0;
$fila = [];

if ($notaDe > 0) {
    if ($sinEmisor) {
        morir('Para armar una nota de crédito hace falta el emisor cargado: la app busca el comprobante original en su historial.');
    }

    $original = Db::una(
        "SELECT * FROM comprobantes
          WHERE emisor_id = ? AND nro = ? AND punto_venta = ? AND estado IN ('AUTORIZADO','PDF_OK')
          ORDER BY id DESC LIMIT 1",
        [(int) $emisor['id'], $notaDe, (int) $emisor['punto_venta']]
    );
    if ($original === null) {
        morir(
            'No encontré un comprobante autorizado con el número ' . $notaDe
            . ' en el punto de venta ' . $emisor['punto_venta'] . '.' . \PHP_EOL
            . 'Fijate el número exacto en la pantalla del lote (la columna Comprobante).'
        );
    }

    // La nota de crédito es el espejo del original: misma clase, mismos importes.
    $tipoNc = match ((int) $original['tipo_cbte']) {
        1, 2 => 3,      // A
        6, 7 => 8,      // B
        default => 13,  // C
    };

    $fila = [
        'id_externo'             => (string) ($o['referencia'] ?? ('NC-' . $original['id_externo'])),
        'punto_venta'            => (int) $emisor['punto_venta'],
        'tipo_cbte'              => $tipoNc,
        'concepto'               => (int) $original['concepto'],
        'fecha'                  => (string) ($o['fecha'] ?? date('Y-m-d')),
        'doc_tipo'               => (int) $original['doc_tipo'],
        'doc_nro'                => (string) $original['doc_nro'],
        'condicion_iva_receptor' => (int) $original['condicion_iva_receptor'],
        'razon_social'           => (string) ($original['razon_social'] ?? ''),
        'domicilio'              => (string) ($original['domicilio'] ?? ''),
        'email'                  => (string) ($original['email'] ?? ''),
        'descripcion'            => (string) ($o['desc'] ?? ('Anulación de ' . str_pad((string) $original['punto_venta'], 4, '0', \STR_PAD_LEFT) . '-' . str_pad((string) $original['nro'], 8, '0', \STR_PAD_LEFT))),
        'neto_gravado'           => (string) $original['neto_gravado'],
        'alicuota_iva'           => (string) ($original['alicuota_iva'] ?? ''),
        'no_gravado'             => (string) $original['no_gravado'],
        'exento'                 => (string) $original['exento'],
        'total'                  => (string) $original['total'],
        'moneda'                 => (string) $original['moneda'],
        'cotizacion'             => (string) $original['cotizacion'],
        'serv_desde'             => (string) ($original['serv_desde'] ?? ''),
        'serv_hasta'             => (string) ($original['serv_hasta'] ?? ''),
        'vto_pago'               => (string) ($original['vto_pago'] ?? ''),
        'asoc_tipo'              => (int) $original['tipo_cbte'],
        'asoc_pto_vta'           => (int) $original['punto_venta'],
        'asoc_nro'               => (int) $original['nro'],
    ];
    $titulo = 'Nota de crédito del comprobante ' . str_pad((string) $original['nro'], 8, '0', \STR_PAD_LEFT);
    $salidaPorDefecto = 'deploy/planilla-nota-credito.xlsx';
} else {
    // ------------------------------------------------------------ factura nueva
    $total = (string) ($o['total'] ?? '1210.00');
    if (!is_numeric(str_replace(',', '.', $total)) || (float) str_replace(',', '.', $total) <= 0) {
        morir('Pasá el importe con --total=1210');
    }
    $total = number_format((float) str_replace(',', '.', $total), 2, '.', '');

    $tipo = (int) ($o['tipo'] ?? ($esMono ? 11 : 6));
    $esC = in_array($tipo, [11, 12, 13], true);

    if ($esC) {
        $neto = $total;
        $alicuota = '';
        $iva = '0';
    } else {
        $alicuota = (string) ($o['alicuota'] ?? '21');
        $neto = number_format((float) $total / (1 + (float) $alicuota / 100), 2, '.', '');
        $iva = number_format((float) $neto * (float) $alicuota / 100, 2, '.', '');
        // El total manda: se ajusta el neto para que la suma cierre al centavo.
        $total = number_format((float) $neto + (float) $iva, 2, '.', '');
    }

    $docTipo = (int) ($o['doc-tipo'] ?? 99);
    $concepto = (int) ($o['concepto'] ?? 1);

    $fila = [
        'id_externo'             => (string) ($o['referencia'] ?? ('PRUEBA-' . date('Ymd-Hi'))),
        'punto_venta'            => (int) $emisor['punto_venta'],
        'tipo_cbte'              => $tipo,
        'concepto'               => $concepto,
        'fecha'                  => (string) ($o['fecha'] ?? date('Y-m-d')),
        'doc_tipo'               => $docTipo,
        'doc_nro'                => (string) ($o['doc'] ?? ($docTipo === 99 ? '0' : '')),
        'condicion_iva_receptor' => (int) ($o['cond-iva'] ?? ($docTipo === 99 ? 5 : 1)),
        'razon_social'           => (string) ($o['razon'] ?? ($docTipo === 99 ? 'Consumidor Final' : '')),
        'domicilio'              => (string) ($o['domicilio'] ?? ''),
        'email'                  => (string) ($o['email'] ?? ''),
        'descripcion'            => (string) ($o['desc'] ?? 'Prueba de facturación electrónica'),
        'neto_gravado'           => $neto,
        'alicuota_iva'           => $alicuota,
        'no_gravado'             => '0',
        'exento'                 => '0',
        'total'                  => $total,
        'moneda'                 => 'PES',
        'cotizacion'             => '1',
        'serv_desde'             => $concepto === 1 ? '' : (string) ($o['serv-desde'] ?? date('Y-m-01')),
        'serv_hasta'             => $concepto === 1 ? '' : (string) ($o['serv-hasta'] ?? date('Y-m-t')),
        'vto_pago'               => $concepto === 1 ? '' : (string) ($o['vto-pago'] ?? date('Y-m-d')),
        'asoc_tipo'              => '',
        'asoc_pto_vta'           => '',
        'asoc_nro'               => '',
    ];
    $titulo = ($esC ? 'Factura C' : ($tipo === 1 ? 'Factura A' : 'Factura B')) . ' de prueba';
    $salidaPorDefecto = 'deploy/planilla-prueba.xlsx';
}

// -------------------------------------------------------------------- escribir
$salida = (string) ($o['salida'] ?? (App::raiz() . '/' . $salidaPorDefecto));
$dir = dirname($salida);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    morir("No se pudo crear {$dir}");
}

$libro = new Spreadsheet();
$libro->getProperties()->setTitle($titulo)->setCreator('Facturador ARCA');
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Facturas');

$hoja->fromArray(Importador::COLUMNAS, null, 'A1');
$valores = [];
foreach (Importador::COLUMNAS as $columna) {
    $valores[] = $fila[$columna] ?? '';
}
$hoja->fromArray($valores, null, 'A2');

// Las fechas y los importes como texto, para que Excel no los reinterprete.
foreach (['E', 'T', 'U', 'V'] as $letra) {
    $hoja->getStyle($letra . '2')->getNumberFormat()->setFormatCode('@');
}

$ultima = chr(ord('A') + count(Importador::COLUMNAS) - 1);
$hoja->getStyle('A1:' . $ultima . '1')->getFont()->setBold(true);
$hoja->getStyle('A1:' . $ultima . '1')->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF4');
$hoja->getStyle('A1:' . $ultima . '2')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$hoja->getStyle('A1:' . $ultima . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
foreach (range('A', $ultima) as $letra) {
    $hoja->getColumnDimension($letra)->setAutoSize(true);
}
$hoja->freezePane('A2');

(new Xlsx($libro))->save($salida);

// ---------------------------------------------------- validar lo que se escribió
$lectura = Importador::leer($salida, basename($salida));
$condiciones = [];
try {
    $condiciones = $sinEmisor ? [] : array_column((new \Facturador\Arca\Wsfe($emisor))->condicionesIvaReceptor(), 'id');
} catch (\Throwable) {
    // Sin conexión a ARCA no se valida contra la tabla; la app lo hace al subir.
}
$validadas = (new Validador($emisor, $condiciones, []))->validar($lectura['filas']);
$revisada = $validadas[0] ?? null;

echo str_repeat('-', 60), \PHP_EOL;
echo $titulo, \PHP_EOL;
echo str_repeat('-', 60), \PHP_EOL;
echo '  Archivo:     ', $salida, \PHP_EOL;
echo '  Referencia:  ', $fila['id_externo'], \PHP_EOL;
echo '  Tipo:        ', $fila['tipo_cbte'], ' · punto de venta ', $fila['punto_venta'], \PHP_EOL;
echo '  Fecha:       ', $fila['fecha'], \PHP_EOL;
echo '  Receptor:    doc ', $fila['doc_tipo'], ' ', $fila['doc_nro'] === '' ? '(sin número)' : $fila['doc_nro'],
     ' · condición IVA ', $fila['condicion_iva_receptor'], \PHP_EOL;
echo '  Descripción: ', $fila['descripcion'], \PHP_EOL;
echo '  Importes:    neto ', $fila['neto_gravado'],
     ($fila['alicuota_iva'] === '' ? ' (sin IVA discriminado)' : ' + IVA ' . $fila['alicuota_iva'] . '%'),
     ' = total ', $fila['total'], \PHP_EOL;
if (($fila['asoc_nro'] ?? '') !== '') {
    echo '  Asociado:    tipo ', $fila['asoc_tipo'], ' ',
        str_pad((string) $fila['asoc_pto_vta'], 4, '0', \STR_PAD_LEFT), '-',
        str_pad((string) $fila['asoc_nro'], 8, '0', \STR_PAD_LEFT), \PHP_EOL;
}
echo \PHP_EOL;

if ($revisada === null) {
    morir('Algo salió mal: la planilla quedó sin filas.');
}

if ($revisada['errores'] !== []) {
    echo 'LA PLANILLA NO PASA LA VALIDACIÓN:', \PHP_EOL;
    foreach ($revisada['errores'] as $error) {
        echo '  - ', $error, \PHP_EOL;
    }
    echo \PHP_EOL, 'Corregí los datos y volvé a generarla.', \PHP_EOL;
    exit(1);
}

echo 'Validada: pasa los mismos controles que al subirla.', \PHP_EOL;
foreach ($revisada['advertencias'] as $advertencia) {
    echo '  Ojo: ', $advertencia, \PHP_EOL;
}
if ($sinEmisor) {
    echo \PHP_EOL, 'Aviso: no hay emisor cargado en esta instalación, así que usé punto de venta ',
        $emisor['punto_venta'], ' y clase ', $emisor['condicion_iva'], '.', \PHP_EOL,
        'En el servidor volvé a generarla (toma el punto de venta y la clase del emisor real)',
        \PHP_EOL, 'o editá la columna punto_venta antes de subirla.', \PHP_EOL;
}
echo \PHP_EOL, 'Siguiente: subila en la app desde "Subir facturas".', \PHP_EOL;
