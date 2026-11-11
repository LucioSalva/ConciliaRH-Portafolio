<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$ev = $c['asociacion_evidencia'];
$eventos = ['PAGO' => 'Pago', 'CUENTA_CONFIRMADA' => 'CLABE registrada'];
$periodos = array_values(array_unique(array_filter(array_map(static fn ($h) => $h['evento'] === 'PAGO' ? $h['periodo'] : null, $c['historial']))));
?>
<div class="page-header">
    <div>
        <h1>Pensión #<?= e($c['id']) ?></h1>
        <p class="page-header__sub"><?= e($c['periodo']) ?>, lote <?= e(sprintf('%03d', $c['lote_numero'])) ?> · archivo #<?= e($c['archivo_id']) ?>, fila <?= e($c['fila_origen']) ?></p>
    </div>
    <a class="btn-action btn-cancel" href="/revision-pensiones"><span class="btn-action__label">Volver a la bandeja</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
</div>

<?php if (!$c['es_media']): ?>
    <div class="notice notice--info" role="status"><i class="bi bi-info-circle-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Este caso ya no está pendiente</p>Su estado actual es <?= status_badge((string) $c['asociacion_estado']) ?>. Consulte el detalle en Asociaciones.</div></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-xl-7">
        <section class="card h-100" aria-labelledby="caso-t"><div class="card-header card-header--accent" id="caso-t">El pago y la propuesta</div><div class="card-body">
            <dl class="dl-grid">
                <dt>Beneficiario (en el archivo)</dt><dd><?= e($c['nombre_original'] ?? '—') ?><?= $c['beneficiario_nombre'] ? '<br><small class="text-muted">Coincide con el beneficiario registrado «' . e($c['beneficiario_nombre']) . '»</small>' : '' ?></dd>
                <dt>Trabajador candidato</dt><dd><?php if ($c['candidato']): ?><a href="/empleados/<?= e($c['candidato']['id']) ?>"><?= e($c['candidato']['numero_empleado']) ?></a> · <?= e($c['candidato']['nombre_completo']) ?> <span class="text-muted">(<?= e(ucfirst(strtolower((string) $c['candidato']['grupo']))) ?>)</span><?php else: ?>—<?php endif; ?></dd>
                <dt>Importe</dt><dd class="money"><?= money($c['importe']) ?></dd>
                <dt>Banco · CLABE</dt><dd><?= e(bank_label($c['banco'])) ?><?= $c['banco_receptor'] ? ' → ' . e($c['banco_receptor']) : '' ?> · <span class="font-mono">••••<?= e($c['cuenta_ultimos4'] ?? '—') ?></span></dd>
                <dt>Periodos con esta CLABE</dt><dd><?= $periodos === [] ? '—' : e(implode(' · ', $periodos)) ?></dd>
            </dl>
        </div></section>
    </div>
    <div class="col-12 col-xl-5">
        <section class="card h-100" aria-labelledby="ev-t"><div class="card-header card-header--accent" id="ev-t">Por qué se sugiere</div><div class="card-body">
            <?= $this->insert('partials/evidence', ['nivel' => (string) ($ev['categoria'] ?? 'SIN_EVIDENCIA'), 'razon' => $c['motivo_texto'], 'senales' => count($c['senales_texto']), 'compact' => false]) ?>
            <ul class="evidence-list mt-3">
                <?php foreach ($c['senales_texto'] as $s): ?><li><i class="bi bi-check2" aria-hidden="true"></i> <?= e($s['texto']) ?></li><?php endforeach; ?>
            </ul>
            <p class="small text-muted mb-0">MEDIA significa que dos señales independientes coinciden en el mismo trabajador. No basta para confirmar sola: revise la historia y decida.</p>
            <?php if (!empty($ev['demo'])): ?>
                <div class="notice notice--info mt-3 mb-0" role="note"><i class="bi bi-cone-striped notice__icon" aria-hidden="true"></i>
                    <div class="notice__body"><p class="notice__title">Evidencia de demostración</p>Estas señales se generaron con <code>make demo-dev</code> para mostrar la pantalla; no provienen del motor. La historia de abajo es la real.</div></div>
            <?php endif; ?>
        </div></section>
    </div>
</div>

<section class="card mb-3" aria-labelledby="tl-t"><div class="card-header card-header--accent" id="tl-t">Historia de esta CLABE</div><div class="card-body">
    <?php if ($c['historial'] === []): ?>
        <p class="text-muted mb-0">Sin otros movimientos con esta CLABE.</p>
    <?php else: ?>
        <ol class="timeline">
            <?php foreach ($c['historial'] as $h): ?>
                <li class="timeline__item<?= $h['es_actual'] ? ' timeline__item--current' : '' ?>">
                    <p class="timeline__when"><?= e($h['periodo'] ?? fmt_datetime($h['fecha'])) ?><?= $h['lote_numero'] !== null ? ', lote ' . e(sprintf('%03d', $h['lote_numero'])) : '' ?></p>
                    <p class="timeline__what"><?= e($eventos[$h['evento']] ?? $h['evento']) ?><?= $h['importe'] !== null ? ' de ' . money($h['importe']) : '' ?>
                        <?= $h['es_actual'] ? '<strong>(este pago)</strong>' : '' ?></p>
                    <p class="timeline__detail">
                        <?= $h['evento'] === 'PAGO' ? status_badge((string) $h['estado']) : status_badge((string) $h['estado'], 'Cuenta ' . mb_strtolower(status_label((string) $h['estado']))) ?>
                        <?= $h['empleado_numero'] ? ' Trabajador ' . e($h['empleado_numero']) . '.' : '' ?>
                        <?= $h['beneficiario_confirmado'] ? ' Beneficiario confirmado.' : '' ?>
                        <?= $h['mismo_nombre'] === true && !$h['es_actual'] ? ' Mismo nombre de beneficiario.' : '' ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div></section>

<?php if ($c['decisiones'] !== []): ?>
<section class="card mb-3" aria-labelledby="dec-t"><div class="card-header" id="dec-t">Revisiones anteriores</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($c['decisiones'] as $d): ?><li class="list-group-item"><strong>Dejado pendiente</strong> por <?= e($d['username']) ?> el <?= e(fmt_datetime($d['created_at'])) ?>: <?= e($d['motivo']) ?></li><?php endforeach; ?>
    </ul></section>
<?php endif; ?>

<?php if ($c['es_media'] && $puedeDecidir): ?>
<section class="decision-bar" aria-labelledby="decide-t">
    <h2 class="h6 m-0" id="decide-t">Su decisión</h2>
    <?php if ($puedeConfirmar): ?>
        <form method="post" action="/revision-pensiones/<?= e($c['id']) ?>/confirmar" data-confirm="Se asociará el pago al trabajador <?= e($c['candidato']['numero_empleado'] ?? '') ?> y se registrará la CLABE del beneficiario: los periodos siguientes la reconocerán automáticamente." data-confirm-title="Confirmar pensión" data-confirm-reason="motivo">
            <?= $this->csrf() ?><input type="hidden" name="version" value="<?= e($c['version']) ?>">
            <button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Confirmar</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button></form>
    <?php endif; ?>
    <form method="post" action="/revision-pensiones/<?= e($c['id']) ?>/rechazar" data-confirm="Este trabajador no se volverá a proponer para este pago." data-confirm-title="Rechazar la sugerencia" data-confirm-variant="danger" data-confirm-reason="motivo">
        <?= $this->csrf() ?><input type="hidden" name="version" value="<?= e($c['version']) ?>">
        <button class="btn-action btn-delete" type="submit"><span class="btn-action__label">Rechazar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></button></form>
    <form method="post" action="/revision-pensiones/<?= e($c['id']) ?>/pendiente" data-confirm="La asociación no cambia; quedará registrado que lo revisó y por qué lo deja pendiente." data-confirm-title="Dejar pendiente" data-confirm-reason="motivo">
        <?= $this->csrf() ?><input type="hidden" name="version" value="<?= e($c['version']) ?>">
        <button class="btn-action btn-cancel" type="submit"><span class="btn-action__label">Dejar pendiente</span><span class="btn-action__icon"><i class="bi bi-pause" aria-hidden="true"></i></span></button></form>
    <?php if (!$puedeConfirmar): ?><p class="small text-muted mb-0">Confirmar requiere además los permisos de beneficiarios y cuentas.</p><?php endif; ?>
</section>
<?php endif; ?>
