<?php
/** @var \ConciliaRH\Core\Template $this */
$icon = match (true) {
    $code === 'CSRF_INVALIDO' => 'bi-hourglass-bottom',
    $status === 403 => 'bi-shield-x',
    $status === 404 => 'bi-signpost-split',
    $status >= 500 => 'bi-exclamation-octagon',
    default => 'bi-exclamation-triangle',
};
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f6f5">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <?= $this->insert('partials/styles') ?>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
<main class="card p-4 text-center w-100 error-card">
    <div class="empty-state">
        <div class="empty-state__icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
        <p class="text-gold fw-600 mb-1">Error <?= e($status) ?></p>
        <h1 class="empty-state__title"><?= e($message) ?></h1>
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
            <a class="btn-action btn-add" href="/"><span class="btn-action__label">Ir al inicio</span><span class="btn-action__icon"><i class="bi bi-house-door" aria-hidden="true"></i></span></a>
            <?php if ($code === 'CSRF_INVALIDO'): ?>
                <a class="btn-action btn-cancel" href="/login"><span class="btn-action__label">Iniciar sesión</span><span class="btn-action__icon"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></span></a>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
