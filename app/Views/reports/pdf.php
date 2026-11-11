<?php
/**
 * Plantilla PDF (dompdf). TODO valor se escapa con e(); sin recursos externos: el logo va embebido (data:),
 * los estilos en línea en <style>. dompdf corre sin red, sin PHP, sin JS y sin acceso a archivos.
 * @var array<string,mixed> $r @var string $usuario @var string $fecha @var string $logo @var string $sello
 */
$c = $r['conciliacion'];
$fmt = static function (mixed $v, string $type): string {
    if ($v === null || $v === '') {
        return '';
    }
    return match ($type) {
        'money' => '$' . fmt_decimal($v),
        'int' => number_format((int) $v),
        default => (string) $v,
    };
};
$selloClase = $c['lote_oficial'] ? ($c['estado'] === 'CERRADA' ? 'sello--cerrada' : 'sello--revision') : 'sello--nooficial';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><title><?= e($r['titulo']) ?></title>
<style>
@page { margin: 72px 36px 44px 36px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #222; }
header { position: fixed; top: -60px; left: 0; right: 0; height: 54px; border-bottom: 2px solid #BC955C; }
header img { height: 44px; float: left; margin-right: 10px; }
header .t1 { font-size: 12px; font-weight: bold; color: #691C32; }
header .t2 { font-size: 8px; color: #555; }
.sello { position: absolute; right: 0; top: 6px; padding: 4px 10px; font-weight: bold; font-size: 10px; border: 2px solid; border-radius: 4px; }
.sello--cerrada { color: #2e6b3a; border-color: #2e6b3a; }
.sello--revision { color: #8a5a00; border-color: #BC955C; background: #fbf4dc; }
.sello--nooficial { color: #fff; border-color: #691C32; background: #691C32; }
h1 { font-size: 15px; color: #691C32; margin: 0 0 4px 0; }
.meta { font-size: 8px; color: #444; margin-bottom: 8px; }
.meta td { padding: 1px 8px 1px 0; }
h2 { font-size: 11px; color: #691C32; border-bottom: 1px solid #ECD798; padding-bottom: 2px; margin: 12px 0 4px 0; }
table.d { width: 100%; border-collapse: collapse; }
table.d th { background: #691C32; color: #fff; text-align: left; padding: 3px 4px; font-size: 7.5px; }
table.d td { padding: 2px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
table.d td.n { text-align: right; white-space: nowrap; }
table.d tr.tot td { background: #ECD798; font-weight: bold; }
.nota { font-size: 7.5px; color: #555; margin: 2px 0 4px 0; }
.aviso { border: 1px solid #691C32; background: #f7eef0; color: #691C32; padding: 5px 8px; margin-bottom: 8px; font-weight: bold; }
.firma { font-size: 7px; color: #666; margin-top: 12px; border-top: 1px solid #ddd; padding-top: 4px; word-break: break-all; }
</style></head>
<body>
<header>
    <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="ConciliaRH"><?php endif; ?>
    <div class="t1">ConciliaRH</div>
    <div class="t2">Sistema Integral de Conciliación de Nómina y Pagos<br>Tesorería Municipal</div>
    <div class="sello <?= e($selloClase) ?>"><?= e($sello) ?></div>
</header>
<h1><?= e($r['titulo']) ?></h1>
<?php if (!$c['lote_oficial']): ?><div class="aviso">LOTE NO DECLARADO OFICIAL: este reporte no debe interpretarse como resultado definitivo del periodo.</div><?php endif; ?>
<table class="meta"><tr><td><strong>Periodo:</strong> <?= e($c['periodo']) ?></td><td><strong>Lote:</strong> <?= e(sprintf('%03d', $c['lote'])) ?><?= $c['lote_oficial'] ? ' (oficial)' : ' (no oficial)' ?></td>
    <td><strong>Conciliación:</strong> #<?= e($c['id']) ?> · ejecución v<?= e($c['numero']) ?></td><td><strong>Estado:</strong> <?= e(status_label((string) $c['estado'])) ?></td></tr>
    <tr><td><strong>Motor:</strong> v<?= e($c['motor_version']) ?></td><td><strong>Tolerancia:</strong> $<?= e(fmt_decimal($c['tolerancia'])) ?></td>
    <td><strong>Generado:</strong> <?= e($fecha) ?></td><td><strong>Por:</strong> <?= e($usuario) ?></td></tr></table>
<p class="nota"><?= e($r['descripcion']) ?> Los importes provienen de los resultados persistidos del motor de conciliación; el reporte describe hechos y no califica conductas.</p>
<?php foreach ($r['secciones'] as $s): ?>
    <h2><?= e($s['titulo']) ?></h2>
    <?php if ($s['nota']): ?><p class="nota"><?= e($s['nota']) ?></p><?php endif; ?>
    <?php if ($s['filas'] === []): ?><p class="nota">Sin registros.</p><?php else: ?>
    <table class="d"><thead><tr><?php foreach ($s['columnas'] as [$l, $t]): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_slice($s['filas'], 0, 400) as $f): ?><tr><?php foreach ($s['columnas'] as $i => [, $t]): ?><td<?= in_array($t, ['money', 'int'], true) ? ' class="n"' : '' ?>><?= e($fmt($f[$i] ?? null, $t)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if ($s['totales'] !== null): ?><tr class="tot"><?php foreach ($s['columnas'] as $i => [, $t]): ?><td<?= in_array($t, ['money', 'int'], true) ? ' class="n"' : '' ?>><?= e($fmt($s['totales'][$i] ?? null, $t)) ?></td><?php endforeach; ?></tr><?php endif; ?>
    </tbody></table>
    <?php if (count($s['filas']) > 400): ?><p class="nota">El PDF muestra las primeras 400 de <?= e(count($s['filas'])) ?> filas; el detalle completo está en XLSX/CSV.</p><?php endif; ?>
    <?php endif; ?>
<?php endforeach; ?>
<div class="firma">Huella SHA-256 del contenido del reporte: <?= e($r['huella']) ?> · huella de entradas de la conciliación: <?= e((string) $c['huella_entradas']) ?> ·
    Regenerar el mismo reporte de la misma ejecución produce la misma huella de contenido.</div>
</body></html>
