<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div><h1>Incidencias de identidad</h1>
        <p class="page-header__sub">Conflictos, identificadores inválidos o duplicados y cambios de cuenta detectados al normalizar. No se asume fraude ni error.</p></div>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/incidencias-identidad" aria-label="Filtrar incidencias">
        <div class="filter-bar">
            <div><label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <?php foreach (['ABIERTA' => 'Abiertas', 'RESUELTA' => 'Resueltas', 'DESCARTADA' => 'Descartadas', 'TODAS' => 'Todas'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label small mb-1" for="tipo">Tipo</label>
                <select class="form-select form-select-sm" id="tipo" name="tipo" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($types as $t): ?><option value="<?= e($t['tipo']) ?>"<?= $filters['tipo'] === $t['tipo'] ? ' selected' : '' ?>><?= e(status_label((string) $t['tipo'])) ?> (<?= e($t['n']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label small mb-1" for="severidad">Severidad</label>
                <select class="form-select form-select-sm" id="severidad" name="severidad" data-autosubmit>
                    <option value="">Todas</option><?php foreach (['ALTA' => 'Alta', 'ADVERTENCIA' => 'Advertencia', 'INFO' => 'Informativa'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['severidad'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></div><p class="empty-state__title">Sin incidencias</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Incidencias de identidad</caption>
            <thead><tr><th scope="col">Tipo</th><th scope="col">Severidad</th><th scope="col">Registro / empleado</th><th scope="col" class="col-hide-sm">Detectada</th><th scope="col">Estado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
            <?php foreach ($rows as $i): ?>
                <tr><td><?= e(status_label((string) $i['tipo'])) ?></td><td><?= status_badge('SEV_' . $i['severidad']) ?></td>
                    <td class="small"><?= $i['registro_id'] ? '<a href="/asociaciones/' . e(strtolower((string) $i['registro_tipo'])) . '/' . e($i['registro_id']) . '">' . e($i['registro_tipo']) . ' #' . e($i['registro_id']) . '</a>' : '' ?>
                        <?= $i['empleado_id'] ? ' · <a href="/empleados/' . e($i['empleado_id']) . '">empleado ' . e($i['numero_empleado']) . '</a>' : '' ?>
                        <?= $i['archivo_id'] ? '<br><a class="text-muted" href="/importaciones/' . e($i['archivo_id']) . '">archivo #' . e($i['archivo_id']) . '</a>' : '' ?></td>
                    <td class="small col-hide-sm"><?= e(fmt_datetime($i['created_at'])) ?></td>
                    <td><?= status_badge((string) $i['estado']) ?><?= $i['resolucion'] ? '<br><small class="text-muted">' . e($i['resolucion']) . '</small>' : '' ?></td>
                    <td class="cell-actions"><?php if ($i['estado'] === 'ABIERTA' && $this->can('asociaciones.resolver')): ?>
                        <form method="post" action="/incidencias-identidad/<?= e($i['id']) ?>/cerrar" class="d-inline" data-confirm="La incidencia se marca como resuelta." data-confirm-title="Resolver incidencia" data-confirm-reason="motivo"><?= $this->csrf() ?><input type="hidden" name="estado" value="RESUELTA">
                            <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Resolver</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button></form>
                        <form method="post" action="/incidencias-identidad/<?= e($i['id']) ?>/cerrar" class="d-inline" data-confirm="La incidencia se descarta (no requiere acción)." data-confirm-title="Descartar incidencia" data-confirm-reason="motivo"><?= $this->csrf() ?><input type="hidden" name="estado" value="DESCARTADA">
                            <button class="btn-action btn-cancel btn-action--sm" type="submit"><span class="btn-action__label">Descartar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
