<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$c = $r['conciliacion'];
// Celda HTML ya escapada: el dinero pasa por money() (signo «−», sin float), el resto por e()
$fmt = static fn (mixed $v, string $t): string => $v === null || $v === '' ? '' : match ($t) { 'money' => money($v), 'int' => e(number_format((int) $v)), default => e((string) $v) };
$sello = \ConciliaRH\Domain\Reports\ReportService::seal($c);
?>
<div class="page-header"><div><h1><?= e($r['titulo']) ?></h1>
    <p class="page-header__sub"><?= e($c['periodo']) ?> · cotejo n.º <?= e($c['numero']) ?> · <?= status_badge((string) $c['estado']) ?>
        <?= status_badge($c['lote_oficial'] ? 'OFICIAL' : 'NO_OFICIAL', $sello) ?></p></div>
    <div class="page-header__actions"><a class="btn-action btn-view" href="/conciliaciones/<?= e($c['id']) ?>"><span class="btn-action__label">Ver cotejo</span><span class="btn-action__icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></span></a></div></div>
<?php if (!$c['lote_oficial']): ?><div class="notice notice--warning" role="note"><i class="bi bi-exclamation-triangle notice__icon" aria-hidden="true"></i><div class="notice__body">Resultado provisional: el cotejo no es todavía el oficial de la quincena.</div></div><?php endif; ?>
<?php if ($this->can('reportes.exportar')): ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <?php foreach (['xlsx' => 'XLSX', 'csv' => 'CSV', 'pdf' => 'PDF'] as $f => $l): if ($f === 'pdf' && !$r['pdf']) continue; ?>
        <form method="post" action="/reportes/exportar" class="d-flex gap-2 align-items-center" data-confirm="Se generará el archivo <?= e($l) ?> y la descarga quedará registrada en auditoría." data-confirm-title="Exportar <?= e($l) ?>">
            <?= $this->csrf() ?><input type="hidden" name="tipo" value="<?= e($r['tipo']) ?>"><input type="hidden" name="conciliacion" value="<?= e($c['id']) ?>"><input type="hidden" name="formato" value="<?= e($f) ?>">
            <?php if ($otra !== null): ?><input type="hidden" name="otra" value="<?= e($otra) ?>"><?php endif; ?>
            <?php if ($puedeSensible && $f !== 'pdf' && in_array($r['tipo'], ['empleados', 'diferencias', 'no_pagados', 'pagados_mas', 'pagados_menos'], true)): ?>
                <div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="sensible" value="1" id="sens-<?= e($f) ?>"><label class="form-check-label" for="sens-<?= e($f) ?>">RFC/CURP</label></div>
            <?php endif; ?>
            <button class="btn-action btn-export btn-action--sm" type="submit"><span class="btn-action__label"><?= e($l) ?></span><span class="btn-action__icon"><i class="bi bi-download" aria-hidden="true"></i></span></button>
        </form>
    <?php endforeach; ?>
    <span class="small text-muted ms-auto">Huella del contenido: <code><?= e(substr($r['huella'], 0, 16)) ?>…</code> · <?= e($r['filas']) ?> fila(s)</span>
</div></div>
<?php endif; ?>
<p class="small text-muted"><?= e($r['descripcion']) ?></p>
<?php foreach ($r['secciones'] as $s): ?>
<div class="table-card mb-3">
    <div class="table-card__toolbar"><h2 class="table-card__title"><?= e($s['titulo']) ?></h2><span class="table-card__hint"><?= e(count($s['filas'])) ?> fila(s)<?= count($s['filas']) > 300 ? ' · se muestran 300' : '' ?></span></div>
    <?php if ($s['nota']): ?><p class="small text-muted px-3 pt-2 mb-0"><?= e($s['nota']) ?></p><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle table-sticky-first"><caption class="visually-hidden"><?= e($s['titulo']) ?></caption>
        <thead><tr><?php foreach ($s['columnas'] as [$l, $t]): ?><th scope="col"<?= in_array($t, ['money', 'int'], true) ? ' class="num"' : '' ?>><?= e($l) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach (array_slice($s['filas'], 0, 300) as $f): ?><tr><?php foreach ($s['columnas'] as $i => [, $t]): ?><td<?= in_array($t, ['money', 'int'], true) ? ' class="num"' : '' ?>><?= $fmt($f[$i] ?? null, $t) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody><?php if ($s['totales'] !== null): ?><tfoot><tr><?php foreach ($s['columnas'] as $i => [, $t]): ?><td<?= in_array($t, ['money', 'int'], true) ? ' class="num"' : '' ?>><?= $fmt($s['totales'][$i] ?? null, $t) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?></table></div>
</div>
<?php endforeach; ?>
