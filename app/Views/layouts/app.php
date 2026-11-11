<?php
/** @var \ConciliaRH\Core\Template $this */
/** @var string $content */
$title = isset($title) ? (string) $title : '';
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f6f5">
    <title><?= e($title !== '' ? $title . ' · ' : '') ?><?= e($appName) ?></title>
    <link rel="icon" href="<?= e($this->asset('img/brand/isotipo.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e($this->asset('img/brand/isotipo.svg')) ?>">
    <?= $this->insert('partials/styles') ?>
</head>
<body>
<a class="visually-hidden-focusable" href="#main">Saltar al contenido principal</a>
<div class="app">
    <?= $this->insert('partials/sidebar') ?>
    <div class="main">
        <?= $this->insert('partials/topbar', ['title' => $title]) ?>
        <main id="main" class="content" tabindex="-1">
            <?= $this->insert('partials/breadcrumbs') ?>
            <?= $content ?>
        </main>
        <footer class="footer d-flex flex-wrap justify-content-between gap-2">
            <span><?= e($institution) ?> · <?= e($appFullName) ?></span>
            <span>ConciliaRH v<?= e($appVersion) ?></span>
        </footer>
    </div>
</div>
<?= $this->insert('partials/flashes') ?>
<?= $this->insert('partials/confirm-modal') ?>
<script src="<?= e($this->asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script type="module" src="<?= e($this->asset('js/app.js')) ?>"></script>
</body>
</html>
