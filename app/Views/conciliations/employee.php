<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$base = '/conciliaciones/' . (int) $c['id'];
$n = $d['nomina'];
$p = $d['pension'];
$money = static fn (mixed $v): string => money($v);
$tipos = ['RRHH' => 'RH: neto autorizado', 'TESORERIA_NOMINA' => 'Tesorería: pago de nómina', 'TESORERIA_PENSION' => 'Tesorería: pensión',
          'RRHH_PENSION' => 'RH: retención de pensión', 'PENDIENTE_NOMINA' => 'Pago sin identificar (posible)', 'PENDIENTE_PENSION' => 'Pensión sin identificar (posible)'];
$criterios = ['NUMERO_EMPLEADO' => 'Por número de empleado', 'RFC' => 'Por RFC', 'CURP' => 'Por CURP', 'CUENTA' => 'Por cuenta confirmada antes',
              'MANUAL' => 'Confirmado por una persona', 'ALTA_RRHH' => 'Registro de RH', 'NOMBRE_EXACTO' => 'Nombre idéntico, sin confirmar'];
$bancos = ['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria'];
?>
<div class="page-header">
    <div>
        <h1><?= e($d['empleado']['nombre_completo'] ?? '') ?></h1>
        <p class="page-header__sub">Núm. <?= e($d['empleado']['numero_empleado'] ?? '') ?> · <?= e($c['periodo']) ?> · cotejo del <?= e(fmt_datetime($c['created_at'])) ?>
            <?php if ($n): ?> · RFC <?= e($n['rfc'] ?? '—') ?> · CURP <?= e($n['curp'] ?? '—') ?><?php endif; ?></p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-view" href="/empleados/<?= e((int) ($n['empleado_id'] ?? $p['empleado_id'])) ?>"><span class="btn-action__label">Ficha</span><span class="btn-action__icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span></a>
        <a class="btn-action btn-cancel" href="<?= e($base) ?>/empleados"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php if ($n): ?>
    <div class="col-lg-7">
        <div class="card h-100"><div class="card-header card-header--accent d-flex justify-content-between"><span>Nómina</span><?= status_badge((string) $n['estado']) ?></div>
            <div class="card-body">
                <div class="explain mb-3">
                    <strong>Esperado</strong> (neto de RH) <?= $money($n['neto_esperado']) ?> · <strong>Pagado</strong> <?= $money($n['pagado_total']) ?> en <?= e($n['pagos']) ?> pago(s)
                    · <strong>Diferencia</strong> = pagado − esperado = <?= money_diff($n['diferencia'], (string) $c['tolerancia']) ?>
                    <br><small class="text-muted">Regla: si la diferencia no pasa de <?= money($c['tolerancia']) ?>, el pago es correcto. <?= e($n['detalle']['regla'] ?? '') ?></small>
                    <?php if (!empty($n['detalle']['estado_matematico']) && $n['detalle']['estado_matematico'] !== $n['estado']): ?>
                        <br><small>Resultado matemático: <?= status_badge((string) $n['detalle']['estado_matematico']) ?> — se muestra como <?= e(status_label((string) $n['estado'])) ?> porque hay un pago sin identificar de <?= $money($n['posible_pendiente']) ?> que completaría exactamente el neto. No se asigna a la persona hasta confirmar su identidad.</small>
                    <?php elseif (bccomp((string) $n['posible_pendiente'], '0', 2) !== 0): ?>
                        <br><small>Posible pago pendiente de identidad: <?= $money($n['posible_pendiente']) ?> (no se suma al pagado).</small>
                    <?php endif; ?>
                    <?php if (!empty($n['detalle']['duplicado'])): ?><br><small>Posible duplicado: <?= e(implode(', ', (array) ($n['detalle']['duplicado']['senales'] ?? []))) ?>.</small><?php endif; ?>
                </div>
                <div class="bank-grid mb-2">
                    <?php foreach (['SANTANDER' => 'pagado_santander', 'BBVA' => 'pagado_bbva', 'INTERBANCARIA' => 'pagado_interbancaria', 'OTROS' => 'pagado_otros'] as $bk => $col): ?>
                        <div class="bank-grid__item"><div class="bank-grid__label"><?= e(bank_label($bk)) ?></div><div class="bank-grid__value"><?= $money($n[$col]) ?></div></div>
                    <?php endforeach; ?>
                </div>
                <dl class="dl-grid dl-grid--num">
                    <dt>Percepciones RH</dt><dd><?= $money($n['percepciones']) ?></dd>
                    <dt>Deducciones RH</dt><dd><?= $money($n['deducciones']) ?></dd>
                    <dt>Área</dt><dd><?= e($n['area'] ?? '—') ?></dd>
                    <?php if ($n['alerta']): ?><dt>Alerta</dt><dd><?= e(status_label((string) $n['alerta'])) ?></dd><?php endif; ?>
                </dl>
            </div></div>
    </div>
    <?php endif; ?>
    <?php if ($p): ?>
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-header card-header--accent d-flex justify-content-between"><span>Pensión alimenticia</span><?= status_badge((string) $p['estado']) ?></div>
            <div class="card-body"><div class="explain">
                <strong>Retenido</strong> (RH) <?= $money($p['retenido']) ?> · <strong>Dispersado</strong> <?= $money($p['dispersado']) ?> en <?= e($p['dispersiones']) ?> dispersión(es)
                · <strong>Diferencia</strong> = dispersado − retenido = <?= money_diff($p['diferencia'], (string) $c['tolerancia']) ?>
                <br><small class="text-muted">La pensión se concilia por separado: no forma parte del neto de nómina.</small>
            </div></div></div>
    </div>
    <?php endif; ?>
</div>

<div class="table-card mb-3">
    <div class="table-card__toolbar"><h2 class="table-card__title">De dónde sale cada importe</h2></div>
    <div class="table-responsive"><table class="table align-middle table-stack">
        <caption class="visually-hidden">Fuentes exactas usadas en el cálculo</caption>
        <thead><tr><th scope="col">Tipo</th><th scope="col">Archivo · hoja · fila</th><th scope="col">Banco / concepto</th><th scope="col" class="num">Original</th><th scope="col" class="num">Importe</th><th scope="col">Cómo se identificó</th></tr></thead>
        <tbody>
        <?php foreach ($d['fuentes'] as $f): ?>
            <tr>
                <td class="small"><?= e($tipos[$f['tipo']] ?? $f['tipo']) ?></td>
                <td class="small"><a href="/importaciones/<?= e($f['archivo_id']) ?>"><?= e($f['nombre_original']) ?></a><br><span class="text-muted"><?= e($f['hoja_nombre'] ?? '') ?><?= $f['fila_origen'] ? ' · fila ' . e($f['fila_origen']) : '' ?></span></td>
                <td class="small"><?= e($bancos[$f['banco']] ?? (string) ($f['banco'] ?? '')) ?><?= $f['codigo_concepto'] ? ' ' . e($f['codigo_concepto']) : '' ?><?= $f['cuenta_ultimos4'] ? '<br><span class="text-muted">cuenta terminada en ' . e($f['cuenta_ultimos4']) . '</span>' : '' ?></td>
                <td class="num"><?= $f['importe_original'] !== null ? $money($f['importe_original']) : '' ?></td>
                <td class="num"><?= $money($f['importe']) ?></td>
                <td class="small"><?= $f['asociacion_estado'] ? status_badge((string) $f['asociacion_estado']) : '' ?>
                    <?php if ($f['metodo'] !== null): ?><br><span class="text-muted"><?= e($criterios[$f['metodo']] ?? (string) $f['metodo']) ?><?= $f['metodo'] === 'MANUAL' && $f['asociado_at'] ? ' el ' . e(fmt_datetime($f['asociado_at'])) : '' ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="row g-3">
    <?php if ($d['conceptos'] !== []): ?>
    <div class="col-lg-6"><details class="table-card">
        <summary class="table-card__toolbar"><h2 class="table-card__title">Conceptos de RH (<?= count($d['conceptos']) ?>)</h2></summary>
        <div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Conceptos RRHH de la persona</caption>
            <thead><tr><th scope="col">Código</th><th scope="col">Concepto</th><th scope="col">Tipo</th><th scope="col" class="num">Importe (original)</th></tr></thead>
            <tbody><?php foreach ($d['conceptos'] as $k): ?>
                <tr><td><?= e($k['codigo']) ?></td><td class="small"><?= e($k['descripcion']) ?><?= $k['es_pension'] ? ' ' . status_badge('PENSION') : '' ?></td><td class="small"><?= e($k['tipo'] ?? '') ?></td><td class="num"><?= $money($k['importe']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </details></div>
    <?php endif; ?>
    <?php if ($d['incidencias'] !== null): ?>
    <div class="col-lg-6"><div class="table-card">
        <div class="table-card__toolbar"><h2 class="table-card__title">Incidencias de la persona</h2></div>
        <?php if ($d['incidencias'] === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin incidencias</p></div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($d['incidencias'] as $i): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2"><span><?= status_badge('SEV_' . $i['severidad']) ?> <?= e($i['mensaje']) ?> <?= $i['importe'] !== null ? $money($i['importe']) : '' ?></span><?= status_badge((string) $i['estado']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
