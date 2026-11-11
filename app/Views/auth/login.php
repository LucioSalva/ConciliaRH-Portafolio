<?php
/** @var \ConciliaRH\Core\Template $this */
$icons = ['success' => 'bi-check-circle-fill', 'error' => 'bi-x-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
$errorIds = implode(' ', array_keys(array_filter($flashes ?? [], static fn ($f) => $f['type'] === 'error')));
$errorIds = $errorIds === '' ? '' : implode(' ', array_map(static fn ($i) => 'login-msg-' . $i, explode(' ', $errorIds)));
$hasError = $errorIds !== '';
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#521628">
    <title>Iniciar sesión · <?= e($appName) ?></title>
    <link rel="icon" href="<?= e($this->asset('img/brand/isotipo.svg')) ?>" type="image/svg+xml">
    <?= $this->insert('partials/styles', ['styleExtra' => ['css/login.css']]) ?>
</head>
<body>
<div class="login">
    <section class="login__brand" aria-label="Identidad institucional">
        <img class="login__logo" fetchpriority="high" src="<?= e($this->asset('img/brand/logo-horizontal.svg')) ?>"
             alt="ConciliaRH · Municipio" width="420" height="121">
        <div class="login__headline">
            <h1 class="login__title"><?= e($appName) ?></h1>
            <p class="login__subtitle"><?= e($appFullName) ?></p>
            <div class="login__motif" aria-hidden="true">
                <span class="login__motif-row"><span class="login__motif-label">RH autorizó</span><span class="login__motif-bar login__motif-bar--a"></span></span>
                <span class="login__motif-row"><span class="login__motif-label">Tesorería pagó</span><span class="login__motif-bar login__motif-bar--b"></span></span>
            </div>
            <p class="login__lead">Compara, persona por persona, lo que Recursos Humanos autorizó con lo que Tesorería dispersó.</p>
        </div>
        <p class="login__legal mb-0"><?= e($institution) ?>. Acceso exclusivo para personal autorizado; toda actividad queda registrada.</p>
    </section>

    <main class="login__panel">
        <div class="login-card">
            <img class="login-card__iso" src="<?= e($this->asset('img/brand/isotipo.svg')) ?>" alt="" width="56" height="56">
            <h2 class="login-card__title">Iniciar sesión</h2>
            <p class="login-card__hint">Use el usuario y la contraseña que le asignó el administrador.</p>

            <?php foreach ($flashes ?? [] as $i => $f):
                $type = isset($icons[$f['type']]) ? $f['type'] : 'info'; ?>
                <div class="notice notice--<?= e($type) ?>" id="login-msg-<?= e($i) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
                    <i class="bi <?= e($icons[$type]) ?> notice__icon" aria-hidden="true"></i>
                    <div class="notice__body"><?= e($f['message']) ?></div>
                </div>
            <?php endforeach; ?>

            <form method="post" action="/login" novalidate data-busy autocomplete="on">
                <?= $this->csrf() ?>
                <div class="login-field">
                    <label for="username">Usuario</label>
                    <div class="login-field__wrap">
                        <i class="bi bi-person login-field__icon" aria-hidden="true"></i>
                        <input id="username" name="username" type="text" required maxlength="50"
                               autocomplete="username" autocapitalize="none" spellcheck="false" autofocus
                               value="<?= e($this->old('username')) ?>"<?= $hasError ? ' aria-invalid="true" aria-describedby="' . e($errorIds) . '"' : '' ?>>
                    </div>
                </div>
                <div class="login-field">
                    <label for="password">Contraseña</label>
                    <div class="login-field__wrap">
                        <i class="bi bi-lock login-field__icon" aria-hidden="true"></i>
                        <input id="password" name="password" type="password" required maxlength="1024"
                               autocomplete="current-password"<?= $hasError ? ' aria-invalid="true" aria-describedby="' . e($errorIds) . '"' : '' ?>>
                        <button type="button" class="login-field__toggle" data-toggle-password="password"
                                aria-label="Mostrar contraseña" aria-pressed="false">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="login-submit">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Entrar
                </button>
            </form>
            <p class="login-card__foot"><i class="bi bi-shield-lock" aria-hidden="true"></i> Si no tiene usuario o lo olvidó, pídalo al administrador del sistema. No hay registro público.</p>
        </div>
    </main>
</div>
<script src="<?= e($this->asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script type="module" src="<?= e($this->asset('js/app.js')) ?>"></script>
</body>
</html>
