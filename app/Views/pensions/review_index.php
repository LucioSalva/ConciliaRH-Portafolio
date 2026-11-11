<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div>
        <h1>Revisión de pensiones</h1>
        <p class="page-header__sub">Pagos de pensión sin trabajador con evidencia <strong>MEDIA</strong>: dos señales coinciden en un mismo trabajador.
            Ninguno se confirma solo; cada caso lo decide una persona.</p>
    </div>
</div>
<div class="figures">
    <div class="figure"><p class="figure__label">Casos por revisar</p><p class="figure__value"><?= e(number_format($total)) ?></p><p class="figure__hint">con los filtros actuales</p></div>
    <div class="figure"><p class="figure__label">Importe en revisión</p><p class="figure__value"><?= money($importeTotal) ?></p><p class="figure__hint">suma de los casos</p></div>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/revision-pensiones" role="search" aria-label="Filtrar casos de pensión"><div class="filter-bar">
    <div class="search filter-bar__field--wide"><i class="bi bi-search search__icon" aria-hidden="true"></i>
        <label class="visually-hidden" for="q">Buscar por nombre del beneficiario</label>
        <input class="search__input" id="q" name="q" type="search" value="<?= e($filters['q']) ?>" maxlength="80" placeholder="Nombre del beneficiario…" autocomplete="off"></div>
    <div><label class="form-label" for="banco">Banco</label>
        <select class="form-select form-select-sm" id="banco" name="banco"><option value="">Todos</option>
            <?php foreach (['INTERBANCARIA' => 'Interbancaria', 'SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'OTROS' => 'Otros'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['banco'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="revision">Revisión</label>
        <select class="form-select form-select-sm" id="revision" name="revision">
            <?php foreach (['' => 'Todos', 'sin_revisar' => 'Sin revisar', 'pendientes' => 'Dejados pendientes'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['revision'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="filter-bar__actions">
        <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        <a class="btn-action btn-cancel btn-action--sm" href="/revision-pensiones"><span class="btn-action__label">Limpiar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
    </div>
</div>
    <?= $this->insert('partials/filter-status', ['filtros' => $filters, 'limpiar' => '/revision-pensiones']) ?>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-people" aria-hidden="true"></i></div>
            <p class="empty-state__title"><?= array_filter($filters) ? 'Ningún caso con estos filtros' : 'No hay pensiones con evidencia MEDIA por revisar' ?></p>
            <p class="mb-0">Las sugerencias aparecen aquí después de normalizar un archivo de Tesorería con pagos de pensión sin trabajador.</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack">
        <caption class="visually-hidden">Pensiones con evidencia MEDIA por revisar</caption>
        <thead><tr><th scope="col">Beneficiario</th><th scope="col">Trabajador candidato</th><th scope="col" class="num">Importe</th><th scope="col">Banco · CLABE</th>
            <th scope="col">Periodo</th><th scope="col">Evidencia</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr<?= $r['dejado_pendiente_at'] ? '' : ' class="row-attention"' ?>>
                <td class="cell-name"><?= e($r['nombre_original'] ?? '—') ?></td>
                <td><?= $r['candidato'] ? e($r['candidato']['numero_empleado']) . '<br><small class="text-muted">' . e($r['candidato']['nombre_completo']) . '</small>' : '—' ?></td>
                <td class="num"><?= money($r['importe']) ?></td>
                <td><?= e(bank_label($r['banco'])) ?> <span class="text-muted">••••<?= e($r['cuenta_ultimos4'] ?? '—') ?></span></td>
                <td><?= e($r['periodo']) ?><br><small class="text-muted">Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></small></td>
                <td><?= $this->insert('partials/evidence', ['nivel' => 'MEDIA', 'razon' => $r['motivo_texto'], 'senales' => count($r['senales_texto']), 'compact' => true]) ?>
                    <?php if ($r['dejado_pendiente_at']): ?><br><small class="text-muted"><i class="bi bi-pause-circle" aria-hidden="true"></i> Dejado pendiente <?= e(fmt_datetime($r['dejado_pendiente_at'])) ?></small><?php endif; ?></td>
                <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/revision-pensiones/<?= e($r['id']) ?>" aria-label="Revisar el caso #<?= e($r['id']) ?>"><span class="btn-action__label">Revisar</span><span class="btn-action__icon"><i class="bi bi-arrow-right" aria-hidden="true"></i></span></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
