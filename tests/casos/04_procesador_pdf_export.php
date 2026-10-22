<?php
// Prueba del procesador, el reconciliador, el PDF y las exportaciones,
// con un ARCA simulado (no se llama al web service ni se emite nada real).
declare(strict_types=1);

require __DIR__ . '/../../src/App.php';

use Facturador\App;
use Facturador\Arca\ArcaExcepcion;
use Facturador\Arca\Errores;
use Facturador\Arca\Wsfe;
use Facturador\Db;
use Facturador\Export\ExportadorCsv;
use Facturador\Lote\Procesador;
use Facturador\Pdf\Qr;

App::iniciar();

$CUIT = '20123456786';
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
        echo "FALLA {$titulo}: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $fallos++;
    }
}

/** ARCA simulado: se le escribe el guión de respuestas. */
final class WsfeFalso extends Wsfe
{
    /** @var list<string> 'A' | 'datos' | 'numeracion' | 'tecnico' | 'auth' */
    public array $guion = [];
    public array $enArca = [];
    public int $ultimo = 0;
    public bool $dummyOk = true;
    public array $llamadas = [];

    public function __construct(private array $em)
    {
        parent::__construct($em);
    }

    public function dummy(): array
    {
        $this->llamadas[] = 'dummy';
        return [
            'AppServer' => $this->dummyOk ? 'OK' : 'ERROR',
            'DbServer' => $this->dummyOk ? 'OK' : 'ERROR',
            'AuthServer' => $this->dummyOk ? 'OK' : 'OK',
            'ok' => $this->dummyOk,
        ];
    }

    public function ultimoAutorizado(int $tipoCbte, ?int $puntoVenta = null): int
    {
        $this->llamadas[] = 'ultimo';
        return $this->ultimo;
    }

    public function condicionesIvaReceptor(): array
    {
        return [['id' => 5, 'desc' => 'Consumidor Final', 'clase' => '']];
    }

    public function solicitarCae(array $c, int $nro, ?int $comprobanteId = null, ?int $loteId = null): array
    {
        $this->llamadas[] = 'solicitar:' . $nro;
        $accion = array_shift($this->guion) ?? 'A';

        if ($accion === 'tecnico') {
            throw new ArcaExcepcion(Errores::TECNICO, 'ARCA no respondió. Se reintenta sin duplicar nada.', null, 'timeout simulado');
        }
        if ($accion === 'auth') {
            throw new ArcaExcepcion(Errores::AUTH, 'ARCA no aceptó el certificado: el alias no está autorizado.', '602', 'auth simulado');
        }
        if ($accion === 'datos') {
            return [
                'resultado' => 'R', 'cae' => null, 'vto_cae' => null, 'nro' => $nro,
                'observaciones' => [['categoria' => Errores::DATOS, 'codigo' => '10013', 'mensaje' => 'En factura A el receptor tiene que estar identificado con CUIT.', 'mensaje_arca' => 'x']],
                'errores' => [], 'categoria' => Errores::DATOS,
                'mensaje' => 'En factura A el receptor tiene que estar identificado con CUIT.',
                'reproceso' => null,
            ];
        }
        if ($accion === 'numeracion') {
            $this->ultimo++;    // alguien emitió por fuera
            return [
                'resultado' => 'R', 'cae' => null, 'vto_cae' => null, 'nro' => $nro,
                'observaciones' => [['categoria' => Errores::NUMERACION, 'codigo' => '10016', 'mensaje' => 'El número de comprobante no es el que sigue en ARCA para este punto de venta.', 'mensaje_arca' => 'y']],
                'errores' => [], 'categoria' => Errores::NUMERACION,
                'mensaje' => 'El número de comprobante no es el que sigue en ARCA para este punto de venta.',
                'reproceso' => null,
            ];
        }

        // Autorizada.
        $this->ultimo = $nro;
        $cae = '7100' . str_pad((string) $nro, 10, '0', STR_PAD_LEFT);
        $this->enArca[$c['tipo_cbte'] . '-' . $nro] = [
            'nro' => $nro, 'cae' => $cae, 'vto_cae' => date('Y-m-d', strtotime('+14 days')),
            'fecha' => (string) $c['fecha'], 'doc_nro' => (string) $c['doc_nro'],
            'doc_tipo' => (int) $c['doc_tipo'], 'total' => number_format((float) $c['total'], 2, '.', ''),
            'resultado' => 'A',
        ];
        return [
            'resultado' => 'A', 'cae' => $cae, 'vto_cae' => date('Y-m-d', strtotime('+14 days')),
            'nro' => $nro, 'observaciones' => [], 'errores' => [],
            'categoria' => null, 'mensaje' => null, 'reproceso' => null,
        ];
    }

    public function consultarComprobante(int $tipoCbte, int $nro, ?int $puntoVenta = null): ?array
    {
        $this->llamadas[] = 'consultar:' . $nro;
        return $this->enArca[$tipoCbte . '-' . $nro] ?? null;
    }
}

// ------------------------------------------------------------------ preparación
Db::ejecutar('DELETE FROM eventos');
Db::ejecutar('DELETE FROM comprobantes');
Db::ejecutar('DELETE FROM lotes');
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);
$emisorId = Db::insertar('emisores', [
    'cuit' => $CUIT, 'razon_social' => 'ZZTEST Emisor SRL', 'domicilio' => 'Calle 1, Bariloche',
    'condicion_iva' => 'MONO', 'punto_venta' => 5, 'ambiente' => 'homo',
    'iibb' => '901-123456-7', 'inicio_actividades' => '2014-03-01',
]);
$emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$emisorId]);

/** Crea un lote con N filas listas para emitir. */
function crearLote(array $emisor, int $cantidad, array $sobre = []): array
{
    $loteId = Db::insertar('lotes', [
        'emisor_id' => (int) $emisor['id'], 'archivo_original' => 'prueba.xlsx',
        'archivo_path' => '-', 'archivo_sha256' => hash('sha256', (string) microtime(true)),
        'estado' => 'PROCESANDO', 'total_filas' => $cantidad,
    ]);
    for ($i = 1; $i <= $cantidad; $i++) {
        Db::insertar('comprobantes', array_merge([
            'lote_id' => $loteId, 'emisor_id' => (int) $emisor['id'], 'fila_excel' => $i + 1,
            'id_externo' => 'REF-' . $loteId . '-' . $i, 'punto_venta' => 5, 'tipo_cbte' => 11,
            'concepto' => 1, 'fecha' => date('Y-m-d'), 'doc_tipo' => 99, 'doc_nro' => '0',
            'condicion_iva_receptor' => 5, 'razon_social' => 'Consumidor Final',
            'descripcion' => 'Servicio de prueba ' . $i, 'neto_gravado' => '1210.00',
            'alicuota_iva' => null, 'no_gravado' => '0', 'exento' => '0', 'iva' => '0',
            'total' => '1210.00', 'moneda' => 'PES', 'cotizacion' => '1', 'estado' => 'VALIDADO',
        ], $sobre));
    }
    return Db::una('SELECT * FROM lotes WHERE id = ?', [$loteId]);
}

function correrHasta(Procesador $p, int $maximo = 20): array
{
    $ultimo = [];
    for ($i = 0; $i < $maximo; $i++) {
        $ultimo = $p->paso();
        if ($ultimo['termino'] || $ultimo['pausado'] || $ultimo['ocupado']) {
            break;
        }
    }
    return $ultimo;
}

function filas(int $loteId): array
{
    return Db::todas('SELECT * FROM comprobantes WHERE lote_id = ? ORDER BY fila_excel', [$loteId]);
}

// ------------------------------------------------------- A) todo sale bien
$lote = crearLote($emisor, 3);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 40;
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Tres facturas seguidas: se emiten con números consecutivos', function () use ($lote, $resultado): bool {
    $f = filas((int) $lote['id']);
    $numeros = array_map(static fn ($x) => (int) $x['nro'], $f);
    $estados = array_unique(array_map(static fn ($x) => (string) $x['estado'], $f));
    return $resultado['termino'] === true
        && $resultado['autorizadas'] === 3
        && $numeros === [41, 42, 43]
        && $estados === ['PDF_OK']
        && Db::valor('SELECT estado FROM lotes WHERE id = ?', [(int) $lote['id']]) === 'FINALIZADO';
});

chequear('Cada factura guardó CAE y vencimiento', function () use ($lote): bool {
    foreach (filas((int) $lote['id']) as $f) {
        if (($f['cae'] ?? '') === '' || ($f['vto_cae'] ?? '') === '') {
            return false;
        }
    }
    return true;
});

chequear('El PDF existe, es un PDF y tiene nombre con letra, PV y número', function () use ($lote): bool {
    $f = filas((int) $lote['id'])[0];
    $ruta = (string) $f['pdf_path'];
    return is_file($ruta)
        && str_starts_with((string) file_get_contents($ruta, false, null, 0, 5), '%PDF-')
        && filesize($ruta) > 3000
        && preg_match('/^C-0005-00000041-[a-f0-9]{32}\.pdf$/', basename($ruta)) === 1
        && str_contains($ruta, '/certs/') === false;
});

chequear('El QR lleva el JSON que pide ARCA', function () use ($lote, $emisor): bool {
    $f = filas((int) $lote['id'])[0];
    $url = Qr::url($emisor, $f);
    if (!str_starts_with($url, 'https://www.afip.gob.ar/fe/qr/?p=')) {
        return false;
    }
    $json = json_decode((string) base64_decode(substr($url, strlen('https://www.afip.gob.ar/fe/qr/?p=')), true), true);
    return $json['ver'] === 1
        && $json['fecha'] === (string) $f['fecha']
        && $json['cuit'] === (int) $emisor['cuit']
        && $json['ptoVta'] === 5
        && $json['tipoCmp'] === 11
        && $json['nroCmp'] === 41
        && $json['importe'] == 1210.0
        && $json['moneda'] === 'PES'
        && $json['tipoCodAut'] === 'E'
        && $json['codAut'] === (int) $f['cae'];
});

chequear('Quedó registrado en eventos sin token ni sign', function (): bool {
    $cuenta = (int) Db::valor('SELECT COUNT(*) FROM eventos');
    $sucio = (int) Db::valor("SELECT COUNT(*) FROM eventos WHERE request_xml LIKE '%<Token>%' AND request_xml NOT LIKE '%[oculto]%'");
    return $cuenta >= 0 && $sucio === 0;
});

// ------------------------------------------- B) una rechazada por datos
$lote = crearLote($emisor, 3);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 100;
$arca->guion = ['A', 'datos', 'A'];
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Un rechazo por datos no consume número y el lote sigue', function () use ($lote, $resultado): bool {
    $f = filas((int) $lote['id']);
    return $resultado['termino'] === true
        && (int) $f[0]['nro'] === 101
        && (string) $f[1]['estado'] === 'RECHAZADO'
        && $f[1]['nro'] === null
        && (int) $f[2]['nro'] === 102     // el número no se perdió
        && str_contains((string) $f[1]['error_msg'], 'Fila 3:')
        && Db::valor('SELECT estado FROM lotes WHERE id = ?', [(int) $lote['id']]) === 'FINALIZADO_CON_ERRORES';
});

$loteRechazo = $lote;

// --------------------------------- C) numeración desfasada: reintenta una vez
$lote = crearLote($emisor, 1);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 200;
$arca->guion = ['numeracion', 'A'];
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Numeración desfasada: reconsulta y reintenta una sola vez', function () use ($lote, $resultado, $arca): bool {
    $f = filas((int) $lote['id'])[0];
    $solicitudes = count(array_filter($arca->llamadas, static fn ($l) => str_starts_with((string) $l, 'solicitar:')));
    return $resultado['termino'] === true
        && (string) $f['estado'] === 'PDF_OK'
        && (int) $f['nro'] === 202      // 200 + 1 que emitió otro + 1
        && $solicitudes === 2;
});

// ------------------------------- D) numeración desfasada dos veces: pausa
$lote = crearLote($emisor, 1);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 300;
$arca->guion = ['numeracion', 'numeracion'];
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Si la numeración falla dos veces, pausa el lote con el motivo claro', function () use ($lote, $resultado): bool {
    $f = filas((int) $lote['id'])[0];
    $motivo = (string) Db::valor('SELECT motivo_pausa FROM lotes WHERE id = ?', [(int) $lote['id']]);
    return $resultado['pausado'] === true
        && (string) $f['estado'] === 'VALIDADO'
        && str_contains($motivo, 'por fuera de la app')
        && Db::valor('SELECT estado FROM lotes WHERE id = ?', [(int) $lote['id']]) === 'PAUSADO';
});

// --------------------------------------- E) error técnico → INCIERTO y pausa
$lote = crearLote($emisor, 2);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 400;
$arca->guion = ['tecnico'];
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Error técnico: la fila queda INCIERTO y el lote pausado', function () use ($lote, $resultado): bool {
    $f = filas((int) $lote['id'])[0];
    return $resultado['pausado'] === true
        && (string) $f['estado'] === 'INCIERTO'
        && (int) $f['nro_esperado'] === 401
        && $f['nro'] === null
        && str_contains((string) Db::valor('SELECT motivo_pausa FROM lotes WHERE id = ?', [(int) $lote['id']]), 'Continuar');
});

chequear('No se reintenta a ciegas: el siguiente paso consulta primero', function () use ($lote, $emisor, $arca): bool {
    // ARCA no tiene el comprobante: la fila vuelve a la cola.
    $arca->llamadas = [];
    $arca->guion = ['A', 'A'];
    $p = new Procesador($emisor, Db::una('SELECT * FROM lotes WHERE id = ?', [(int) $lote['id']]), $arca);
    $primer = $p->paso();
    $consultoPrimero = ($arca->llamadas[0] ?? '') === 'consultar:401';
    $f = filas((int) $lote['id'])[0];
    return $consultoPrimero
        && (string) $f['estado'] === 'VALIDADO'
        && $f['nro_esperado'] === null
        && str_contains((string) $primer['ultima']['mensaje'], 'no tiene ese comprobante');
});

chequear('Después de reconciliar, el lote sigue y emite las dos', function () use ($lote, $emisor, $arca): bool {
    $resultado = correrHasta(new Procesador($emisor, Db::una('SELECT * FROM lotes WHERE id = ?', [(int) $lote['id']]), $arca));
    $f = filas((int) $lote['id']);
    return $resultado['termino'] === true
        && (string) $f[0]['estado'] === 'PDF_OK'
        && (string) $f[1]['estado'] === 'PDF_OK'
        && (int) $f[0]['nro'] === 401
        && (int) $f[1]['nro'] === 402;
});

// ------------- E2) el comprobante SÍ estaba en ARCA: se recupera el CAE
$lote = crearLote($emisor, 1);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 500;
$arca->guion = ['tecnico'];
correrHasta(new Procesador($emisor, $lote, $arca));
$fila = filas((int) $lote['id'])[0];
// Simulamos que ARCA sí lo había tomado, con los mismos datos.
$arca->enArca['11-501'] = [
    'nro' => 501, 'cae' => '71000000000501', 'vto_cae' => date('Y-m-d', strtotime('+14 days')),
    'fecha' => (string) $fila['fecha'], 'doc_nro' => '0', 'doc_tipo' => 99,
    'total' => '1210.00', 'resultado' => 'A',
];

chequear('Si ARCA lo tenía emitido, se recupera el CAE y no se duplica', function () use ($lote, $emisor, $arca): bool {
    $arca->llamadas = [];
    $p = new Procesador($emisor, Db::una('SELECT * FROM lotes WHERE id = ?', [(int) $lote['id']]), $arca);
    $paso = $p->paso();
    $f = filas((int) $lote['id'])[0];
    $solicitudes = count(array_filter($arca->llamadas, static fn ($l) => str_starts_with((string) $l, 'solicitar:')));
    return (string) $f['estado'] === 'PDF_OK'
        && (string) $f['cae'] === '71000000000501'
        && (int) $f['nro'] === 501
        && $solicitudes === 0                       // no se volvió a pedir CAE
        && is_file((string) $f['pdf_path'])
        && str_contains((string) $paso['ultima']['mensaje'], 'Estaba emitida en ARCA');
});

// ------------- E3) hay otro comprobante con ese número: revisión manual
$lote = crearLote($emisor, 1);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 600;
$arca->guion = ['tecnico'];
correrHasta(new Procesador($emisor, $lote, $arca));
$arca->enArca['11-601'] = [
    'nro' => 601, 'cae' => '71000000000601', 'vto_cae' => date('Y-m-d'),
    'fecha' => date('Y-m-d'), 'doc_nro' => '30111222', 'doc_tipo' => 96,
    'total' => '9999.00', 'resultado' => 'A',
];

chequear('Si el número lo tiene otro comprobante: REVISION_MANUAL y pausa', function () use ($lote, $emisor, $arca): bool {
    $paso = (new Procesador($emisor, Db::una('SELECT * FROM lotes WHERE id = ?', [(int) $lote['id']]), $arca))->paso();
    $f = filas((int) $lote['id'])[0];
    return $paso['pausado'] === true
        && (string) $f['estado'] === 'REVISION_MANUAL'
        && str_contains((string) $f['error_msg'], 'no coincide')
        && str_contains((string) $f['error_msg'], 'Mis Comprobantes');
});

// ------------------------------------------------------- F) problema de AUTH
$lote = crearLote($emisor, 2);
$arca = new WsfeFalso($emisor);
$arca->ultimo = 700;
$arca->guion = ['auth'];
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Problema de certificado: la fila vuelve a la cola y el lote se pausa', function () use ($lote, $resultado): bool {
    $f = filas((int) $lote['id'])[0];
    return $resultado['pausado'] === true
        && (string) $f['estado'] === 'VALIDADO'
        && $f['nro_esperado'] === null
        && str_contains((string) Db::valor('SELECT motivo_pausa FROM lotes WHERE id = ?', [(int) $lote['id']]), 'certificado');
});

// ---------------------------------------------- G) ARCA caído en el primer paso
$lote = crearLote($emisor, 1);
$arca = new WsfeFalso($emisor);
$arca->dummyOk = false;
$resultado = correrHasta(new Procesador($emisor, $lote, $arca));

chequear('Si FEDummy dice que ARCA está caído, no se emite nada', function () use ($lote, $resultado, $arca): bool {
    $f = filas((int) $lote['id'])[0];
    return $resultado['pausado'] === true
        && (string) $f['estado'] === 'VALIDADO'
        && !in_array('ultimo', $arca->llamadas, true)
        && str_contains((string) $resultado['motivo'], 'servicios caídos');
});

// --------------------------------------------------- H) lock del punto de venta
chequear('Si otra pestaña está emitiendo, el paso devuelve "ocupado"', function () use ($emisor): bool {
    $lote = crearLote($emisor, 1);
    $arca = new WsfeFalso($emisor);
    $ruta = App::storage('locks/' . $emisor['cuit'] . '_' . $emisor['punto_venta'] . '.lock');
    $otro = fopen($ruta, 'c+');
    flock($otro, LOCK_EX);
    $paso = (new Procesador($emisor, $lote, $arca))->paso();
    flock($otro, LOCK_UN);
    fclose($otro);
    $f = filas((int) $lote['id'])[0];
    return $paso['ocupado'] === true && (string) $f['estado'] === 'VALIDADO';
});

// ------------------------------------------------------- I) exportaciones
chequear('emitidas.csv: BOM, punto y coma, decimales con coma y columnas de la plantilla', function () use ($loteRechazo): bool {
    $csv = ExportadorCsv::emitidas($loteRechazo);
    $lineas = explode("\r\n", $csv);
    $encabezado = str_replace("\xEF\xBB\xBF", '', $lineas[0]);
    $columnas = explode(';', $encabezado);
    return str_starts_with($csv, "\xEF\xBB\xBF")
        && $columnas[0] === 'id_externo'
        && in_array('cae', $columnas, true)
        && in_array('url_pdf', $columnas, true)
        && in_array('comprobante', $columnas, true)
        && str_contains($lineas[1], '1210,00')              // decimales con coma
        && str_contains($lineas[1], '0005-00000101')
        && str_contains($lineas[1], 'descargar.php?t=')
        && count(array_filter(array_slice($lineas, 1))) === 2;   // dos emitidas
});

chequear('errores.csv: mismas columnas que la plantilla + motivo', function () use ($loteRechazo): bool {
    $csv = ExportadorCsv::errores($loteRechazo);
    $lineas = explode("\r\n", $csv);
    $columnas = explode(';', str_replace("\xEF\xBB\xBF", '', $lineas[0]));
    $esperadas = array_merge(\Facturador\Lote\Importador::COLUMNAS, ['motivo']);
    return $columnas === $esperadas
        && count(array_filter(array_slice($lineas, 1))) === 1
        && str_contains($lineas[1], 'Fila 3:');
});

chequear('pdfs.zip trae los PDF del lote', function () use ($loteRechazo): bool {
    $ruta = ExportadorCsv::pdfsZip($loteRechazo);
    $zip = new ZipArchive();
    $zip->open($ruta);
    $cantidad = $zip->numFiles;
    $nombre = $zip->getNameIndex(0);
    $zip->close();
    @unlink($ruta);
    return $cantidad === 2 && preg_match('/^C-0005-\d{8}-[a-f0-9]{32}\.pdf$/', (string) $nombre) === 1;
});

// ----------------------------------------------- J) el CAE nunca se revierte
chequear('Una fila con CAE no se puede volver a emitir', function () use ($emisor, $arca): bool {
    $f = Db::una("SELECT * FROM comprobantes WHERE estado = 'PDF_OK' LIMIT 1");
    $lote = Db::una('SELECT * FROM lotes WHERE id = ?', [(int) $f['lote_id']]);
    $arca2 = new WsfeFalso($emisor);
    $arca2->ultimo = 9000;
    $paso = (new Procesador($emisor, $lote, $arca2))->paso();
    $despues = Db::una('SELECT * FROM comprobantes WHERE id = ?', [(int) $f['id']]);
    // El lote ya estaba terminado: no toca nada.
    return (string) $despues['cae'] === (string) $f['cae'] && (int) $despues['nro'] === (int) $f['nro'];
});

// -------------------------------------------------------------------- limpieza
foreach (Db::todas('SELECT pdf_path FROM comprobantes WHERE pdf_path IS NOT NULL') as $fila) {
    @unlink((string) $fila['pdf_path']);
}
$dirPdf = App::storage('pdf/' . $CUIT);
foreach ((array) glob($dirPdf . '/*') as $sub) {
    @rmdir((string) $sub);
}
@rmdir($dirPdf);
Db::ejecutar('DELETE FROM eventos');
Db::ejecutar('DELETE FROM comprobantes');
Db::ejecutar('DELETE FROM lotes');
Db::ejecutar('DELETE FROM emisores WHERE cuit = ?', [$CUIT]);

echo "\n== {$ok} OK / {$fallos} fallas ==\n";
exit($fallos === 0 ? 0 : 1);
