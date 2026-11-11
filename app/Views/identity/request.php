<?php
/** @var \ConciliaRH\Core\Template $this */
use ConciliaRH\Domain\Identity\MergeService;

$this->layout('layouts/app', ['title' => $title]);
$a = $s['analisis'];
?>
<div class="page-header">
    <div>
        <h1>Solicitud de fusión #<?= e($s['id']) ?> <?= status_badge('SOL_' . $s['estado']) ?></h1>
        <p class="page-header__sub"><a href="/empleados/<?= e($s['origen_id']) ?>"><?= e($s['origen_numero']) ?></a> se uniría a
            <a href="/empleados/<?= e($s['destino_id']) ?>"><?= e($s['destino_numero']) ?></a> (<?= e($s['destino_nombre']) ?>).</p>
    </div>
    <?php if ($s['fusion_id']): ?><a class="btn-action btn-view" href="/empleados/fusiones/<?= e($s['fusion_id']) ?>"><span class="btn-action__label">Ver fusión</span><span class="btn-action__icon"><i class="bi bi-intersect" aria-hidden="true"></i></span></a><?php endif; ?>
</div>

<?php if ($s['estado'] === 'PENDIENTE_APROBACION'): ?>
    <div class="notice notice--warning" role="status"><i class="bi bi-people-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Requiere una segunda persona</p>
            Esta fusión tiene conflictos graves. Sólo se aplica cuando la aprueba un usuario autorizado distinto de quien la solicitó.
            Vence el <?= e(fmt_datetime($s['expira_at'])) ?>.<?= $s['es_propia'] ? ' Usted es quien la solicitó: no puede aprobarla.' : '' ?></div></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7"><div class="card h-100"><div class="card-header card-header--accent">Solicitud</div><div class="card-body">
        <dl class="dl-grid">
            <dt>Solicitó</dt><dd><?= e($s['solicitante']) ?> · <?= e(fmt_datetime($s['solicitado_at'])) ?></dd>
            <dt>Motivo</dt><dd><?= e($s['motivo']) ?></dd>
            <dt>Conflictos graves aceptados</dt><dd><ul class="mb-0 ps-3"><?php foreach ($s['graves'] as $c): ?><li><?= e(MergeService::CONFLICTOS[$c] ?? $c) ?></li><?php endforeach; ?></ul></dd>
            <dt>Evidencias</dt><dd><?= e(implode(' · ', array_map(static fn ($x) => MergeService::EVIDENCIAS[$x['codigo']] ?? $x['codigo'], $a['evidencias'] ?? []))) ?: '—' ?></dd>
            <dt>Confianza</dt><dd><?= status_badge('CONF_' . ($a['confianza'] ?? 'BAJA')) ?></dd>
            <dt>Huella del análisis</dt><dd><code><?= e(substr((string) $s['huella'], 0, 16)) ?>…</code></dd>
            <?php if ($s['decidido_at']): ?>
                <dt>Decisión</dt><dd><?= e($s['decisor'] ?? 'Sistema (vencimiento)') ?> · <?= e(fmt_datetime($s['decidido_at'])) ?><?= $s['motivo_decision'] ? '<br>' . e($s['motivo_decision']) : '' ?></dd>
            <?php endif; ?>
        </dl>
    </div></div></div>
    <div class="col-12 col-lg-5"><div class="card h-100"><div class="card-header card-header--accent">Decisión</div><div class="card-body">
        <?php if ($s['puede_aprobar']): ?>
            <form method="post" action="/empleados/solicitudes-fusion/<?= e($s['id']) ?>/aprobar" class="mb-3"
                  data-confirm="Se aplicará la fusión con los conflictos graves aceptados por <?= e($s['solicitante']) ?>. Quedará registrado que usted la aprobó."
                  data-confirm-title="Aprobar y aplicar la fusión" data-confirm-reason="motivo">
                <?= $this->csrf() ?><input type="hidden" name="motivo" value="">
                <button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Aprobar y aplicar</span><span class="btn-action__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></span></button>
            </form>
            <form method="post" action="/empleados/solicitudes-fusion/<?= e($s['id']) ?>/rechazar"
                  data-confirm="No se aplicará ninguna fusión. Quien la solicitó recibirá una notificación." data-confirm-title="Rechazar la solicitud"
                  data-confirm-variant="danger" data-confirm-reason="motivo">
                <?= $this->csrf() ?><input type="hidden" name="motivo" value="">
                <button class="btn-action btn-delete" type="submit"><span class="btn-action__label">Rechazar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></button>
            </form>
        <?php elseif ($s['puede_cancelar']): ?>
            <form method="post" action="/empleados/solicitudes-fusion/<?= e($s['id']) ?>/cancelar"
                  data-confirm="La solicitud se cerrará sin aplicar la fusión." data-confirm-title="Cancelar mi solicitud" data-confirm-reason="motivo">
                <?= $this->csrf() ?><input type="hidden" name="motivo" value="">
                <button class="btn-action btn-cancel" type="submit"><span class="btn-action__label">Cancelar solicitud</span><span class="btn-action__icon"><i class="bi bi-x-circle" aria-hidden="true"></i></span></button>
            </form>
        <?php elseif ($s['estado'] === 'PENDIENTE_APROBACION'): ?>
            <p class="mb-0 text-muted">Su rol no incluye la aprobación de fusiones con conflictos graves.</p>
        <?php else: ?>
            <p class="mb-0 text-muted">La solicitud ya está cerrada. Consta en el historial y en la auditoría.</p>
        <?php endif; ?>
    </div></div></div>
</div>
