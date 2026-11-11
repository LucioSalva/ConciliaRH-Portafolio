<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header"><div><h1>Fusiones de empleados</h1>
    <p class="page-header__sub">Historial completo: una fusión nunca se borra; una corrección queda como separación.</p></div></div>
<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-intersect" aria-hidden="true"></i></div><p class="empty-state__title">Aún no hay fusiones</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Historial de fusiones de empleados</caption>
        <thead><tr><th scope="col">#</th><th scope="col">Origen</th><th scope="col">Destino (canónico)</th><th scope="col">Confianza</th><th scope="col">Estado</th><th scope="col" class="col-hide-sm">Usuario · fecha</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
            <tr><td><a href="/empleados/fusiones/<?= e($r['id']) ?>">#<?= e($r['id']) ?></a></td>
                <td><a href="/empleados/<?= e($r['origen_id']) ?>"><?= e($r['origen_numero']) ?></a></td>
                <td><a href="/empleados/<?= e($r['destino_id']) ?>"><?= e($r['destino_numero']) ?></a><br><small><?= e($r['destino_nombre']) ?></small></td>
                <td><?= status_badge('CONF_' . $r['confianza']) ?></td><td><?= status_badge($r['estado']) ?></td>
                <td class="small col-hide-sm"><?= e($r['usuario']) ?> · <?= e(fmt_datetime($r['created_at'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>
