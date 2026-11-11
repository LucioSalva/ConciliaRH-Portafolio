<?php
/** @var \ConciliaRH\Core\Template $this */
use ConciliaRH\Domain\Identity\MergeService;

$this->layout('layouts/app', ['title' => $title]);
$lbl = static fn (array $items, array $map): string => implode(' · ', array_map(static fn ($x) => $map[$x['codigo']] ?? status_label($x['codigo']), $items));
?>
<div class="page-header"><div><h1>Posibles duplicados de empleados</h1>
    <p class="page-header__sub">Pares detectados con reglas determinísticas y explicables. Nada se fusiona solo: cada fusión es uno a uno, previsualizada y con motivo.
        El nombre por sí solo nunca permite fusionar.</p></div>
    <div class="page-header__actions">
        <a class="btn-action btn-view" href="/empleados/fusiones"><span class="btn-action__label">Fusiones realizadas</span><span class="btn-action__icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span></a>
        <form method="post" action="/empleados/duplicados/detectar" data-busy><?= $this->csrf() ?>
            <button class="btn-action btn-retry" type="submit"><span class="btn-action__label">Detectar ahora</span><span class="btn-action__icon"><i class="bi bi-search" aria-hidden="true"></i></span></button></form>
    </div></div>
<div class="row g-3 mb-3">
    <?php foreach (['ALTA' => ['Confianza alta', 'RFC, CURP, ISSEMYM o cuenta confirmada iguales'], 'MEDIA' => ['Confianza media', 'Pagos a la misma cuenta o un registro con identificadores de ambos'],
                    'BAJA' => ['Confianza baja', 'Sólo nombre: no permite fusionar']] as $k => [$l, $d]): ?>
        <div class="col-12 col-md-4"><div class="kpi"><div class="kpi__label"><?= e($l) ?> (pendientes)</div>
            <div class="kpi__value"><?= e((string) ($conteos[$k] ?? 0)) ?></div><div class="kpi__hint"><?= e($d) ?></div></div></div>
    <?php endforeach; ?>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/empleados/duplicados" role="search" aria-label="Filtrar posibles duplicados">
        <div class="search"><i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por número o nombre</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Número o nombre…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <div class="filter-bar">
            <?php foreach ([['estado', 'Estado', array_combine(MergeService::ESTADOS, array_map('status_label', MergeService::ESTADOS))],
                            ['confianza', 'Confianza', ['ALTA' => 'Alta', 'MEDIA' => 'Media', 'BAJA' => 'Baja']]] as [$k, $l, $opts]): ?>
                <div><label class="form-label small mb-1" for="d-<?= e($k) ?>"><?= e($l) ?></label>
                    <select class="form-select form-select-sm" id="d-<?= e($k) ?>" name="<?= e($k) ?>" data-autosubmit>
                        <?php if ($k === 'confianza'): ?><option value="">Todas</option><?php endif; ?>
                        <?php foreach ($opts as $v => $t): ?><option value="<?= e($v) ?>"<?= ($filters[$k] ?: ($k === 'estado' ? 'PENDIENTE' : '')) === $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <?php endforeach; ?>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($searchError): ?><div class="notice notice--warning m-3" role="alert"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($searchError) ?></div></div><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-intersect" aria-hidden="true"></i></div><p class="empty-state__title">No hay pares con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table align-middle table-stack"><caption class="visually-hidden">Posibles duplicados de empleados</caption>
        <thead><tr><th scope="col">Empleado A</th><th scope="col">Empleado B</th><th scope="col">Confianza</th><th scope="col">Evidencias</th><th scope="col" class="col-hide-md">Conflictos</th><th scope="col" class="cell-actions">Acciones</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
            <tr><td><a href="/empleados/<?= e($r['empleado_a']) ?>"><?= e($r['a_numero']) ?></a><br><small><?= e($r['a_nombre']) ?> · <?= e($r['a_grupo']) ?></small></td>
                <td><a href="/empleados/<?= e($r['empleado_b']) ?>"><?= e($r['b_numero']) ?></a><br><small><?= e($r['b_nombre']) ?> · <?= e($r['b_grupo']) ?></small></td>
                <td><?= status_badge('CONF_' . $r['confianza']) ?><?= $r['estado'] !== 'PENDIENTE' ? '<br>' . status_badge($r['estado']) : '' ?></td>
                <td class="small"><?= e($lbl($r['evidencias'], MergeService::EVIDENCIAS)) ?></td>
                <td class="small col-hide-md"><?= $r['conflictos'] === [] ? '—' : e($lbl($r['conflictos'], MergeService::CONFLICTOS)) ?></td>
                <td class="cell-actions">
                    <?php if ($r['estado'] === 'PENDIENTE' && $puedeFusionar && $r['confianza'] !== 'BAJA'): ?>
                        <a class="btn-action btn-view btn-action--sm mb-1" href="/empleados/fusion?origen=<?= e($r['empleado_b']) ?>&amp;destino=<?= e($r['empleado_a']) ?>"><span class="btn-action__label">Previsualizar fusión</span><span class="btn-action__icon"><i class="bi bi-intersect" aria-hidden="true"></i></span></a>
                    <?php elseif ($r['confianza'] === 'BAJA'): ?><small class="text-muted d-block mb-1">Sólo nombre: no fusionable</small><?php endif; ?>
                    <?php if ($r['estado'] === 'PENDIENTE'): ?>
                        <form method="post" action="/empleados/duplicados/<?= e($r['id']) ?>/descartar" data-confirm="¿Descartar este par? Sólo volverá a proponerse si aparece evidencia más fuerte." data-confirm-title="Descartar posible duplicado" data-confirm-reason="motivo"><?= $this->csrf() ?>
                            <button class="btn-action btn-cancel btn-action--sm" type="submit"><span class="btn-action__label">No son la misma persona</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></button></form>
                    <?php endif; ?>
                </td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
