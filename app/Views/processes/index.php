<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$states = ['PENDIENTE', 'EN_EJECUCION', 'COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS', 'ERROR', 'CANCELADO'];
?>
<div class="page-header">
    <div>
        <h1>Procesos en segundo plano</h1>
        <p class="page-header__sub">Trabajos que el procesador en segundo plano toma de PostgreSQL. La aplicación web nunca ejecuta procesos del sistema.</p>
    </div>
    <?php if ($this->can('procesos.diagnostico')): ?>
        <form method="post" action="/procesos/diagnostico" class="page-header__actions" data-busy>
            <?= $this->csrf() ?>
            <button class="btn-action btn-reconcile" type="submit"><span class="btn-action__label">Diagnóstico del motor</span><span class="btn-action__icon"><i class="bi bi-activity" aria-hidden="true"></i></span></button>
        </form>
    <?php endif; ?>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/procesos" aria-label="Filtrar procesos">
        <div class="filter-bar">
            <div>
                <label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($states as $s): ?><option value="<?= e($s) ?>"<?= $filters['estado'] === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="tipo">Tipo</label>
                <select class="form-select form-select-sm" id="tipo" name="tipo" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $filters['tipo'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-cpu" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay procesos registrados</p>
            <p class="mb-0">Las importaciones y conciliaciones aparecerán aquí cuando se habiliten.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <caption class="visually-hidden">Procesos en segundo plano (el detalle de cada uno está en «Ver»)</caption>
                <thead><tr><th scope="col" class="num">#</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col" class="col-hide-sm">Progreso</th><th scope="col" class="col-hide-md num">Intentos</th><th scope="col" class="col-hide-md">Creado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Detalle</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td class="num"><?= e($p['id']) ?></td>
                        <td><code><?= e($p['tipo']) ?></code></td>
                        <td><?= status_badge((string) $p['estado']) ?><?php if ($p['etapa']): ?> <small class="text-muted"><?= e($p['etapa']) ?></small><?php endif; ?></td>
                        <td class="col-hide-sm">
                            <div class="progress" role="progressbar" aria-label="Progreso del proceso <?= e($p['id']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($p['progreso']) ?>">
                                <div class="progress-bar js-progress-bar" data-progress="<?= e($p['progreso']) ?>"></div>
                            </div>
                        </td>
                        <td class="col-hide-md num"><?= e($p['intentos']) ?>/<?= e($p['max_intentos']) ?></td>
                        <td class="col-hide-md nowrap"><?= e(fmt_datetime($p['created_at'])) ?><br><small class="text-muted"><?= e($p['creado_por_username'] ?? 'sistema') ?></small></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/procesos/<?= e($p['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
