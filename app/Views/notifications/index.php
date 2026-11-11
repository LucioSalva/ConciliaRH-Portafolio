<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$noLeidas = count(array_filter($rows, static fn ($r) => $r['estado'] === 'NO_LEIDA'));
?>
<div class="page-header">
    <div>
        <h1>Notificaciones</h1>
        <p class="page-header__sub">Avisos para usted sobre cotejos, reportes, archivos cargados y fusiones. No son la bitácora de auditoría: puede marcarlos y borrarlos.</p>
    </div>
    <div class="page-header__actions">
        <form method="post" action="/notificaciones/leer-todas"><?= $this->csrf() ?>
            <button class="btn-action btn-view" type="submit"><span class="btn-action__label">Marcar todas como leídas</span><span class="btn-action__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></span></button></form>
        <form method="post" action="/notificaciones/eliminar-leidas" data-confirm="Se borrarán las notificaciones que ya leyó. La auditoría no se modifica." data-confirm-title="Eliminar notificaciones leídas"><?= $this->csrf() ?>
            <button class="btn-action btn-cancel" type="submit"><span class="btn-action__label">Eliminar leídas</span><span class="btn-action__icon"><i class="bi bi-trash3" aria-hidden="true"></i></span></button></form>
    </div>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/notificaciones" aria-label="Filtrar notificaciones">
        <div class="filter-bar">
            <div><label class="form-label" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <?php foreach (['' => 'Todas', 'NO_LEIDA' => 'Sin leer', 'LEIDA' => 'Leídas'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="tipo">Tipo</label>
                <select class="form-select form-select-sm" id="tipo" name="tipo" data-autosubmit><option value="">Todos</option>
                    <?php foreach ($tipos as $v => [, $area]): ?><option value="<?= e($v) ?>"<?= $filters['tipo'] === $v ? ' selected' : '' ?>><?= e($area . ' · ' . status_label($v)) ?></option><?php endforeach; ?>
                </select></div>
            <div class="filter-bar__actions"><button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button></div>
        </div>
        <?= $this->insert('partials/filter-status', ['filtros' => $filters, 'limpiar' => '/notificaciones']) ?>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-bell-slash" aria-hidden="true"></i></div>
            <p class="empty-state__title"><?= $filters['estado'] === 'NO_LEIDA' ? 'Está al día' : 'Sin notificaciones' ?></p>
            <p class="mb-0">Aquí aparecerán los avisos cuando termine una conciliación, un reporte programado esté listo o algo requiera su decisión.</p></div>
    <?php else: ?>
        <h2 class="visually-hidden">Lista de notificaciones</h2>
        <ul class="notif-list" aria-label="Notificaciones">
            <?php foreach ($rows as $n): ?>
                <li class="notif-item<?= $n['estado'] === 'NO_LEIDA' ? ' notif-item--unread' : '' ?><?= $n['prioridad'] === 'ALTA' ? ' notif-item--alta' : '' ?>">
                    <i class="bi <?= e($n['icono']) ?> notif-item__icon" aria-hidden="true"></i>
                    <div class="notif-item__body">
                        <p class="notif-item__title"><?= e($n['titulo']) ?><?php if ($n['estado'] === 'NO_LEIDA'): ?> <span class="visually-hidden">(sin leer)</span><?php endif; ?><?php if ($n['prioridad'] === 'ALTA'): ?> <?= status_badge('SEV_ALTA', 'Prioridad alta') ?><?php endif; ?></p>
                        <p class="notif-item__text"><?= e($n['mensaje']) ?></p>
                        <p class="notif-item__meta"><?= e($n['area']) ?> · <time datetime="<?= e($n['created_at']) ?>"><?= e(fmt_datetime($n['created_at'])) ?></time></p>
                    </div>
                    <div class="notif-item__actions">
                        <?php if ($n['url'] !== null): ?>
                            <form method="post" action="/notificaciones/<?= e($n['id']) ?>/abrir"><?= $this->csrf() ?>
                                <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Abrir</span><span class="btn-action__icon"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span></button></form>
                        <?php endif; ?>
                        <form method="post" action="/notificaciones/<?= e($n['id']) ?>/eliminar"><?= $this->csrf() ?>
                            <button class="btn btn-outline-secondary btn-icon" type="submit" aria-label="Eliminar notificación «<?= e($n['titulo']) ?>»"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
