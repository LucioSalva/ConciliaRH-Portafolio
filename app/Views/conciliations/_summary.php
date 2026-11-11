<?php
/** @var array<string,mixed> $c */
$t = $c['totales']['TOTAL'] ?? null;
$rs = $c['resumen'];
$verInc = $this->can('incidencias.ver');
$inc = $verInc ? ($c['incidencias_por_severidad'] ?? []) : [];
$abiertas = array_sum(array_map('intval', array_diff_key($inc, ['INFO' => true])));
$base = '/conciliaciones/' . (int) $c['id'];
$dif = $t['diferencia_global_nomina'] ?? null;
$tol = (string) $c['tolerancia'];
$difCero = $dif !== null && bccomp(ltrim((string) $dif, '-'), $tol, 2) <= 0;
$flag = static fn (bool $alert): string => $alert
    ? '<i class="bi bi-exclamation-triangle-fill figure__flag" aria-hidden="true"></i><span class="visually-hidden">Requiere atención: </span>'
    : '<i class="bi bi-check-circle-fill figure__flag" aria-hidden="true"></i><span class="visually-hidden">Sin pendientes: </span>';
?>
<?php if ($t === null): ?>
    <div class="notice notice--info" role="status">
        <i class="bi bi-hourglass-split notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Cotejo <?= e(mb_strtolower(status_label((string) $c['estado']))) ?></p>
            Los importes aparecen cuando el motor termina. Recargue la página en unos momentos.</div>
    </div>
<?php else:
    $esperado = (string) $t['rrhh']['neto'];
    $pagado = (string) $t['tesoreria_nomina']['total'];
    $ident = (string) $t['tesoreria_nomina']['identificado'];
    $pend = (string) $t['tesoreria_nomina']['pendiente_identidad'];
    $escala = bccomp($esperado, $pagado, 2) >= 0 ? $esperado : $pagado;
    $pct = static fn (string $v): string => bccomp($escala, '0', 2) > 0 && bccomp($v, '0', 2) > 0 ? bcdiv(bcmul($v, '100', 6), $escala, 2) : '0';
    $absDif = ltrim((string) $dif, '-');
?>
<section class="cuadre" aria-labelledby="cuadre-t-<?= e($c['id']) ?>">
    <div class="cuadre__head">
        <h2 class="cuadre__title" id="cuadre-t-<?= e($c['id']) ?>">Cuadre de nómina</h2>
        <span class="cuadre__meta">Tolerancia <?= money($tol) ?> por persona y global</span>
    </div>
    <dl class="cuadre__rows">
        <dt class="cuadre__label">RH autorizó<small>Neto de <?= e(number_format((int) $t['rrhh']['registros'])) ?> registro(s)</small></dt>
        <dd class="cuadre__track" aria-hidden="true"><span class="cuadre__bar cuadre__bar--expected" data-progress="<?= e($pct($esperado)) ?>"></span></dd>
        <dd class="cuadre__amount"><?= money($esperado) ?></dd>

        <dt class="cuadre__label">Total pagado<small>Tesorería, nómina</small></dt>
        <dd class="cuadre__track" aria-hidden="true">
            <span class="cuadre__bar cuadre__bar--pending" data-progress="<?= e($pct($pagado)) ?>"></span>
            <span class="cuadre__bar cuadre__bar--paid" data-progress="<?= e($pct($ident)) ?>"></span>
        </dd>
        <dd class="cuadre__amount"><?= money($pagado) ?></dd>
    </dl>
    <div class="cuadre__result">
        <span class="cuadre__diff"><span class="visually-hidden">Diferencia: </span><?= money_diff($dif, $tol) ?></span>
        <p class="cuadre__verdict mb-0">
            <?php if ($difCero): ?>
                <?= status_badge('CUADRA', 'Cuadra') ?> Lo pagado coincide con lo autorizado dentro de la tolerancia.
            <?php elseif (str_starts_with((string) $dif, '-')): ?>
                <?= status_badge('NO_CUADRA', 'No cuadra') ?> Tesorería pagó <strong><?= e(money_plain($absDif)) ?> menos</strong> de lo que RH autorizó.
            <?php else: ?>
                <?= status_badge('NO_CUADRA', 'No cuadra') ?> Tesorería pagó <strong><?= e(money_plain($absDif)) ?> más</strong> de lo que RH autorizó.
            <?php endif; ?>
            <?php if (bccomp($pend, '0', 2) > 0): ?>
                De lo pagado, <strong><?= e(money_plain($pend)) ?></strong> aún no tiene persona asignada (tramo rayado).
            <?php endif; ?>
        </p>
    </div>
</section>

<div class="figures">
    <div class="figure <?= ($rs['pct_conciliado'] ?? null) === '100.00' ? 'figure--ok' : '' ?>">
        <p class="figure__label">% conciliado</p>
        <p class="figure__value"><?= ($rs['pct_conciliado'] ?? null) === '100.00' ? $flag(false) : '' ?><?= $rs['pct_conciliado'] !== null ? e($rs['pct_conciliado']) . ' %' : '—' ?></p>
        <p class="figure__hint"><a href="<?= e($base) ?>/empleados?estado=CORRECTO"><?= e($rs['correctos']) ?></a> de <?= e($rs['evaluables']) ?> personas correctas</p>
    </div>
    <div class="figure">
        <p class="figure__label">Pagado identificado</p>
        <p class="figure__value"><?= money($ident) ?></p>
        <p class="figure__hint">Pagos asociados a una persona</p>
    </div>
    <div class="figure <?= bccomp($pend, '0', 2) > 0 ? 'figure--alert' : '' ?>">
        <p class="figure__label">Pendiente de identificar</p>
        <p class="figure__value"><?= bccomp($pend, '0', 2) > 0 ? $flag(true) : '' ?><?= money($pend) ?></p>
        <p class="figure__hint"><?php if ((int) $rs['pendientes_identidad'] > 0): ?><a href="<?= e($base) ?>/empleados?estado=PENDIENTE_IDENTIDAD"><?= e($rs['pendientes_identidad']) ?> persona(s)</a> (<?= e($rs['pct_pendiente_identidad']) ?> %)<?php elseif (bccomp($pend, '0', 2) > 0): ?>Pagos que aún no se asignan a una persona<?php else: ?>Todo pago tiene persona<?php endif; ?></p>
    </div>
    <?php if ($verInc): ?>
    <div class="figure <?= ($inc['CRITICA'] ?? 0) + ($inc['ERROR'] ?? 0) > 0 ? 'figure--alert' : ($abiertas === 0 ? 'figure--ok' : '') ?>">
        <p class="figure__label">Por atender</p>
        <p class="figure__value"><?= ($inc['CRITICA'] ?? 0) + ($inc['ERROR'] ?? 0) > 0 ? $flag(true) : ($abiertas === 0 ? $flag(false) : '') ?><a href="<?= e($base) ?>/incidencias"><?= e($abiertas) ?></a></p>
        <p class="figure__hint">
            <?php foreach (['CRITICA', 'ERROR', 'ADVERTENCIA'] as $s): if (!empty($inc[$s])): ?>
                <a class="text-decoration-none" href="<?= e($base) ?>/incidencias?severidad=<?= e($s) ?>"><?= status_badge('SEV_' . $s, ucfirst(strtolower($s)) . ': ' . $inc[$s]) ?></a>
            <?php endif; endforeach; ?>
            <?php if ($abiertas === 0): ?>Nada por atender<?php endif; ?>
            <?php if (!empty($inc['INFO'])): ?><a class="d-block small" href="<?= e($base) ?>/incidencias?severidad=INFO"><?= e(number_format((int) $inc['INFO'])) ?> avisos informativos</a><?php endif; ?>
        </p>
    </div>
    <?php endif; ?>
</div>
<p class="footnote">
    % conciliado = personas correctas ÷ personas evaluables (sólo se excluyen las que no tenían pago que realizar). Las pendientes de identificar no cuentan como correctas ni como error.
    <?php if (($rs['pct_monto_conciliado'] ?? null) !== null): ?>Por importe, <?= e($rs['pct_monto_conciliado']) ?> % del neto esperado está en personas correctas.<?php endif; ?></p>
<?php endif; ?>
