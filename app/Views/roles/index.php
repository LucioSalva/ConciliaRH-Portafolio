<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$err = static fn (string $f): ?string => $errors[$f] ?? null;
?>
<div class="page-header">
    <div>
        <h1>Roles y permisos</h1>
        <p class="page-header__sub">Control de acceso basado en roles. La autorización se valida en el servidor para cada acción.</p>
    </div>
</div>

<div class="row g-3">
    <div class="<?= $this->can('roles.administrar') ? 'col-xl-8' : 'col-12' ?>">
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-stack">
                    <caption class="visually-hidden">Roles del sistema (los permisos de cada uno están en «Ver»)</caption>
                    <thead><tr><th scope="col">Rol</th><th scope="col">Descripción</th><th scope="col" class="num">Permisos</th><th scope="col" class="num">Usuarios</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td>
                                <span class="role-chip role-chip--<?= e($r['codigo']) ?>"><?= e($r['codigo']) ?></span>
                                <div class="fw-600"><?= e($r['nombre']) ?></div>
                                <?php if ($r['protegido']): ?><?= status_badge('PROTEGIDO', 'Protegido: sólo GOD lo asigna') ?><?php endif; ?>
                                <?php if (!$r['activo']): ?><?= status_badge('INACTIVO') ?><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($r['descripcion'] ?? '') ?></td>
                            <td class="num"><?= $r['codigo'] === 'GOD' ? 'Todos' : e($r['total_permisos']) ?></td>
                            <td class="num"><?= e($r['total_usuarios']) ?></td>
                            <td class="cell-actions">
                                <a class="btn-action btn-view btn-action--sm" href="/roles/<?= e($r['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($this->can('roles.administrar')): ?>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header card-header--accent">Nuevo rol</div>
                <div class="card-body">
                    <form method="post" action="/roles" class="d-grid gap-3" novalidate data-busy>
                        <?= $this->csrf() ?>
                        <div>
                            <label class="form-label" for="codigo">Código</label>
                            <input class="form-control<?= $err('codigo') ? ' is-invalid' : '' ?>" id="codigo" name="codigo" required maxlength="40" value="<?= e($this->old('codigo')) ?>" placeholder="SUPERVISOR…" autocomplete="off" spellcheck="false"<?= field_invalid('codigo', $err('codigo')) ?>>
                            <?= field_error('codigo', $err('codigo')) ?>
                        </div>
                        <div>
                            <label class="form-label" for="nombre">Nombre</label>
                            <input class="form-control<?= $err('nombre') ? ' is-invalid' : '' ?>" id="nombre" name="nombre" required maxlength="80" value="<?= e($this->old('nombre')) ?>" autocomplete="off"<?= field_invalid('nombre', $err('nombre')) ?>>
                            <?= field_error('nombre', $err('nombre')) ?>
                        </div>
                        <div>
                            <label class="form-label" for="descripcion">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="2" maxlength="300"><?= e($this->old('descripcion')) ?></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="protegido" name="protegido" value="1">
                            <label class="form-check-label" for="protegido">Protegido <small class="text-muted d-block">Sólo GOD podrá asignarlo a usuarios.</small></label>
                        </div>
                        <div>
                            <button class="btn-action btn-add" type="submit"><span class="btn-action__label">Crear rol</span><span class="btn-action__icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
