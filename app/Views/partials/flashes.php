<?php
/** @var list<array{type:string,message:string}> $flashes */
$icons = ['success' => 'bi-check-circle-fill', 'error' => 'bi-x-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
$titles = ['success' => 'Listo', 'error' => 'Error', 'warning' => 'Atención', 'info' => 'Aviso'];
?>
<div id="toastStack" class="toast-stack" aria-live="polite">
    <?php foreach ($flashes ?? [] as $f):
        $type = isset($icons[$f['type']]) ? $f['type'] : 'info'; ?>
        <div class="notice notice--<?= e($type) ?>" data-flash="<?= e($type) ?>" role="<?= in_array($type, ['error', 'warning'], true) ? 'alert' : 'status' ?>">
            <i class="bi <?= e($icons[$type]) ?> notice__icon" aria-hidden="true"></i>
            <div class="notice__body">
                <p class="notice__title"><?= e($titles[$type]) ?></p>
                <div><?= e($f['message']) ?></div>
            </div>
            <button type="button" class="notice__close" aria-label="Cerrar notificación">×</button>
        </div>
    <?php endforeach; ?>
</div>
