<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header"><div><h1>Acción masiva #<?= e($a['id']) ?> · <?= e(status_label((string) $a['accion'])) ?></h1>
    <p class="page-header__sub"><?= e($a['username']) ?> · <?= e(fmt_datetime($a['created_at'])) ?> · <?= e($a['registros']) ?> registro(s) · <?= money($a['importe_total']) ?> · huella <code><?= e(substr((string) $a['huella'], 0, 16)) ?>…</code></p></div>
    <div class="page-header__actions"><a class="btn-action btn-cancel" href="/revision-masiva"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a></div></div>
<div class="notice notice--info" role="note"><i class="bi bi-info-circle notice__icon" aria-hidden="true"></i><div class="notice__body">Motivo: <?= e($a['motivo']) ?>. Para corregir una decisión, tome una nueva decisión sobre el registro: el historial conserva ambas.</div></div>
<div class="table-card"><div class="table-responsive"><table class="table align-middle table-stack">
    <caption class="visually-hidden">Registros incluidos en la acción masiva</caption>
    <thead><tr><th scope="col">Registro</th><th scope="col">Nombre en el archivo</th><th scope="col">Antes</th><th scope="col">Después</th><th scope="col">Empleado</th><th scope="col" class="num">Importe</th></tr></thead>
    <tbody><?php foreach ($a['registros_detalle'] as $d): ?>
        <tr><td class="small"><a href="/asociaciones/tesoreria/<?= e($d['registro_id']) ?>">#<?= e($d['registro_id']) ?></a> · <?= e($d['banco']) ?> · <?= e(strtolower((string) $d['categoria'])) ?> · v<?= e($d['version_anterior']) ?></td>
            <td class="text-break"><?= e($d['nombre_original'] ?? '—') ?><?= $d['beneficiario_id'] ? '<br><a class="small" href="/beneficiarios/' . e($d['beneficiario_id']) . '">beneficiario #' . e($d['beneficiario_id']) . '</a>' : '' ?></td>
            <td><?= status_badge((string) $d['estado_anterior']) ?></td><td><?= status_badge((string) $d['estado_nuevo']) ?></td>
            <td class="small"><?= $d['numero_empleado'] ? e($d['numero_empleado'] . ' ' . $d['nombre_completo']) : '—' ?></td>
            <td class="num"><?= money($d['importe']) ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div></div>
