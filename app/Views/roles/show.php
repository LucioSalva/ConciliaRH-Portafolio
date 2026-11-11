<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$isGod = $role['codigo'] === 'GOD';
?>
<div class="page-header">
    <div>
        <h1><span class="role-chip role-chip--<?= e($role['codigo']) ?> align-middle"><?= e($role['codigo']) ?></span> <?= e($role['nombre']) ?></h1>
        <p class="page-header__sub"><?= e($role['descripcion'] ?? '') ?></p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/roles"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<?php if ($isGod): ?>
    <div class="notice notice--incident" role="status">
        <i class="bi bi-shield-lock-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Rol de máximo nivel</p>GOD conserva siempre todos los permisos, incluidos los exclusivos. Sus acciones también quedan en la auditoría.</div>
    </div>
<?php elseif (!$editable): ?>
    <div class="notice notice--info" role="status">
        <i class="bi bi-info-circle-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body">Consulta de sólo lectura. Modificar permisos es exclusivo de GOD.</div>
    </div>
<?php endif; ?>

<form method="post" action="/roles/<?= e($role['id']) ?>/permisos" data-perm-matrix
      data-confirm="Los cambios de permisos aplican de inmediato a todos los usuarios con este rol." data-confirm-title="Modificar permisos">
    <?= $this->csrf() ?>
    <div class="perm-tools">
        <div class="search" role="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label class="visually-hidden" for="perm-q">Buscar permiso por código o descripción</label>
            <?php /* form="perm-filtro" (no existe): el buscador filtra en la página y NUNCA envía el formulario de permisos (Enter, limpiar, Esc) */ ?>
            <input class="search__input" id="perm-q" type="search" placeholder="Buscar permiso…" autocomplete="off" form="perm-filtro" data-perm-search>
            <button type="button" class="search__clear" data-search-clear="perm-q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <p class="small text-muted mb-0" aria-live="polite" data-perm-count></p>
        <p class="small mb-0"><?= status_badge('SOLO_GOD') ?> exclusivo de GOD (no se asigna a otros roles) · <?= status_badge('SENSIBLE', 'Sensible') ?> su uso se audita</p>
    </div>
    <div class="row g-3">
        <?php foreach ($modules as $module => $perms): ?>
            <div class="col-md-6 col-xl-4">
                <div class="perm-module" data-perm-module>
                    <div class="perm-module__head"><h2 class="perm-module__title"><?= e($module) ?> <span class="text-muted fw-normal" data-perm-module-count></span></h2>
                        <?php if ($editable && !$isGod): ?><button type="button" class="perm-module__toggle" data-perm-toggle aria-label="Marcar o quitar todos los permisos asignables de <?= e($module) ?>">Marcar todos</button><?php endif; ?>
                    </div>
                    <?php foreach ($perms as $p):
                        $checked = $isGod || in_array((int) $p['id'], $role['permisos'], true);
                        $disabled = !$editable || $p['solo_god']; ?>
                        <div class="perm-item" data-perm-item>
                            <input class="form-check-input mt-1" type="checkbox" name="permisos[]" value="<?= e($p['id']) ?>" id="perm-<?= e($p['id']) ?>"
                                <?= $checked ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                            <label for="perm-<?= e($p['id']) ?>">
                                <code><?= e($p['codigo']) ?></code>
                                <?php if ($p['solo_god']): ?> <?= status_badge('SOLO_GOD') ?><?php endif; ?>
                                <?php if ($p['sensible']): ?> <?= status_badge('SENSIBLE', 'Sensible: se audita') ?><?php endif; ?>
                                <br><small class="text-muted"><?= e($p['descripcion']) ?></small>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($editable): ?>
        <button class="btn-action btn-confirm mt-2" type="submit"><span class="btn-action__label">Guardar permisos</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button>
    <?php endif; ?>
</form>
