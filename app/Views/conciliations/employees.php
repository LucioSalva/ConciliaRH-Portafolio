<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$base = '/conciliaciones/' . (int) $c['id'];
$zero = static fn (mixed $v): bool => $v === null || bccomp((string) $v, '0', 2) === 0;
$pay = static fn (mixed $v): string => $zero($v) ? '<span class="diff--none">—</span>' : money($v);
$tol = (string) $c['tolerancia'];
$hayOtros = array_filter($rows, static fn ($r) => !$zero($r['pagado_otros'])) !== [];
?>
<?php
$segGrupo = ['' => 'Todos', 'POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos'];
$segRes = ['' => 'Todos', 'CORRECTOS' => 'Correctos', 'DIFERENCIA' => 'Con diferencia', 'NO_PAGADOS' => 'No pagados', 'PENDIENTES' => 'Pendientes'];
$cg = $counts[$filters['grupo'] !== '' ? $filters['grupo'] : 'TODOS'] ?? [];
$avanzados = $filters['banco'] !== '' || $filters['area'] !== '' || $filters['min'] !== null || $filters['max'] !== null || $filters['pension'] || $filters['incidencia']
    || in_array($filters['estado'], \ConciliaRH\Domain\Conciliation\ConciliationService::ESTADOS_EMPLEADO, true) || $filters['estado'] === 'CON_DIFERENCIA';
?>
<div class="page-header">
    <div>
        <h1>Resultados por persona</h1>
        <p class="page-header__sub"><?= e($c['periodo']) ?> · cotejo del <?= e(fmt_datetime($c['created_at'])) ?> · <?= status_badge((string) $c['estado']) ?></p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-view" href="<?= e($base) ?>/pensiones"><span class="btn-action__label">Pensiones</span><span class="btn-action__icon"><i class="bi bi-person-heart" aria-hidden="true"></i></span></a>
        <a class="btn-action btn-view" href="<?= e($base) ?>"><span class="btn-action__label">Resumen</span><span class="btn-action__icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span></a>
    </div>
</div>

<form method="get" action="<?= e($base) ?>/empleados" class="card mb-3" role="search" aria-label="Filtrar resultados por persona"><div class="card-body d-grid gap-3">
    <div class="filter-bar">
        <fieldset class="segmented segmented--3" aria-label="Población">
            <legend class="visually-hidden">Población</legend>
            <?php foreach ($segGrupo as $v => $l): ?>
                <input type="radio" id="grupo-<?= e($v ?: 'todos') ?>" name="grupo" value="<?= e($v) ?>" data-autosubmit<?= $filters['grupo'] === $v ? ' checked' : '' ?>>
                <label for="grupo-<?= e($v ?: 'todos') ?>"><?= e($l) ?> <span class="segmented__count"><?= e(number_format((int) ($counts[$v !== '' ? $v : 'TODOS']['TODOS'] ?? 0))) ?></span></label>
            <?php endforeach; ?>
            <span class="segmented__glider" aria-hidden="true"></span>
        </fieldset>
        <fieldset class="segmented segmented--5" aria-label="Resultado">
            <legend class="visually-hidden">Resultado</legend>
            <?php foreach ($segRes as $v => $l): $checked = $filters['estado'] === $v; ?>
                <input type="radio" id="res-<?= e($v ?: 'todos') ?>" name="estado" value="<?= e($v) ?>" data-autosubmit<?= $checked ? ' checked' : '' ?>>
                <label for="res-<?= e($v ?: 'todos') ?>"><?= e($l) ?> <span class="segmented__count"><?= e(number_format((int) ($cg[$v !== '' ? $v : 'TODOS'] ?? 0))) ?></span></label>
            <?php endforeach; ?>
            <span class="segmented__glider" aria-hidden="true"></span>
        </fieldset>
    </div>
    <div class="filter-bar">
        <div class="search filter-bar__field--wide">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por número o nombre</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="80" autocomplete="off"
                   placeholder="Número o nombre<?= $this->can('conciliaciones.ver_sensible') ? ' · RFC/CURP exacto' : '' ?>…">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar__actions">
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
            <a class="btn-action btn-cancel btn-action--sm" href="<?= e($base) ?>/empleados"><span class="btn-action__label">Limpiar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
        </div>
    </div>
    <details class="filter-more"<?= $avanzados ? ' open' : '' ?>>
        <summary>Más filtros</summary>
        <div class="filter-bar mt-2">
            <div><label class="form-label" for="estado-det">Resultado detallado</label>
                <select class="form-select form-select-sm" id="estado-det" name="estado_detalle"><option value="">—</option>
                    <?php foreach (\ConciliaRH\Domain\Conciliation\ConciliationService::ESTADOS_EMPLEADO as $st): ?><option value="<?= e($st) ?>"<?= $filters['estado'] === $st ? ' selected' : '' ?>><?= e(status_label($st)) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="banco">Banco</label>
                <select class="form-select form-select-sm" id="banco" name="banco"><option value="">Todos</option>
                    <?php foreach (['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria', 'OTROS' => 'Otros'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['banco'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label" for="area">Área</label>
                <input class="form-control form-control-sm" id="area" name="area" value="<?= e($filters['area']) ?>" maxlength="80" autocomplete="off"></div>
            <div><label class="form-label" for="min">Diferencia desde</label>
                <input class="form-control form-control-sm" id="min" name="min" value="<?= e($filters['min'] ?? '') ?>" inputmode="decimal" pattern="\d{1,12}(\.\d{1,2})?" placeholder="0.00" autocomplete="off"></div>
            <div><label class="form-label" for="max">Diferencia hasta</label>
                <input class="form-control form-control-sm" id="max" name="max" value="<?= e($filters['max'] ?? '') ?>" inputmode="decimal" pattern="\d{1,12}(\.\d{1,2})?" placeholder="0.00" autocomplete="off"></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="pension" name="pension" value="1"<?= $filters['pension'] ? ' checked' : '' ?>><label class="form-check-label small" for="pension">Con pensión</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="incidencia" name="incidencia" value="1"<?= $filters['incidencia'] ? ' checked' : '' ?>><label class="form-check-label small" for="incidencia">Con incidencia abierta</label></div>
        </div>
    </details>
</div></form>

<?php if ($searchError): ?><div class="notice notice--warning" role="alert"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($searchError) ?></div></div><?php endif; ?>

<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-search" aria-hidden="true"></i></div><p class="empty-state__title">Sin resultados con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive<?= count($rows) > 12 ? ' table-scroll' : '' ?>"><table class="table table-hover align-middle table-sticky-first table-stack table-dense">
        <caption class="visually-hidden">Resultados por persona. Diferencia = total pagado − neto de RH</caption>
        <thead><tr>
            <th scope="col">Número</th><th scope="col">Nombre · área</th>
            <th scope="col" class="num">Neto RH</th><th scope="col" class="num col-hide-sm">Santander</th><th scope="col" class="num col-hide-sm">BBVA</th>
            <th scope="col" class="num col-hide-sm">Interbancaria</th><?php if ($hayOtros): ?><th scope="col" class="num col-hide-lg">Otros</th><?php endif; ?><th scope="col" class="num">Total pagado</th>
            <th scope="col" class="num">Diferencia</th><th scope="col">Estado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $attn = !in_array((string) $r['estado'], ['CORRECTO', 'NO_APLICA'], true); ?>
            <tr<?= $attn ? ' class="row-attention"' : '' ?>>
                <td class="nowrap"><?= e($r['numero_empleado']) ?> <small class="text-muted d-sm-block"><?= e(ucfirst(strtolower((string) $r['grupo']))) ?></small></td>
                <td class="cell-name"><?= e($r['nombre_completo']) ?><?php if (!empty($r['area'])): ?><small class="d-block text-muted col-hide-sm"><?= e($r['area']) ?></small><?php endif; ?></td>
                <td class="num"><?= money($r['neto_esperado']) ?></td>
                <td class="num col-hide-sm"><?= $pay($r['pagado_santander']) ?></td>
                <td class="num col-hide-sm"><?= $pay($r['pagado_bbva']) ?></td>
                <td class="num col-hide-sm"><?= $pay($r['pagado_interbancaria']) ?></td>
                <?php if ($hayOtros): ?><td class="num col-hide-lg"><?= $pay($r['pagado_otros']) ?></td><?php endif; ?>
                <td class="num fw-600"><?= money($r['pagado_total']) ?></td>
                <td class="num"><?= money_diff($r['diferencia'], $tol) ?></td>
                <td><span class="d-inline-flex flex-column align-items-end align-items-sm-start gap-1"><?= status_badge((string) $r['estado']) ?><?php if ($r['alerta']): ?><small class="text-muted"><i class="bi bi-bell" aria-hidden="true"></i> <?= e(status_label((string) $r['alerta'])) ?></small><?php endif; ?><?= $r['con_pension'] ? '<small class="text-muted"><i class="bi bi-people" aria-hidden="true"></i> con pensión</small>' : '' ?></span></td>
                <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="<?= e($base) ?>/empleados/<?= e($r['empleado_id']) ?>" aria-label="Ver detalle de <?= e($r['numero_empleado']) ?>"><span class="btn-action__label">Detalle</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
