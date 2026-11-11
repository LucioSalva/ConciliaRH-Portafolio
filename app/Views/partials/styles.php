<?php
/**
 * Hojas de estilo compartidas (docs/DESIGN_SYSTEM.md §5). Un solo lugar para el orden de carga:
 * Bootstrap → tokens → base → layout → componentes → tablas → formularios → tablero → responsive.
 * @var \ConciliaRH\Core\Template $this
 * @var list<string> $styleExtra hojas propias de una pantalla (p. ej. css/login.css)
 */
$sheets = ['vendor/bootstrap/bootstrap.min.css', 'vendor/bootstrap-icons/bootstrap-icons.min.css',
    'css/tokens.css', 'css/base.css', 'css/layout.css', 'css/components.css', 'css/tables.css',
    'css/forms.css', 'css/dashboard.css', 'css/responsive.css', ...($styleExtra ?? [])];
foreach ($sheets as $s): ?>
    <link rel="stylesheet" href="<?= e($this->asset($s)) ?>">
<?php endforeach;
