<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$labels = ['pendientes' => 'Pendientes', 'CONFLICTO' => 'Conflicto', 'SUGERENCIA' => 'Sugerencia', 'AMBIGUO' => 'Ambiguo', 'NO_IDENTIFICADO' => 'No identificado',
           'ASOCIADO_MANUAL' => 'Asociados manualmente', 'NO_IDENTIFICADO_CONFIRMADO' => 'No identificados (confirmado)'];
$pend = ($counts['CONFLICTO'] ?? 0) + ($counts['SUGERENCIA'] ?? 0) + ($counts['AMBIGUO'] ?? 0) + ($counts['NO_IDENTIFICADO'] ?? 0);
?>
<div class="page-header">
    <div>
        <h1>Asociaciones</h1>
        <p class="page-header__sub">Registros importados que requieren decisión. El nombre nunca asocia por sí solo: sólo sugiere.</p>
    </div>
</div>
<div class="row g-3 mb-3">
    <?php foreach ([['Pendientes', $pend, $pend ? 'kpi--wine' : ''], ['Conflictos', $counts['CONFLICTO'] ?? 0, ($counts['CONFLICTO'] ?? 0) ? 'kpi--danger' : ''],
                    ['Sugerencias', $counts['SUGERENCIA'] ?? 0, ''], ['Asociados automáticamente', $counts['ASOCIADO_AUTOMATICO'] ?? 0, 'kpi--success']] as [$l, $v, $cls]): ?>
        <div class="col-6 col-lg-3"><div class="kpi <?= e($cls) ?>"><div class="kpi__label"><?= e($l) ?></div><div class="kpi__value"><?= e(number_format((int) $v)) ?></div></div></div>
    <?php endforeach; ?>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/asociaciones" role="search" aria-label="Filtrar asociaciones">
        <div class="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por nombre</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre en el archivo…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar">
            <?php foreach (['periodo', 'lote', 'archivo'] as $k): if ($filters[$k] !== null): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
            <div><label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <?php foreach ($labels as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="form-label small mb-1" for="origen">Origen</label>
                <select class="form-select form-select-sm" id="origen" name="origen" data-autosubmit>
                    <option value="">RH y Tesorería</option><option value="RRHH"<?= $filters['origen'] === 'RRHH' ? ' selected' : '' ?>>RH</option><option value="TESORERIA"<?= $filters['origen'] === 'TESORERIA' ? ' selected' : '' ?>>Tesorería</option>
                </select></div>
            <div><label class="form-label small mb-1" for="banco">Banco</label>
                <select class="form-select form-select-sm" id="banco" name="banco" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach (['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria', 'PENSION' => 'Pensiones'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['banco'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($filters['archivo'] !== null): ?><p class="small px-3 pt-2 mb-0">Filtrado por <a href="/importaciones/<?= e($filters['archivo']) ?>">archivo #<?= e($filters['archivo']) ?></a> · <a href="/asociaciones">quitar filtro</a></p><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></div><p class="empty-state__title">No hay registros en esta bandeja</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sticky-first align-middle">
                <caption class="visually-hidden">Registros por asociar (la decisión se toma en «Revisar»)</caption>
                <thead><tr><th scope="col">Origen · fila</th><th scope="col">Nombre en el archivo</th><th scope="col" class="col-hide-sm">Identificadores</th><th scope="col">Estado</th><th scope="col" class="col-hide-md">Motivo</th><th scope="col" class="num col-hide-sm">Importe</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $ev = $r['asociacion_evidencia']; ?>
                    <tr>
                        <td class="small"><?= e($r['registro_tipo']) ?><?= $r['banco'] ? ' · ' . e($r['banco']) : '' ?><?= $r['categoria'] === 'PENSION' ? ' ' . status_badge('PENSION') : '' ?><br>
                            <a href="/importaciones/<?= e($r['archivo_id']) ?>" title="<?= e($r['archivo']) ?>">Archivo #<?= e($r['archivo_id']) ?></a> · <?= e($r['hoja_nombre']) ?> · fila <?= e($r['fila_origen']) ?><br><span class="text-muted"><?= e($r['periodo']) ?> · lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></span></td>
                        <td class="text-break"><?= e($r['nombre_original'] ?? '—') ?></td>
                        <td class="small col-hide-sm">No. <code><?= e($r['numero_original'] ?? '—') ?></code><?= $r['cuenta_ultimos4'] ? '<br>Cuenta <code>' . e(\ConciliaRH\Domain\Identity\AccountVault::mask($r['cuenta_ultimos4'])) . '</code>' : '' ?></td>
                        <td><?= status_badge((string) $r['asociacion_estado']) ?><?= isset($ev['candidatos']) ? '<br><small class="text-muted">' . e(count($ev['candidatos'])) . ' candidato(s)</small>' : '' ?></td>
                        <td class="small col-hide-md"><?= e($ev['motivo'] ?? (isset($ev['fuertes']) && count($ev['fuertes']) > 1 ? 'Identificadores contradictorios' : '—')) ?></td>
                        <td class="num small col-hide-sm"><?= money($r['importe']) ?></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/asociaciones/<?= e(strtolower((string) $r['registro_tipo'])) ?>/<?= e($r['id']) ?>"><span class="btn-action__label">Revisar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
