<?php
/** @var \ConciliaRH\Core\Paginator $paginator */
?>
<div class="table-card__footer">
    <span>Mostrando <?= e($paginator->from()) ?>–<?= e($paginator->to()) ?> de <?= e($paginator->total) ?></span>
    <?php if ($paginator->pages > 1): ?>
        <nav aria-label="Paginación">
            <ul class="pagination pagination-sm">
                <li class="page-item<?= $paginator->page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($paginator->url(max(1, $paginator->page - 1))) ?>" aria-label="Anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                </li>
                <?php foreach ($paginator->window() as $p): ?>
                    <?php if ($p === null): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php else: ?>
                        <li class="page-item<?= $p === $paginator->page ? ' active' : '' ?>">
                            <a class="page-link" href="<?= e($paginator->url($p)) ?>"<?= $p === $paginator->page ? ' aria-current="page"' : '' ?>><?= e($p) ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item<?= $paginator->page >= $paginator->pages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($paginator->url(min($paginator->pages, $paginator->page + 1))) ?>" aria-label="Siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
