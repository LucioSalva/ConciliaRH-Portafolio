<?php
/**
 * Estado de los filtros (docs/DESIGN_SYSTEM.md §10): «N filtro(s) activo(s) · Limpiar filtros». Sólo aparece con filtros activos.
 * @var array<string,mixed> $filtros valores actuales (los vacíos no cuentan)
 * @var string $limpiar URL sin filtros
 * @var list<string> $ignorar claves que no son filtros (p. ej. orden)
 */
$n = count(array_filter($filtros, static fn ($v, $k) => !in_array($k, $ignorar ?? [], true) && $v !== '' && $v !== null && $v !== [] && $v !== false,
    ARRAY_FILTER_USE_BOTH));
if ($n === 0) {
    return;
}
?>
<p class="filter-status" role="status"><span><span class="filter-status__count"><?= e($n) ?></span> <?= $n === 1 ? 'filtro activo' : 'filtros activos' ?></span>
    <a href="<?= e($limpiar) ?>"><i class="bi bi-x-circle" aria-hidden="true"></i> Limpiar filtros</a></p>
