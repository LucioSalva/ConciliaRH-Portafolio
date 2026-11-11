<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$states = ['vigentes' => 'Vigentes', 'activos' => 'Activos', 'inactivos' => 'Inactivos', 'eliminados' => 'Eliminados'];
?>
<div class="page-header">
    <div>
        <h1>Usuarios</h1>
        <p class="page-header__sub">Cuentas con acceso al sistema. No existe registro público: sólo personal autorizado crea usuarios.</p>
    </div>
    <?php if ($this->can('usuarios.crear')): ?>
        <div class="page-header__actions">
            <a class="btn-action btn-add" href="/usuarios/nuevo">
                <span class="btn-action__label">Nuevo usuario</span><span class="btn-action__icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/usuarios" role="search" aria-label="Filtrar usuarios">
        <div class="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por usuario o nombre</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Buscar usuario o nombre…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar">
            <fieldset class="segmented" aria-label="Estado">
                <legend class="visually-hidden">Estado</legend>
                <?php foreach ($states as $value => $label): ?>
                    <input type="radio" id="estado-<?= e($value) ?>" name="estado" value="<?= e($value) ?>" data-autosubmit<?= $filters['estado'] === $value ? ' checked' : '' ?>>
                    <label for="estado-<?= e($value) ?>"><?= e($label) ?></label>
                <?php endforeach; ?>
                <span class="segmented__glider" aria-hidden="true"></span>
            </fieldset>
            <label for="rol" class="visually-hidden">Rol</label>
            <select id="rol" name="rol" class="form-select form-select-sm w-auto" data-autosubmit>
                <option value="">Todos los roles</option>
                <?php foreach (['GOD', 'ADMIN', 'CONSULTA'] as $r): ?>
                    <option value="<?= e($r) ?>"<?= $filters['rol'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach; ?>
                <?php foreach ($roles as $r): if (in_array($r['codigo'], ['GOD', 'ADMIN', 'CONSULTA'], true)) { continue; } ?>
                    <option value="<?= e($r['codigo']) ?>"<?= $filters['rol'] === $r['codigo'] ? ' selected' : '' ?>><?= e($r['codigo']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>

    <?php if ($users === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-person-x" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay usuarios que coincidan</p>
            <p class="mb-0">Ajuste la búsqueda o los filtros.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sticky-first align-middle table-stack">
                <caption class="visually-hidden">Listado de usuarios</caption>
                <thead>
                <tr>
                    <th scope="col">Nombre</th>
                    <th scope="col">Usuario</th>
                    <th scope="col">Rol</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Último acceso</th>
                    <th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $state = $u['deleted_at'] !== null ? 'ELIMINADO' : ($u['activo'] ? 'ACTIVO' : 'INACTIVO'); ?>
                    <tr>
                        <td class="fw-600 cell-name"><?= e($u['nombre']) ?><?php if ($u['debe_cambiar_password']): ?> <?= status_badge('PASSWORD_TEMPORAL', 'Contraseña temporal') ?><?php endif; ?></td>
                        <td class="font-mono"><?= e($u['username']) ?></td>
                        <td><?php foreach (array_filter(explode(',', (string) $u['roles'])) as $r): ?><span class="role-chip role-chip--<?= e($r) ?>"><?= e($r) ?></span><?php endforeach; ?></td>
                        <td><?= status_badge($state) ?></td>
                        <td class="nowrap"><?= $u['ultimo_acceso_at'] ? '<time datetime="' . e($u['ultimo_acceso_at']) . '">' . e(fmt_datetime($u['ultimo_acceso_at'])) . '</time>' : '<span class="text-muted">Nunca</span>' ?>
                            <?php if ((int) $u['sesiones_activas'] > 0): ?><br><small class="text-muted"><?= e($u['sesiones_activas']) ?> sesión(es) abierta(s)</small><?php endif; ?></td>
                        <td class="cell-actions">
                            <a class="btn-action btn-view btn-action--sm" href="/usuarios/<?= e($u['id']) ?>" aria-label="Ver usuario <?= e($u['username']) ?>">
                                <span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
