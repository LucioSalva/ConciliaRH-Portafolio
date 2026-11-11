<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$sens = $this->can('empleados.ver_sensible');
?>
<div class="page-header">
    <div>
        <h1>Empleados</h1>
        <p class="page-header__sub">Maestro de personas. Las altas provienen sólo de tabulados RRHH confiables; Tesorería nunca crea empleados.</p>
    </div>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/empleados" role="search" aria-label="Buscar empleados">
        <div class="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar empleado</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="80"
                   placeholder="<?= $sens ? 'Número, nombre, RFC o CURP…' : 'Número o nombre…' ?>">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar">
            <div>
                <label class="form-label small mb-1" for="grupo">Grupo</label>
                <select class="form-select form-select-sm" id="grupo" name="grupo" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach (['POLICIA' => 'Policía', 'BOMBEROS' => 'Bomberos', 'OTRO' => 'Otro'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['grupo'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <option value="">Todos</option>
                    <option value="ACTIVO"<?= $filters['estado'] === 'ACTIVO' ? ' selected' : '' ?>>Activo</option>
                    <option value="INACTIVO"<?= $filters['estado'] === 'INACTIVO' ? ' selected' : '' ?>>Inactivo</option>
                </select>
            </div>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="incidencias" name="incidencias" value="1"<?= $filters['incidencias'] ? ' checked' : '' ?> data-autosubmit>
                <label class="form-check-label small" for="incidencias">Con incidencias</label>
            </div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Buscar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($searchError): ?>
        <div class="notice notice--warning m-3" role="alert"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($searchError) ?></div></div>
    <?php endif; ?>
    <?php if ($criterio !== null): ?>
        <p class="small text-muted px-3 pt-2 mb-0">Criterio: <?= e(['NUMERO' => 'número de empleado (prefijo)', 'NOMBRE' => 'nombre (sin acentos ni puntuación)', 'IDENTIFICADOR' => 'RFC/CURP exacto'][$criterio]) ?></p>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay empleados que coincidan</p>
            <p class="mb-0">Los empleados se dan de alta al normalizar un tabulado RRHH confiable.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sticky-first align-middle">
                <caption class="visually-hidden">Empleados (el detalle de cada uno está en «Ver»)</caption>
                <thead><tr><th scope="col">Número</th><th scope="col">Nombre</th><th scope="col" class="col-hide-md">Área · cargo (última quincena)</th><th scope="col" class="col-hide-sm">Identificadores</th><th scope="col">Estado</th><th scope="col" class="col-hide-sm">Incidencias</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-600"><code><?= e($r['numero_empleado']) ?></code><br><small class="text-muted"><?= e($r['grupo']) ?></small></td>
                        <td class="text-break"><?= e($r['nombre_completo']) ?><?php if (!empty($r['fusionado_en_id'])): ?><br><a class="text-decoration-none" href="/empleados/<?= e($r['fusionado_en_id']) ?>" title="Ver la identidad canónica"><?= status_badge('FUSIONADO') ?></a><?php endif; ?></td>
                        <td class="col-hide-md small"><?= e($r['area'] ?? '—') ?><br><span class="text-muted"><?= e($r['cargo'] ?? '') ?> · <?= e($r['ultimo_periodo']) ?></span></td>
                        <td class="col-hide-sm small">RFC <code><?= e($r['rfc'] ?? '—') ?></code><?= $r['rfc'] !== null && !$r['rfc_valido'] ? ' ' . status_badge('ESTRUCTURA_INVALIDA') : '' ?><br>
                            CURP <code><?= e($r['curp'] ?? '—') ?></code> · <?= e($r['cuentas']) ?> cuenta(s)</td>
                        <td><?= status_badge($r['activo'] ? 'ACTIVO' : 'INACTIVO') ?></td>
                        <td class="col-hide-sm"><?= (int) $r['incidencias'] > 0 ? status_badge('ABIERTA', $r['incidencias'] . ' abierta(s)') : '<span class="text-muted">—</span>' ?></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/empleados/<?= e($r['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
