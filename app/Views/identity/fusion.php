<?php
/** @var \ConciliaRH\Core\Template $this */
use ConciliaRH\Domain\Identity\MergeService;

$this->layout('layouts/app', ['title' => $title]);
$a = $f['analisis'];
?>
<div class="page-header"><div><h1>Fusión #<?= e($f['id']) ?> <?= status_badge($f['estado']) ?></h1>
    <p class="page-header__sub"><a href="/empleados/<?= e($f['origen_id']) ?>"><?= e($f['origen_numero']) ?></a> (<?= e($f['origen_nombre']) ?>) →
        <a href="/empleados/<?= e($f['destino_id']) ?>"><?= e($f['destino_numero']) ?></a> (<?= e($f['destino_nombre']) ?>), por <?= e($f['usuario']) ?> el <?= e(fmt_datetime($f['created_at'])) ?>.</p></div></div>
<div class="row g-3">
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header card-header--accent">Decisión</div><div class="card-body">
        <dl class="dl-grid">
            <dt>Confianza</dt><dd><?= status_badge('CONF_' . $f['confianza']) ?></dd>
            <dt>Motivo</dt><dd><?= e($f['motivo']) ?></dd>
            <dt>Evidencias</dt><dd><?= e(implode(' · ', array_map(static fn ($x) => MergeService::EVIDENCIAS[$x['codigo']] ?? $x['codigo'], $a['evidencias']))) ?></dd>
            <dt>Conflictos</dt><dd><?= $a['conflictos'] === [] ? '—' : e(implode(' · ', array_map(static fn ($x) => (MergeService::CONFLICTOS[$x['codigo']] ?? $x['codigo']) . ' (' . strtolower($x['severidad']) . ')', $a['conflictos']))) ?></dd>
            <dt>Conflictos aceptados</dt><dd><?php $acept = array_filter(explode(',', trim((string) $f['conflictos_aceptados'], '{}'))); ?><?= $acept === [] ? '—' : e(implode(' · ', array_map(static fn ($x) => MergeService::CONFLICTOS[$x] ?? $x, $acept))) ?></dd>
            <dt>Re-apuntados</dt><dd><?= e(trim((string) $f['reapuntados'], '{}') ?: '—') ?></dd>
            <dt>Huella del análisis</dt><dd><code class="small"><?= e(substr($f['huella'], 0, 16)) ?>…</code> · versión <?= e($f['version']) ?></dd>
            <?php if ($f['estado'] === 'SEPARADA'): ?>
                <dt>Separada</dt><dd><?= e($f['separada_por_usuario']) ?> · <?= e(fmt_datetime($f['separada_at'])) ?><br><?= e($f['motivo_separacion']) ?></dd>
            <?php endif; ?>
        </dl></div></div></div>
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header card-header--accent">Efecto</div><div class="card-body">
        <p class="small">No se movió ni se borró ningún registro. El origen quedó como <strong>alias</strong> del destino: sus registros, cuentas, pensiones e historial
            siguen ligados a él y las consultas actuales los muestran bajo la identidad canónica. Los cotejos anteriores no cambian; al volver a cotejar cada
            quincena afectada se usará la identidad canónica y el comparativo lo explicará.</p>
        <?php if ($f['estado'] === 'APLICADA' && $puedeSeparar): ?>
            <form method="post" action="/empleados/fusiones/<?= e($f['id']) ?>/separar" data-confirm="El origen volverá a ser un empleado independiente. La fusión y la separación quedan en el historial." data-confirm-title="Separar fusión (decisión correctiva)" data-confirm-variant="danger">
                <?= $this->csrf() ?>
                <label class="form-label small" for="motivo">Motivo de la separación (mínimo 20 caracteres)</label>
                <textarea class="form-control mb-2" id="motivo" name="motivo" rows="2" minlength="20" maxlength="2000" required></textarea>
                <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Separar</span><span class="btn-action__icon"><i class="bi bi-arrows-expand" aria-hidden="true"></i></span></button>
            </form>
        <?php endif; ?>
    </div></div></div>
</div>
