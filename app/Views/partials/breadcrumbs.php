<?php
/** @var list<array{0:string,1:?string}> $breadcrumbs */
$items = $breadcrumbs ?? [];
if ($items === []) {
    return;
}
?>
<nav aria-label="Ruta de navegación">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/"><i class="bi bi-house-door" aria-hidden="true"></i><span class="visually-hidden">Inicio</span></a></li>
        <?php foreach ($items as $i => [$label, $href]):
            $last = $i === array_key_last($items); ?>
            <?php if ($last || $href === null): ?>
                <li class="breadcrumb-item<?= $last ? ' active' : '' ?>"<?= $last ? ' aria-current="page"' : '' ?>><?= e($label) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
