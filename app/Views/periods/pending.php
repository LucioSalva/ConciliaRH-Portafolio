<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$pid = (int) $period['id'];
$bancos = ['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria'];
$prev = $bloque['preview'];
$listos = $prev !== null ? $prev['elegibles'] : [];
$total = '0';
foreach ($exactos as $x) {
    if (isset($listos[(int) $x['id']])) {
        $total = bcadd($total, (string) $x['importe'], 2);
    }
}
?>
<div class="page-header">
    <div>
        <h1>Pendientes de identificar</h1>
        <p class="page-header__sub"><?= e($period['descripcion']) ?>: pagos que el sistema no pudo atribuir a una persona por número de empleado.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/periodos/<?= e($pid) ?>"><span class="btn-action__label">Volver a la quincena</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<section class="section" aria-labelledby="exactos-t">
    <div class="table-card">
        <div class="table-card__toolbar">
            <h2 class="table-card__title" id="exactos-t">Coinciden en nombre e importe</h2>
            <span class="table-card__hint">Nombre idéntico a una sola persona y mismo importe que su neto de RH. Nunca se confirman por un nombre parecido.</span>
        </div>
        <?php if ($exactos === []): ?>
            <div class="empty-state empty-state--sm"><p class="mb-0">No hay pagos que cumplan las dos condiciones.</p></div>
        <?php else: ?>
            <div class="table-responsive table-scroll">
                <table class="table align-middle table-stack">
                    <caption class="visually-hidden">Pagos sin número de empleado que coinciden exactamente en nombre e importe con una persona de RH</caption>
                    <thead><tr><th scope="col">Pago (Tesorería)</th><th scope="col" class="col-hide-md">Archivo y fila</th><th scope="col" class="num">Importe</th>
                        <th scope="col">Persona de RH</th><th scope="col" class="num">Neto RH</th><th scope="col">Listo para confirmar</th></tr></thead>
                    <tbody>
                    <?php foreach ($exactos as $x): $ok = isset($listos[(int) $x['id']]); ?>
                        <tr<?= $ok ? '' : ' class="row-attention"' ?>>
                            <td class="cell-name"><?= e($x['nombre_original']) ?><br><small class="text-muted"><?= e($bancos[$x['banco']] ?? (string) $x['banco']) ?><?= $x['cuenta_ultimos4'] ? ' · cuenta terminada en ' . e($x['cuenta_ultimos4']) : '' ?></small></td>
                            <td class="col-hide-md"><?= e($x['archivo']) ?><br><small class="text-muted">fila <?= e($x['fila_origen']) ?></small></td>
                            <td class="num"><?= money((string) $x['importe']) ?></td>
                            <td class="cell-name"><a href="/empleados/<?= e($x['empleado_id']) ?>"><?= e($x['nombre_completo']) ?></a><br><small class="text-muted">núm. <?= e($x['numero_empleado']) ?></small></td>
                            <td class="num"><?= money((string) $x['neto']) ?></td>
                            <td><?= $ok ? status_badge('CORRECTO', 'Sí') : status_badge('REVISION_MANUAL', 'Revisar uno por uno') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($prev !== null && $bloque['items'] !== []): ?>
                <form method="post" action="/periodos/<?= e($pid) ?>/pendientes/confirmar" class="table-card__footer d-grid gap-2" data-busy
                      data-confirm="Se confirmarán <?= e(count($bloque['items'])) ?> pagos por <?= e(money_plain($total)) ?> y su cuenta quedará recordada para las próximas quincenas. Queda registrado en la auditoría." data-confirm-title="Confirmar pagos en bloque">
                    <?= $this->csrf() ?>
                    <input type="hidden" name="token" value="<?= e($prev['token']) ?>">
                    <?php foreach ($bloque['items'] as $it): ?><input type="hidden" name="items[]" value="<?= e($it) ?>"><?php endforeach; ?>
                    <label class="form-label mb-0" for="motivo-bloque">Motivo</label>
                    <textarea class="form-control" id="motivo-bloque" name="motivo" rows="2" maxlength="500" required minlength="5"><?= e($motivo) ?></textarea>
                    <div><button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Confirmar <?= e(count($bloque['items'])) ?> pagos (<?= e(money_plain($total)) ?>)</span><span class="btn-action__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></span></button></div>
                </form>
            <?php elseif (!$this->can('asociaciones.masivo')): ?>
                <p class="table-card__footer form-text mb-0">Su rol puede consultar, pero no confirmar en bloque.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="section" aria-labelledby="otros-t">
    <div class="table-card">
        <div class="table-card__toolbar">
            <h2 class="table-card__title" id="otros-t">Pagos por revisar uno por uno</h2>
            <span class="table-card__hint">Pensiones de archivos sin número de empleado: si el total de la población cuadra, el cotejo no las marca como error.</span>
        </div>
        <?php $resto = array_values(array_filter($tesoreria, static fn ($t) => !in_array((int) $t['id'], array_map(static fn ($x) => (int) $x['id'], $exactos), true))); ?>
        <?php if ($resto === []): ?>
            <div class="empty-state empty-state--sm"><p class="mb-0">No quedan pagos por revisar.</p></div>
        <?php else: ?>
            <div class="table-responsive table-scroll">
                <table class="table align-middle table-stack">
                    <caption class="visually-hidden">Pagos de Tesorería sin persona identificada (el detalle y las acciones están en «Revisar»)</caption>
                    <thead><tr><th scope="col">Tipo</th><th scope="col">Beneficiario en el archivo</th><th scope="col" class="col-hide-md">Archivo y fila</th><th scope="col" class="num">Importe</th><th scope="col">Situación</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($resto as $t): ?>
                        <tr>
                            <td><?= $t['categoria'] === 'PENSION' ? 'Pensión' : 'Nómina' ?><br><small class="text-muted"><?= e($bancos[$t['banco']] ?? (string) $t['banco']) ?></small></td>
                            <td class="cell-name"><?= e($t['nombre_original']) ?></td>
                            <td class="col-hide-md"><?= e($t['archivo']) ?><br><small class="text-muted">fila <?= e($t['fila_origen']) ?></small></td>
                            <td class="num"><?= money((string) $t['importe']) ?></td>
                            <td><?= status_badge((string) $t['asociacion_estado']) ?></td>
                            <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/asociaciones/tesoreria/<?= e($t['id']) ?>" aria-label="Revisar el pago de <?= e($t['nombre_original']) ?>"><span class="btn-action__label">Revisar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($rrhh !== []): ?>
    <section class="section" aria-labelledby="rrhh-t">
        <div class="table-card">
            <div class="table-card__toolbar"><h2 class="table-card__title" id="rrhh-t">Registros de RH sin persona</h2></div>
            <div class="table-responsive">
                <table class="table align-middle table-stack">
                    <caption class="visually-hidden">Registros de la nómina de RH que no se pudieron asociar a una persona</caption>
                    <thead><tr><th scope="col">Núm.</th><th scope="col">Nombre</th><th scope="col" class="col-hide-md">Archivo y fila</th><th scope="col" class="num">Neto</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rrhh as $r): ?>
                        <tr>
                            <td><?= e($r['numero_empleado'] ?? '—') ?></td>
                            <td class="cell-name"><?= e($r['nombre_original']) ?></td>
                            <td class="col-hide-md"><?= e($r['archivo']) ?><br><small class="text-muted">fila <?= e($r['fila_origen']) ?></small></td>
                            <td class="num"><?= money((string) $r['neto']) ?></td>
                            <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/asociaciones/rrhh/<?= e($r['id']) ?>" aria-label="Revisar el registro de <?= e($r['nombre_original']) ?>"><span class="btn-action__label">Revisar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>
