<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$nombres = ['aplicacion' => ['Aplicación', 'bi-box'], 'base_datos' => ['Base de datos', 'bi-database'], 'migraciones' => ['Migraciones', 'bi-layers'],
    'workers' => ['Procesador en segundo plano', 'bi-cpu'], 'cola' => ['Cola de trabajos', 'bi-list-task'], 'scheduler' => ['Scheduler', 'bi-clock-history'],
    'almacenamiento' => ['Revisión de almacenamiento', 'bi-hdd-stack'], 'espacio' => ['Espacio en disco', 'bi-device-hdd'],
    'reportes' => ['Reportes generados', 'bi-file-earmark-lock'], 'respaldo' => ['Respaldo', 'bi-shield-check']];
$titulo = ['OK' => 'El sistema opera con normalidad', 'ADVERTENCIA' => 'Hay puntos que requieren atención', 'ERROR' => 'Hay fallas que requieren acción'];
?>
<div class="page-header">
    <div>
        <h1>Salud del sistema</h1>
        <p class="page-header__sub">Estado de los componentes de ConciliaRH. Sólo estados, conteos y versiones: nunca secretos ni datos de personas.</p>
    </div>
    <?php if ($puedeMantener): ?>
        <form method="post" action="/sistema/salud/almacenamiento" data-busy><?= $this->csrf() ?>
            <button class="btn-action btn-view" type="submit"><span class="btn-action__label">Revisar almacenamiento ahora</span><span class="btn-action__icon"><i class="bi bi-hdd-stack" aria-hidden="true"></i></span></button></form>
    <?php endif; ?>
</div>

<div class="notice notice--<?= $general === 'OK' ? 'success' : ($general === 'ERROR' ? 'error' : 'warning') ?>" role="status">
    <i class="bi <?= $general === 'OK' ? 'bi-check-circle-fill' : ($general === 'ERROR' ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill') ?> notice__icon" aria-hidden="true"></i>
    <div class="notice__body"><p class="notice__title"><?= e($titulo[$general] ?? $general) ?></p>Versión <?= e($checks['aplicacion']['version']) ?><?php $b = $checks['aplicacion']['build'] ?? null; ?><?= $b && $b['commit'] ? ' · build <code>' . e(substr($b['commit'], 0, 12)) . '</code>' . ($b['built_at'] ? ' del ' . e($b['built_at']) : '') : ' · build de desarrollo' ?> · PHP <?= e($checks['aplicacion']['php']) ?><?= isset($checks['base_datos']['version']) ? ' · PostgreSQL ' . e($checks['base_datos']['version']) : '' ?></div>
</div>

<div class="table-card mb-4">
    <div class="table-responsive"><table class="table align-middle table-stack">
        <caption class="visually-hidden">Verificaciones de salud</caption>
        <thead><tr><th scope="col">Componente</th><th scope="col">Estado</th><th scope="col">Detalle</th><th scope="col">Última actividad</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $k => $c): [$n, $icon] = $nombres[$k] ?? [$k, 'bi-circle']; ?>
            <tr<?= in_array($c['estado'], ['ERROR', 'ADVERTENCIA'], true) ? ' class="row-attention"' : '' ?>>
                <th scope="row"><i class="bi <?= e($icon) ?> text-gold me-2" aria-hidden="true"></i><?= e($n) ?></th>
                <td><?= status_badge('SALUD_' . $c['estado']) ?></td>
                <td><?= e($c['detalle'] ?? '') ?></td>
                <td><?php $when = is_array($c['ultima'] ?? null) ? ($c['ultima']['aplicada_at'] ?? null) : ($c['ultima'] ?? ($c['ultimo_latido'] ?? null)); ?><?= $when ? e(fmt_datetime($when)) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6"><section class="card h-100" aria-labelledby="wk-t"><div class="card-header card-header--accent" id="wk-t">Procesadores en segundo plano</div>
        <?php if ($workers === []): ?><div class="empty-state empty-state--sm"><p class="mb-0">Ningún procesador se ha registrado todavía.</p></div><?php else: ?>
        <div class="table-responsive"><table class="table align-middle mb-0 table-stack"><caption class="visually-hidden">Procesadores en segundo plano</caption><thead><tr><th scope="col">Procesador</th><th scope="col">Versión</th><th scope="col">Último latido</th><th scope="col">Estado</th></tr></thead><tbody>
            <?php foreach ($workers as $w): ?><tr><td class="text-break-any"><?= e($w['hostname']) ?></td><td><?= e($w['version']) ?></td>
                <td><?= e(fmt_datetime($w['ultimo_latido_at'], true)) ?></td>
                <td><?= $w['vivo'] ? status_badge('ACTIVO', 'Vivo') : status_badge($w['detenido_at'] ? 'INACTIVO' : 'ERROR', $w['detenido_at'] ? 'Detenido' : 'Sin latido') ?><?= $w['proceso_actual_id'] ? ' <small class="text-muted">proceso #' . e($w['proceso_actual_id']) . '</small>' : '' ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section></div>
    <div class="col-12 col-xl-6"><section class="card h-100" aria-labelledby="er-t"><div class="card-header card-header--accent" id="er-t">Errores recientes y trabajos atascados</div>
        <?php if ($errores === []): ?><div class="empty-state empty-state--sm"><p class="mb-0">Sin errores en las últimas 24 horas ni trabajos atascados.</p></div><?php else: ?>
        <div class="table-responsive"><table class="table align-middle mb-0 table-stack"><caption class="visually-hidden">Procesos con error en 24 horas y trabajos atascados</caption><thead><tr><th scope="col">Proceso</th><th scope="col">Estado</th><th scope="col">Código</th><th scope="col">Fecha</th></tr></thead><tbody>
            <?php foreach ($errores as $p): ?><tr><td><a href="/procesos/<?= e($p['id']) ?>">#<?= e($p['id']) ?> · <?= e($p['tipo']) ?></a></td>
                <td><?= status_badge((string) $p['estado'], $p['estado'] === 'EN_EJECUCION' ? 'Atascado' : null) ?></td>
                <td><code><?= e($p['error_codigo'] ?? '—') ?></code></td><td><?= e(fmt_datetime($p['finalizado_at'] ?? $p['created_at'])) ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section></div>
</div>
