<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header"><div><h1>Reportes generados</h1>
    <p class="page-header__sub">Se descargan sólo desde aquí, con sus permisos y verificando la huella SHA-256 del archivo. Al vencer la retención se borra el archivo y se conservan sus datos de control.</p></div>
    <div class="page-header__actions"><?php if ($admin): ?><a class="btn-action btn-view" href="/reportes/generados<?= $todos ? '' : '?todos=1' ?>"><span class="btn-action__label"><?= $todos ? 'Sólo los míos' : 'Ver todos' ?></span><span class="btn-action__icon"><i class="bi bi-people" aria-hidden="true"></i></span></a><?php endif; ?>
        <a class="btn-action btn-view" href="/reportes/programados"><span class="btn-action__label">Programaciones</span><span class="btn-action__icon"><i class="bi bi-calendar-check" aria-hidden="true"></i></span></a></div></div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/reportes/generados" aria-label="Filtrar reportes generados">
        <?php if ($todos): ?><input type="hidden" name="todos" value="1"><?php endif; ?>
        <div class="filter-bar">
            <div><label class="form-label small mb-1" for="g-tipo">Tipo</label><select class="form-select form-select-sm" id="g-tipo" name="tipo" data-autosubmit><option value="">Todos</option>
                <?php foreach ($tipos as $k => [$t]): ?><option value="<?= e($k) ?>"<?= $filters['tipo'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small mb-1" for="g-periodo">Periodo</label><select class="form-select form-select-sm" id="g-periodo" name="periodo" data-autosubmit><option value="">Todos</option>
                <?php foreach ($periodos as $p): ?><option value="<?= e($p['id']) ?>"<?= $filters['periodo'] === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['descripcion']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small mb-1" for="g-estado">Estado</label><select class="form-select form-select-sm" id="g-estado" name="estado" data-autosubmit><option value="">Todos</option>
                <?php foreach ($estados as $s): ?><option value="<?= e($s) ?>"<?= $filters['estado'] === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small mb-1" for="g-formato">Formato</label><select class="form-select form-select-sm" id="g-formato" name="formato" data-autosubmit><option value="">Todos</option>
                <?php foreach (['XLSX', 'CSV', 'PDF'] as $f): ?><option value="<?= e($f) ?>"<?= $filters['formato'] === $f ? ' selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small mb-1" for="g-desde">Desde</label><input class="form-control form-control-sm" type="date" id="g-desde" name="desde" value="<?= e($filters['desde']) ?>"></div>
            <div><label class="form-label small mb-1" for="g-hasta">Hasta</label><input class="form-control form-control-sm" type="date" id="g-hasta" name="hasta" value="<?= e($filters['hasta']) ?>"></div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-inbox" aria-hidden="true"></i></div><p class="empty-state__title">No hay reportes generados con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Reportes generados</caption>
        <thead><tr><th scope="col">#</th><th scope="col">Programación</th><th scope="col">Tipo · formato</th><th scope="col">Conciliación</th><th scope="col">Estado</th><th scope="col" class="col-hide-md">Generado · expira</th><th scope="col" class="cell-actions">Descarga</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
            <tr><td>#<?= e($r['id']) ?></td>
                <td><?= e($r['nombre']) ?><br><small class="text-muted"><?= e(status_label($r['origen'])) ?><?= $todos ? ' · ' . e($r['propietario']) : '' ?></small></td>
                <td class="small"><?= e($tipos[$r['tipo']][0] ?? $r['tipo']) ?> · <?= e($r['formato']) ?><?= $r['sensible'] ? ' ' . status_badge('SENSIBLE') : '' ?><?= !empty($r['cifrado']) ? ' ' . status_badge('CIFRADO') : '' ?></td>
                <td class="small"><?= $r['conciliacion_id'] ? '<a href="/conciliaciones/' . e($r['conciliacion_id']) . '">' . e($r['periodo'] . ' · lote ' . sprintf('%03d', $r['lote_numero']) . ' · v' . $r['conciliacion_numero']) . '</a>' : '—' ?></td>
                <td><?= status_badge($r['estado']) ?><?php if ($r['error_mensaje']): ?><br><small class="text-muted"><?= e($r['error_mensaje']) ?></small><?php endif; ?></td>
                <td class="small col-hide-md"><?= $r['generado_at'] ? e(fmt_datetime($r['generado_at'])) : '—' ?><br><?= $r['expira_at'] ? e(fmt_datetime($r['expira_at'])) : '' ?></td>
                <td class="cell-actions"><?php if ($r['estado'] === 'DISPONIBLE' && $puedeDescargar): ?>
                    <form method="post" action="/reportes/generados/<?= e($r['id']) ?>/descargar"><?= $this->csrf() ?>
                        <button class="btn-action btn-export btn-action--sm" type="submit" aria-label="Descargar reporte #<?= e($r['id']) ?> (<?= e(fmt_bytes($r['bytes'])) ?>)"><span class="btn-action__label"><?= e(fmt_bytes($r['bytes'])) ?></span><span class="btn-action__icon"><i class="bi bi-download" aria-hidden="true"></i></span></button></form>
                <?php else: ?><span class="diff--none">—</span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>
