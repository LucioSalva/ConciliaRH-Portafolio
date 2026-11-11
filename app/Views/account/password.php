<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$fields = [
    ['password_actual', 'Contraseña actual', 'current-password'],
    ['password_nueva', 'Nueva contraseña', 'new-password'],
    ['password_confirmacion', 'Confirme la nueva contraseña', 'new-password'],
];
?>
<div class="page-header">
    <div>
        <h1>Cambiar contraseña</h1>
        <p class="page-header__sub">Al cambiarla se cerrarán sus demás sesiones abiertas.</p>
    </div>
</div>
<?php if ($forced): ?>
    <div class="notice notice--warning" role="alert">
        <i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body">
            <p class="notice__title">Cambio obligatorio</p>
            Su cuenta tiene una contraseña temporal. Debe definir una contraseña personal para continuar.
        </div>
    </div>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="post" action="/cuenta/password" class="row g-3" data-busy novalidate>
            <?= $this->csrf() ?>
            <?php foreach ($fields as [$name, $label, $ac]): $err = $errors[$name] ?? null; ?>
                <div class="col-md-8 col-lg-6">
                    <label class="form-label" for="<?= e($name) ?>"><?= e($label) ?></label>
                    <div class="input-group">
                        <input type="password" class="form-control<?= $err ? ' is-invalid' : '' ?>" id="<?= e($name) ?>" name="<?= e($name) ?>"
                               autocomplete="<?= e($ac) ?>" required maxlength="128"<?= $name === 'password_nueva' ? ' aria-describedby="password_nueva-help' . ($err ? ' password_nueva-error' : '') . '"' : field_invalid($name, $err) ?><?= $name === 'password_nueva' && $err ? ' aria-invalid="true"' : '' ?>>
                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="<?= e($name) ?>" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <?= field_error($name, $err) ?>
                    <?php if ($name === 'password_nueva'): ?>
                        <div class="form-text" id="password_nueva-help">Mínimo <?= e($minLength) ?> caracteres, combine al menos dos tipos (minúsculas, mayúsculas, números o símbolos) y no incluya su usuario.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="col-12">
                <button type="submit" class="btn-action btn-confirm">
                    <span class="btn-action__label">Guardar contraseña</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                </button>
            </div>
        </form>
    </div>
</div>
