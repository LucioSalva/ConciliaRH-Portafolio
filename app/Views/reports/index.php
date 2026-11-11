<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$mas = array_intersect_key($tipos, array_flip(array_filter(\ConciliaRH\Http\Controllers\ReportController::MAS, fn ($k) => $k !== 'incidencias' || $this->can('incidencias.ver'))));
$listas = array_filter(\ConciliaRH\Http\Controllers\ReportController::LISTAS, fn ($k) => $k !== 'pendientes' || $this->can('asociaciones.ver'), ARRAY_FILTER_USE_KEY);
?>
<div class="page-header"><div><h1>Reportes</h1>
    <p class="page-header__sub">Elija el cotejo y el reporte. Se generan al momento y cada descarga queda registrada.</p></div></div>
<?php if ($conciliaciones === []): ?>
    <div class="card"><div class="card-body"><div class="empty-state">
        <p class="empty-state__title">Todavía no hay cotejos terminados.</p>
        <p class="mb-0">Los reportes aparecen cuando una quincena se coteja.<?php if ($this->can('periodos.ver')): ?> <a href="/periodos">Ir a quincenas</a>.<?php endif; ?></p>
    </div></div></div>
<?php else: ?>
<form method="get" action="/reportes/ver" class="report-form">
    <div class="card mb-3"><div class="card-body">
        <label class="form-label" for="conciliacion">Cotejo</label>
        <select class="form-select report-form__cotejo" id="conciliacion" name="conciliacion" required>
            <?php foreach ($conciliaciones as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['periodo']) ?> · cotejo n.º <?= e($c['numero']) ?> · <?= e(status_label((string) $c['estado'])) ?><?= $c['lote_oficial'] ? '' : ' · provisional' ?></option><?php endforeach; ?>
        </select>
        <p class="form-text mb-0">Primero aparece el cotejo más reciente de cada quincena. «Provisional» indica un resultado que todavía no es el oficial.</p>
    </div></div>

    <div class="report-main">
        <section class="card" aria-labelledby="rep-resumen">
            <div class="card-header card-header--accent" id="rep-resumen"><?= e($tipos['resumen'][0]) ?></div>
            <div class="card-body report-main__body">
                <p class="mb-0"><?= e($tipos['resumen'][1]) ?></p>
                <p class="report-main__formats">PDF · Excel · CSV</p>
                <button class="btn-action btn-view" type="submit" name="tipo" value="resumen"><span class="btn-action__label">Ver resumen</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button>
            </div>
        </section>
        <section class="card" aria-labelledby="rep-lista">
            <div class="card-header card-header--accent" id="rep-lista">Lista de personas</div>
            <div class="card-body report-main__body">
                <p class="mb-0"><?= e($tipos['empleados'][1]) ?></p>
                <div>
                    <label class="form-label" for="lista">Personas</label>
                    <select class="form-select" id="lista" name="lista">
                        <?php foreach ($listas as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <p class="report-main__formats">Excel · CSV</p>
                <button class="btn-action btn-view" type="submit" name="tipo" value="lista"><span class="btn-action__label">Ver lista</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button>
            </div>
        </section>
    </div>

    <details class="report-more">
        <summary>Más reportes (<?= e(count($mas)) ?>)</summary>
        <ul class="report-more__list">
            <?php foreach ($mas as $k => [$t, $d, $pdf]): ?>
                <li class="report-more__item">
                    <div><p class="report-more__title"><?= e($t) ?></p><p class="report-more__desc"><?= e($d) ?> <span class="report-more__formats"><?= $pdf ? 'PDF · ' : '' ?>Excel · CSV</span></p></div>
                    <button class="btn-action btn-view btn-action--sm" type="submit" name="tipo" value="<?= e($k) ?>" aria-label="Ver <?= e($t) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="form-text mb-0">El comparativo entre dos cotejos de la misma quincena se abre desde el cotejo, con «Comparar con».</p>
    </details>
</form>
<?php endif; ?>
