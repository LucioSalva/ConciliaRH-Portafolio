<?php
/** @var \ConciliaRH\Core\Template $this */
/** @var array<string,mixed>|null $target */
$this->layout('layouts/app', ['title' => $title]);
$isNew = $target === null;
$canManage = $isNew ? true : ($canManage ?? false);
$isGodTarget = !$isNew && in_array('GOD', $target['roles'], true);
$deleted = !$isNew && $target['deleted_at'] !== null;
$editable = $canManage && !$deleted && ($isNew ? $this->can('usuarios.crear') : $this->can('usuarios.editar'));
$old = $old ?? [];
$selectedRoles = $isNew ? (array) ($old['roles'] ?? []) : (isset($old['roles']) ? (array) $old['roles'] : $target['roles']);
$err = static fn (string $f): ?string => $errors[$f] ?? null;
?>
<div class="page-header">
    <div>
        <h1><?= $isNew ? 'Nuevo usuario' : e($target['username']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
                <?= status_badge($deleted ? 'ELIMINADO' : ($target['activo'] ? 'ACTIVO' : 'INACTIVO')) ?>
                <?php foreach ($target['roles'] as $r): ?><span class="role-chip role-chip--<?= e($r) ?>"><?= e($r) ?></span><?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/usuarios"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<?php if (!$isNew && !$canManage): ?>
    <div class="notice notice--info" role="status">
        <i class="bi bi-info-circle-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><?= $isGodTarget ? 'La cuenta GOD sólo puede ser administrada por su titular.' : 'Esta cuenta tiene un rol protegido; sólo GOD puede modificarla.' ?></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header card-header--accent">Datos de la cuenta</div>
            <div class="card-body">
                <form method="post" action="<?= $isNew ? '/usuarios' : '/usuarios/' . e($target['id']) ?>" class="row g-3" novalidate data-busy>
                    <?= $this->csrf() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="username">Usuario</label>
                        <?php if ($isNew): ?>
                            <input class="form-control<?= $err('username') ? ' is-invalid' : '' ?>" id="username" name="username" required maxlength="50"
                                   pattern="[a-z0-9][a-z0-9._\-]{2,49}" autocomplete="off" autocapitalize="none" spellcheck="false" value="<?= e($this->old('username')) ?>"<?= field_invalid('username', $err('username')) ?>>
                            <?= field_error('username', $err('username')) ?>
                            <div class="form-text">Minúsculas, números, punto, guion o guion bajo. No se usa correo electrónico.</div>
                        <?php else: ?>
                            <input class="form-control" id="username" value="<?= e($target['username']) ?>" disabled>
                            <div class="form-text">El nombre de usuario no se modifica (trazabilidad de auditoría).</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="nombre">Nombre completo</label>
                        <input class="form-control<?= $err('nombre') ? ' is-invalid' : '' ?>" id="nombre" name="nombre" required maxlength="150"
                               value="<?= e($isNew ? $this->old('nombre') : ($old['nombre'] ?? $target['nombre'])) ?>" autocomplete="off"<?= field_invalid('nombre', $err('nombre')) ?><?= $editable ? '' : ' disabled' ?>>
                        <?= field_error('nombre', $err('nombre')) ?>
                    </div>
                    <?php if ($isNew): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Contraseña temporal</label>
                            <div class="input-group">
                                <input type="password" class="form-control<?= $err('password') ? ' is-invalid' : '' ?>" id="password" name="password" required maxlength="128" autocomplete="new-password" aria-describedby="password-help<?= $err('password') ? ' password-error" aria-invalid="true' : '' ?>">
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <?= field_error('password', $err('password')) ?>
                            <div class="form-text" id="password-help">Mínimo <?= e($minLength) ?> caracteres. El usuario deberá cambiarla al primer acceso.</div>
                        </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <fieldset<?= $err('roles') ? ' aria-describedby="roles-error"' : '' ?>>
                            <legend class="form-label">Roles</legend>
                            <?php if ($isGodTarget): ?>
                                <p class="text-muted small mb-0">El rol GOD se administra exclusivamente por línea de comandos.</p>
                            <?php else: ?>
                                <?php foreach ($roles as $r): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="roles[]" id="rol-<?= e($r['codigo']) ?>" value="<?= e($r['codigo']) ?>"
                                            <?= in_array($r['codigo'], $selectedRoles, true) ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>>
                                        <label class="form-check-label" for="rol-<?= e($r['codigo']) ?>">
                                            <span class="role-chip role-chip--<?= e($r['codigo']) ?>"><?= e($r['codigo']) ?></span>
                                            <?= e($r['nombre']) ?><?php if ($r['protegido']): ?> <small class="text-muted">(protegido)</small><?php endif; ?>
                                            <?php if ($r['descripcion']): ?><br><small class="text-muted"><?= e($r['descripcion']) ?></small><?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?= field_error('roles', $err('roles')) ?>
                            <?php endif; ?>
                        </fieldset>
                    </div>
                    <?php if ($editable): ?>
                        <div class="col-12">
                            <button type="submit" class="btn-action <?= $isNew ? 'btn-add' : 'btn-confirm' ?>">
                                <span class="btn-action__label"><?= $isNew ? 'Crear usuario' : 'Guardar cambios' ?></span>
                                <span class="btn-action__icon"><i class="bi <?= $isNew ? 'bi-person-plus' : 'bi-check-lg' ?>" aria-hidden="true"></i></span>
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <?php if (!$isNew): ?>
        <div class="col-xl-5">
            <div class="card mb-3">
                <div class="card-header card-header--accent">Información</div>
                <div class="card-body">
                    <dl class="dl-grid">
                        <dt>Último acceso</dt><dd><?= e(fmt_datetime($target['ultimo_acceso_at'])) ?></dd>
                        <dt>Creado</dt><dd><?= e(fmt_datetime($target['created_at'])) ?></dd>
                        <dt>Contraseña cambiada</dt><dd><?= e(fmt_datetime($target['password_cambiado_at'])) ?></dd>
                        <dt>Contraseña temporal</dt><dd><?= $target['debe_cambiar_password'] ? 'Sí' : 'No' ?></dd>
                        <?php if ($deleted): ?><dt>Eliminado</dt><dd><?= e(fmt_datetime($target['deleted_at'])) ?></dd><?php endif; ?>
                    </dl>
                </div>
            </div>

            <?php if ($canManage && !$deleted && !$isSelf): ?>
                <div class="card">
                    <div class="card-header card-header--accent">Acciones administrativas</div>
                    <div class="card-body d-grid gap-3">
                        <?php if ($this->can('usuarios.restablecer_password')): ?>
                            <form method="post" action="/usuarios/<?= e($target['id']) ?>/password" class="row g-2"
                                  data-confirm="Se asignará una contraseña temporal y se cerrarán todas las sesiones del usuario." data-confirm-title="Restablecer contraseña">
                                <?= $this->csrf() ?>
                                <div class="col-12">
                                    <label class="form-label" for="reset-password">Nueva contraseña temporal</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="reset-password" name="password" required minlength="<?= e($minLength) ?>" maxlength="128" autocomplete="new-password">
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="reset-password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn-action btn-edit btn-action--sm" type="submit"><span class="btn-action__label">Restablecer contraseña</span><span class="btn-action__icon"><i class="bi bi-key" aria-hidden="true"></i></span></button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($this->can('usuarios.cerrar_sesiones')): ?>
                                <form method="post" action="/usuarios/<?= e($target['id']) ?>/sesiones/cerrar" data-confirm="Se cerrarán todas las sesiones activas de este usuario." data-confirm-title="Cerrar sesiones">
                                    <?= $this->csrf() ?>
                                    <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Cerrar sesiones</span><span class="btn-action__icon"><i class="bi bi-door-closed" aria-hidden="true"></i></span></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($this->can('usuarios.desactivar')): ?>
                                <form method="post" action="/usuarios/<?= e($target['id']) ?>/estado"
                                      data-confirm="<?= $target['activo'] ? 'El usuario no podrá iniciar sesión y se cerrarán sus sesiones.' : 'El usuario podrá volver a iniciar sesión.' ?>"
                                      data-confirm-title="<?= $target['activo'] ? 'Desactivar usuario' : 'Activar usuario' ?>">
                                    <?= $this->csrf() ?>
                                    <input type="hidden" name="activo" value="<?= $target['activo'] ? '0' : '1' ?>">
                                    <button class="btn-action <?= $target['activo'] ? 'btn-cancel' : 'btn-confirm' ?> btn-action--sm" type="submit">
                                        <span class="btn-action__label"><?= $target['activo'] ? 'Desactivar' : 'Activar' ?></span>
                                        <span class="btn-action__icon"><i class="bi <?= $target['activo'] ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($this->can('usuarios.eliminar')): ?>
                                <form method="post" action="/usuarios/<?= e($target['id']) ?>/eliminar"
                                      data-confirm="El usuario se eliminará de forma lógica: no podrá acceder y su historial se conserva para auditoría."
                                      data-confirm-title="Eliminar usuario" data-confirm-variant="danger" data-confirm-reason="motivo">
                                    <?= $this->csrf() ?>
                                    <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Eliminar</span><span class="btn-action__icon"><i class="bi bi-trash3" aria-hidden="true"></i></span></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
