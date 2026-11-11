<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div>
        <h1>Auditoría</h1>
        <p class="page-header__sub">Bitácora inmutable (sólo inserción) encadenada por hash. Nadie, ni siquiera un administrador, puede editarla o borrarla desde el sistema.</p>
    </div>
    <?php if ($this->can('auditoria.verificar')): ?>
        <form method="post" action="/auditoria/verificar" class="page-header__actions" data-busy>
            <?= $this->csrf() ?>
            <button class="btn-action btn-view" type="submit"><span class="btn-action__label">Verificar integridad</span><span class="btn-action__icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span></button>
        </form>
    <?php endif; ?>
</div>

<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/auditoria" role="search" aria-label="Filtrar auditoría">
        <div class="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por ID de entidad o contenido</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="ID de entidad o texto…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar">
            <div>
                <label for="accion" class="form-label small mb-1">Acción</label>
                <select id="accion" name="accion" class="form-select form-select-sm" data-autosubmit>
                    <option value="">Todas</option>
                    <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $filters['accion'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="usuario" class="form-label small mb-1">Usuario</label>
                <input id="usuario" name="usuario" class="form-control form-control-sm" value="<?= e($filters['usuario']) ?>" maxlength="50" autocomplete="off" spellcheck="false">
            </div>
            <div>
                <label for="modulo" class="form-label small mb-1">Módulo</label>
                <select id="modulo" name="modulo" class="form-select form-select-sm" data-autosubmit><option value="">Todos</option>
                    <?php foreach ($modules as $m): ?><option value="<?= e($m) ?>"<?= $filters['modulo'] === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label for="entidad" class="form-label small mb-1">Entidad</label>
                <select id="entidad" name="entidad" class="form-select form-select-sm" data-autosubmit><option value="">Todas</option>
                    <?php foreach ($entities as $en): ?><option value="<?= e($en) ?>"<?= $filters['entidad'] === $en ? ' selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label for="origen" class="form-label small mb-1">Origen</label>
                <select id="origen" name="origen" class="form-select form-select-sm" data-autosubmit><option value="">Todos</option>
                    <?php foreach (['WEB' => 'Web', 'CLI' => 'Línea de comandos', 'WORKER' => 'Motor / servicio', 'SISTEMA' => 'Sistema'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['origen'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label for="resultado" class="form-label small mb-1">Resultado</label>
                <select id="resultado" name="resultado" class="form-select form-select-sm" data-autosubmit><option value="">Todos</option>
                    <?php foreach (['EXITOSO' => 'Exitoso', 'FALLIDO' => 'Fallido o rechazado'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['resultado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label for="desde" class="form-label small mb-1">Desde</label>
                <input id="desde" type="date" name="desde" class="form-control form-control-sm" value="<?= e($filters['desde']) ?>">
            </div>
            <div>
                <label for="hasta" class="form-label small mb-1">Hasta</label>
                <input id="hasta" type="date" name="hasta" class="form-control form-control-sm" value="<?= e($filters['hasta']) ?>">
            </div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
        <?= $this->insert('partials/filter-status', ['filtros' => $filters, 'limpiar' => '/auditoria']) ?>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-journal" aria-hidden="true"></i></div>
            <p class="empty-state__title">Sin registros para los filtros indicados</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <caption class="visually-hidden">Registros de auditoría (el detalle de cada uno está en «Detalle»)</caption>
                <thead><tr><th scope="col" class="num">#</th><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Acción</th><th scope="col" class="col-hide-sm">Entidad</th><th scope="col" class="col-hide-md">Origen / IP</th><th scope="col" class="cell-actions"><span class="visually-hidden">Detalle</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="num"><?= e($r['secuencia']) ?></td>
                        <td class="nowrap"><?= e(fmt_datetime($r['creado_at'], true)) ?></td>
                        <td><?= e($r['username'] ?? '—') ?><?php if ($r['roles']): ?><br><small class="text-muted"><?= e($r['roles']) ?></small><?php endif; ?></td>
                        <td><code><?= e($r['accion']) ?></code><?= \ConciliaRH\Domain\Audit\AuditRepository::failed((string) $r['accion']) ? ' ' . status_badge('ERROR', 'Fallido o rechazado') : '' ?></td>
                        <td class="col-hide-sm"><?= e($r['entidad'] ?? '—') ?><?= $r['entidad_id'] !== null ? ' #' . e($r['entidad_id']) : '' ?></td>
                        <td class="col-hide-md"><?= status_badge('ORIGEN', (string) $r['origen']) ?> <small class="text-muted"><?= e($r['ip'] ?? '') ?></small></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/auditoria/<?= e($r['id']) ?>" aria-label="Detalle del registro de auditoría #<?= e($r['secuencia']) ?>"><span class="btn-action__label">Detalle</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
