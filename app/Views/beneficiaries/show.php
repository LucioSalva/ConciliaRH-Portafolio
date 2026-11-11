<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$tabs = ['resumen' => ['Resumen', 'bi-person'], 'trabajadores' => ['Trabajadores', 'bi-diagram-2'], 'cuentas' => ['Cuentas', 'bi-credit-card'],
         'pensiones' => ['Pensiones', 'bi-cash-stack'], 'historial' => ['Historial', 'bi-clock-history']];
if ($this->can('auditoria.ver')) {
    $tabs['auditoria'] = ['Auditoría', 'bi-journal-check'];
}
$base = '/beneficiarios/' . (int) $b['id'];
$conf = static fn (string $o): string => match ($o) {
    'NUMERO_EMPLEADO_ARCHIVO', 'CUENTA_CONFIRMADA', 'EVIDENCIA_FUERTE' => status_badge('CUADRA', 'Fuerte'),
    'CONFIRMACION_MANUAL' => status_badge('SEV_INFO', 'Manual'), default => status_badge('SEV_BAJA', $o),
};
?>
<div class="page-header"><div><h1><?= e($b['nombre_original']) ?></h1>
    <p class="page-header__sub">Beneficiario · BEN-<?= e(sprintf('%06d', $b['id'])) ?> · <?= status_badge($b['estado'] === 'ACTIVO' ? 'ACTIVO' : 'INACTIVO') ?> · <?= $conf((string) $b['origen']) ?> · aparece de <?= e($b['primer_periodo']) ?> a <?= e($b['ultimo_periodo']) ?></p></div>
    <div class="page-header__actions"><a class="btn-action btn-cancel" href="/beneficiarios"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a></div></div>
<ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $k => [$lab, $ic]): ?><li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e($base) ?>?tab=<?= e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="bi <?= e($ic) ?> me-1" aria-hidden="true"></i><?= e($lab) ?></a></li><?php endforeach; ?>
</ul>

<?php if ($tab === 'resumen'): ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent">Datos del beneficiario</div><div class="card-body"><dl class="dl-grid">
        <dt>Nombre (como apareció)</dt><dd><?= e($b['nombre_original']) ?></dd>
        <dt>Nombre normalizado</dt><dd><?= e($b['nombre_normalizado']) ?></dd>
        <dt>Relación</dt><dd>Beneficiario de pensión alimenticia (parentesco no registrado)</dd>
        <dt>Origen</dt><dd><?= e(status_label((string) $b['origen'])) ?><?= $b['confirmado_por_usuario'] ? ' · ' . e($b['confirmado_por_usuario']) . ', ' . e(fmt_datetime($b['confirmado_at'])) : '' ?></dd>
        <dt>Trabajadores vigentes</dt><dd><?= e(count(array_filter($b['trabajadores'], static fn ($t) => $t['estado'] === 'ACTIVA'))) ?></dd>
        <dt>Cuentas</dt><dd><?= e(count($b['cuentas'])) ?></dd>
        <dt>Pagos de pensión</dt><dd><?= e($b['n_pensiones']) ?></dd>
    </dl></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent">Observaciones</div><div class="card-body">
        <?php if ($this->can('beneficiarios.editar')): ?>
            <form method="post" action="<?= e($base) ?>/observaciones"><?= $this->csrf() ?>
                <label class="visually-hidden" for="observaciones">Observaciones</label>
                <textarea class="form-control mb-2" id="observaciones" name="observaciones" rows="4" maxlength="1000"><?= e($b['observaciones'] ?? '') ?></textarea>
                <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button></form>
        <?php else: ?><p class="mb-0"><?= $b['observaciones'] ? nl2br(e($b['observaciones'])) : '<span class="text-muted">Sin observaciones.</span>' ?></p><?php endif; ?>
    </div></div></div>
</div>
<?php elseif ($tab === 'trabajadores'): ?>
<div class="table-card"><div class="table-responsive"><table class="table align-middle table-stack"><caption class="visually-hidden">Trabajadores relacionados</caption>
    <thead><tr><th scope="col">Trabajador</th><th scope="col">Origen · confianza</th><th scope="col">Vigencia</th><th scope="col">Evidencia</th><th scope="col">Estado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
    <tbody><?php foreach ($b['trabajadores'] as $t): ?>
        <tr><td><a href="/empleados/<?= e($t['empleado_id']) ?>"><?= e($t['numero_empleado']) ?></a> <?= e($t['nombre_completo']) ?><br><small class="text-muted"><?= e(ucfirst(strtolower((string) $t['grupo']))) ?></small></td>
            <td class="small"><?= e(status_label((string) $t['origen'])) ?><br><?= $conf((string) $t['origen']) ?><?= $t['confirmado_por_usuario'] ? '<br>' . e($t['confirmado_por_usuario']) . ' · ' . e(fmt_datetime($t['confirmado_at'])) : '' ?></td>
            <td class="small">desde <?= e($t['desde']) ?><?= $t['hasta'] ? '<br>hasta ' . e($t['hasta']) : '' ?></td>
            <td class="small"><?php foreach (['registro_id' => 'registro', 'archivo_id' => 'archivo', 'lote_id' => 'lote', 'fila' => 'fila', 'banco' => 'banco'] as $k => $l): if (isset($t['evidencia'][$k])): ?><?= e($l) ?> <?= e((string) $t['evidencia'][$k]) ?><br><?php endif; endforeach; ?>
                <?= $t['motivo'] ? '<span class="text-muted">' . e($t['motivo']) . '</span>' : '' ?></td>
            <td><?= status_badge((string) $t['estado']) ?><?= $t['estado'] === 'INACTIVA' ? '<br><small class="text-muted">' . e($t['motivo_desactivacion'] ?? '') . ' · ' . e($t['desactivado_por_usuario'] ?? 'sistema') . '</small>' : '' ?></td>
            <td class="cell-actions"><?php if ($t['estado'] === 'ACTIVA' && $this->can('beneficiarios.editar')): ?>
                <form method="post" action="<?= e($base) ?>/trabajadores/<?= e($t['id']) ?>/desactivar" data-confirm="La relación quedará inactiva y se conservará en el historial. Una relación correcta se vuelve a confirmar con una nueva decisión." data-confirm-title="Desactivar relación" data-confirm-variant="danger" data-confirm-reason="motivo"><?= $this->csrf() ?>
                    <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Desactivar</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button></form>
            <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
<?php elseif ($tab === 'cuentas'): ?>
<div class="table-card"><div class="table-responsive"><table class="table align-middle table-stack"><caption class="visually-hidden">Cuentas del beneficiario</caption>
    <thead><tr><th scope="col">Cuenta</th><th scope="col">Banco</th><th scope="col">Origen</th><th scope="col">Periodos</th><th scope="col">Estado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Revelar</span></th></tr></thead>
    <tbody><?php foreach ($b['cuentas'] as $c): ?>
        <tr><td><?= e($c['tipo']) ?> <code><?= isset($revealed[(int) $c['id']]) ? e($revealed[(int) $c['id']]) : e(\ConciliaRH\Domain\Identity\AccountVault::mask($c['cuenta_ultimos4'])) ?></code></td>
            <td class="small"><?= e($c['banco'] ?? '—') ?></td>
            <td class="small"><?= e(status_label((string) $c['origen'])) ?><?= $c['confirmado_por_usuario'] ? '<br>' . e($c['confirmado_por_usuario']) : '' ?></td>
            <td class="small"><?= e($c['primer_periodo']) ?> → <?= e($c['ultimo_periodo']) ?> · <?= e($c['veces_vista']) ?> archivo(s)</td>
            <td><?= status_badge((string) $c['estado']) ?><?= $c['desactivacion_motivo'] ? '<br><small class="text-muted">' . e($c['desactivacion_motivo']) . '</small>' : '' ?></td>
            <td class="cell-actions"><?php if ($this->can('cuentas.ver_sensible') && !isset($revealed[(int) $c['id']])): ?>
                <form method="post" action="<?= e($base) ?>/cuentas/<?= e($c['id']) ?>/revelar" data-confirm="La cuenta completa se mostrará una sola vez y el acceso quedará auditado." data-confirm-title="Revelar cuenta"><?= $this->csrf() ?>
                    <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Revelar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button></form>
            <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if ($b['cuentas'] === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin cuentas registradas</p></div><?php endif; ?></div>
<?php elseif ($tab === 'pensiones'): ?>
<div class="table-card"><div class="table-responsive"><table class="table align-middle table-stack"><caption class="visually-hidden">Pagos de pensión</caption>
    <thead><tr><th scope="col">Periodo · lote</th><th scope="col">Banco</th><th scope="col">Trabajador</th><th scope="col">Asociación</th><th scope="col" class="num">Importe</th></tr></thead>
    <tbody><?php foreach ($b['pensiones'] as $p): ?>
        <tr><td class="small"><?= e($p['periodo']) ?> · lote <?= e(sprintf('%03d', $p['lote_numero'])) ?><br><a href="/asociaciones/tesoreria/<?= e($p['id']) ?>">#<?= e($p['id']) ?></a><?= $p['importacion_estado'] !== 'VIGENTE' ? ' ' . status_badge((string) $p['importacion_estado'], 'Importación ' . mb_strtolower(status_label((string) $p['importacion_estado']))) : '' ?></td>
            <td class="small"><?= e($p['banco']) ?> <code><?= e(\ConciliaRH\Domain\Identity\AccountVault::mask($p['cuenta_ultimos4'])) ?></code></td>
            <td class="small"><?= $p['numero_empleado'] ? e($p['numero_empleado'] . ' ' . $p['nombre_completo']) : '—' ?></td>
            <td><?= status_badge((string) $p['asociacion_estado']) ?><br><small class="text-muted"><?= e(status_label((string) ($p['asociacion_metodo'] ?? ''))) ?></small></td>
            <td class="num"><?= money($p['importe']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
<?php elseif ($tab === 'historial'): ?>
<div class="table-card"><ul class="list-group list-group-flush small">
    <?php foreach ($b['historial'] as $h): ?><li class="list-group-item"><strong><?= e(status_label((string) $h['accion'])) ?></strong> · registro <a href="/asociaciones/tesoreria/<?= e($h['registro_id']) ?>">#<?= e($h['registro_id']) ?></a> · <?= e(status_label((string) ($h['estado_anterior'] ?? ''))) ?> → <?= e(status_label((string) $h['estado_nuevo'])) ?>
        <?= $h['accion_masiva_id'] ? ' · <a href="/revision-masiva/acciones/' . e($h['accion_masiva_id']) . '">acción masiva #' . e($h['accion_masiva_id']) . '</a>' : '' ?><br>
        <span class="text-muted"><?= e($h['username'] ?? 'motor') ?> · <?= e(fmt_datetime($h['created_at'])) ?><?= $h['motivo'] ? ' · ' . e($h['motivo']) : '' ?></span></li><?php endforeach; ?>
    <?php if ($b['historial'] === []): ?><li class="list-group-item"><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin movimientos</p></div></li><?php endif; ?></ul></div>
<?php else: ?>
<div class="table-card"><ul class="list-group list-group-flush small">
    <?php foreach ($b['auditoria'] as $a): ?><li class="list-group-item d-flex justify-content-between gap-2"><span class="min-w-0 text-break-any"><code><?= e($a['accion']) ?></code> · <?= e($a['entidad']) ?> · <?= e($a['username'] ?? 'sistema') ?></span><span class="text-muted"><?= e(fmt_datetime($a['creado_at'])) ?></span></li><?php endforeach; ?>
    <?php if ($b['auditoria'] === []): ?><li class="list-group-item"><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin registros</p></div></li><?php endif; ?></ul></div>
<?php endif; ?>
