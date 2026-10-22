<?php
/**
 * Comprobante para dompdf. A4, original.
 *
 * @var array       $emisor
 * @var array       $c          fila de comprobantes (autorizada)
 * @var string      $letra      A | B | C
 * @var string      $nombre     FACTURA | NOTA DE CRÉDITO | NOTA DE DÉBITO
 * @var int         $codigo     código de tipo de comprobante de ARCA
 * @var string      $numero     0005-00000123
 * @var string      $qr         data URI del PNG del QR
 * @var string|null $logo       data URI del logo
 * @var string      $condicion  condición de IVA del receptor
 * @var string      $condEmisor condición de IVA del emisor
 */

declare(strict_types=1);

use Facturador\App;
use Facturador\Pdf\GeneradorPdf;

$esC = $letra === 'C';
$discrimina = $letra === 'A';
$fecha = static fn (?string $f): string => $f === null || $f === '' ? '' : date('d/m/Y', (int) strtotime($f));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        .marco { border: 1px solid #000; }
        .cabecera td { vertical-align: top; width: 50%; padding: 8px 10px; }
        .letra { text-align: center; border-left: 1px solid #000; border-right: 1px solid #000; }
        .letra .caja { font-size: 30pt; font-weight: bold; line-height: 1; }
        .letra .cod { font-size: 7.5pt; }
        .emisor .nombre { font-size: 14pt; font-weight: bold; }
        .titulo { font-size: 13pt; font-weight: bold; }
        .fila-datos td { padding: 2px 0; }
        .seccion { border: 1px solid #000; border-top: 0; padding: 6px 10px; }
        .items { margin-top: 8px; }
        .items th { background: #eee; border: 1px solid #000; padding: 5px; font-size: 8.5pt; text-align: left; }
        .items td { border-left: 1px solid #000; border-right: 1px solid #000; padding: 6px 5px; vertical-align: top; }
        .items tr.ultima td { border-bottom: 1px solid #000; }
        .der { text-align: right; }
        .totales { margin-top: 10px; }
        .totales td { padding: 3px 6px; }
        .totales .etiqueta { text-align: right; }
        .totales .valor { text-align: right; width: 110px; border-bottom: 1px solid #ccc; }
        .totales .total td { font-size: 12pt; font-weight: bold; border-top: 1px solid #000; }
        .pie { margin-top: 12px; }
        .pie td { vertical-align: bottom; }
        .cae { font-size: 11pt; font-weight: bold; }
        .chico { font-size: 8pt; color: #333; }
        .obs { margin-top: 8px; font-size: 8pt; }
    </style>
</head>
<body>

<table class="marco cabecera">
    <tr>
        <td class="emisor">
            <?php if ($logo !== null): ?>
                <img src="<?= $logo ?>" style="max-height: 55px; max-width: 200px;"><br>
            <?php endif; ?>
            <span class="nombre"><?= App::esc($emisor['razon_social']) ?></span><br>
            <?= App::esc($emisor['domicilio']) ?><br>
            <?= App::esc($condEmisor) ?>
        </td>
        <td class="letra" style="width: 60px;">
            <div class="caja"><?= App::esc($letra) ?></div>
            <div class="cod">COD. <?= str_pad((string) $codigo, 2, '0', \STR_PAD_LEFT) ?></div>
        </td>
        <td>
            <span class="titulo"><?= App::esc($nombre) ?></span><br>
            <table class="fila-datos">
                <tr><td style="width: 95px;">Nº</td><td><strong><?= App::esc($numero) ?></strong></td></tr>
                <tr><td>Fecha de emisión</td><td><?= $fecha($c['fecha']) ?></td></tr>
                <tr><td>CUIT</td><td><?= App::esc($emisor['cuit']) ?></td></tr>
                <?php if (($emisor['iibb'] ?? '') !== ''): ?>
                    <tr><td>Ingresos Brutos</td><td><?= App::esc($emisor['iibb']) ?></td></tr>
                <?php endif; ?>
                <?php if (($emisor['inicio_actividades'] ?? '') !== ''): ?>
                    <tr><td>Inicio actividades</td><td><?= $fecha($emisor['inicio_actividades']) ?></td></tr>
                <?php endif; ?>
            </table>
        </td>
    </tr>
</table>

<div class="seccion">
    <table class="fila-datos">
        <tr>
            <td style="width: 60%;">
                <strong><?= App::esc($c['razon_social'] ?? 'Consumidor Final') ?></strong><br>
                <?php
                $tiposDoc = [80 => 'CUIT', 96 => 'DNI', 99 => 'Doc.'];
                $tipoDoc = $tiposDoc[(int) $c['doc_tipo']] ?? 'Doc.';
                ?>
                <?php if ((int) $c['doc_tipo'] !== 99): ?>
                    <?= App::esc($tipoDoc) ?>: <?= App::esc($c['doc_nro']) ?><br>
                <?php endif; ?>
                <?php if (($c['domicilio'] ?? '') !== ''): ?>
                    <?= App::esc($c['domicilio']) ?><br>
                <?php endif; ?>
                <?php if ($condicion !== ''): ?>
                    Condición frente al IVA: <?= App::esc($condicion) ?>
                <?php endif; ?>
            </td>
            <td class="chico">
                <?php if ((int) $c['concepto'] !== 1): ?>
                    Período facturado: <?= $fecha($c['serv_desde']) ?> al <?= $fecha($c['serv_hasta']) ?><br>
                    Vencimiento del pago: <?= $fecha($c['vto_pago']) ?><br>
                <?php endif; ?>
                <?php if ((string) ($c['moneda'] ?? 'PES') !== 'PES'): ?>
                    Moneda: <?= App::esc($c['moneda']) ?> (cotización <?= GeneradorPdf::plata($c['cotizacion']) ?>)<br>
                <?php endif; ?>
                <?php if (($c['asoc_nro'] ?? null) !== null): ?>
                    Comprobante asociado: tipo <?= (int) $c['asoc_tipo'] ?>
                    <?= str_pad((string) $c['asoc_pto_vta'], 4, '0', \STR_PAD_LEFT) ?>-<?= str_pad((string) $c['asoc_nro'], 8, '0', \STR_PAD_LEFT) ?><br>
                <?php endif; ?>
                Referencia: <?= App::esc($c['id_externo']) ?>
            </td>
        </tr>
    </table>
</div>

<table class="items">
    <thead>
    <tr>
        <th>Descripción</th>
        <?php if ($discrimina): ?>
            <th class="der" style="width: 90px;">Neto</th>
            <th class="der" style="width: 60px;">IVA</th>
        <?php endif; ?>
        <th class="der" style="width: 110px;">Importe</th>
    </tr>
    </thead>
    <tbody>
    <tr class="ultima">
        <td><?= nl2br(App::esc($c['descripcion'])) ?></td>
        <?php if ($discrimina): ?>
            <td class="der"><?= GeneradorPdf::plata($c['neto_gravado']) ?></td>
            <td class="der"><?= App::esc((string) $c['alicuota_iva']) ?>%</td>
        <?php endif; ?>
        <td class="der"><?= GeneradorPdf::plata($c['total']) ?></td>
    </tr>
    </tbody>
</table>

<table class="totales">
    <?php if ($discrimina): ?>
        <tr>
            <td class="etiqueta">Neto gravado</td>
            <td class="valor"><?= GeneradorPdf::plata($c['neto_gravado']) ?></td>
        </tr>
        <?php if ((float) ($c['no_gravado'] ?? 0) > 0): ?>
            <tr><td class="etiqueta">No gravado</td><td class="valor"><?= GeneradorPdf::plata($c['no_gravado']) ?></td></tr>
        <?php endif; ?>
        <?php if ((float) ($c['exento'] ?? 0) > 0): ?>
            <tr><td class="etiqueta">Exento</td><td class="valor"><?= GeneradorPdf::plata($c['exento']) ?></td></tr>
        <?php endif; ?>
        <tr>
            <td class="etiqueta">IVA <?= App::esc((string) $c['alicuota_iva']) ?>%</td>
            <td class="valor"><?= GeneradorPdf::plata($c['iva']) ?></td>
        </tr>
    <?php endif; ?>
    <tr class="total">
        <td class="etiqueta">Total</td>
        <td class="valor"><?= App::esc((string) ($c['moneda'] ?? 'PES')) === 'PES' ? '$' : App::esc((string) $c['moneda']) ?> <?= GeneradorPdf::plata($c['total']) ?></td>
    </tr>
</table>

<?php if (!$discrimina && !$esC): ?>
    <p class="chico">El IVA discriminado no se expone en comprobantes clase B (RG 1415).</p>
<?php endif; ?>

<table class="pie">
    <tr>
        <td style="width: 150px;">
            <img src="<?= $qr ?>" style="width: 110px; height: 110px;">
        </td>
        <td>
            <span class="chico">Comprobante autorizado por ARCA</span><br>
            <span class="cae">CAE Nº <?= App::esc((string) $c['cae']) ?></span><br>
            Fecha de vencimiento del CAE: <?= $fecha($c['vto_cae']) ?>
        </td>
    </tr>
</table>

<?php
$observaciones = [];
if (($c['observaciones_arca'] ?? null) !== null) {
    $json = json_decode((string) $c['observaciones_arca'], true);
    if (is_array($json)) {
        foreach ($json as $item) {
            if (is_array($item) && isset($item['mensaje_arca'])) {
                $observaciones[] = (string) $item['mensaje_arca'];
            }
        }
    }
}
?>
<?php if ($observaciones !== []): ?>
    <div class="obs">
        <strong>Observaciones de ARCA:</strong>
        <?= App::esc(implode(' · ', $observaciones)) ?>
    </div>
<?php endif; ?>

</body>
</html>
