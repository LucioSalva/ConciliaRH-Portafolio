<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$dias = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
$frec = static function (array $r) use ($dias): string {
    return match ($r['frecuencia']) {
        'MANUAL' => 'Manual',
        'DIARIO' => sprintf('Diario, %02d:00', $r['hora']),
        'SEMANAL' => sprintf('Cada %s, %02d:00', $dias[(int) $r['dia_semana']] ?? '?', $r['hora']),
        'QUINCENAL' => sprintf('Días 1 y 16, %02d:00', $r['hora']),
        'MENSUAL' => sprintf('Día %d, %02d:00', $r['dia_mes'], $r['hora']),
        default => $r['frecuencia'],
    };
};
?>
<div class="page-header"><div><h1>Reportes programados</h1>
    <p class="page-header__sub">Se generan en segundo plano con los permisos vigentes de su dueño y quedan en <a href="/reportes/generados">Reportes generados</a>. Sin correo electrónico en esta versión.</p></div>
    <div class="page-header__actions">
        <?php if ($admin): ?><a class="btn-action btn-view" href="/reportes/programados<?= $todos ? '' : '?todos=1' ?>"><span class="btn-action__label"><?= $todos ? 'Sólo las mías' : 'Ver todas' ?></span><span class="btn-action__icon"><i class="bi bi-people" aria-hidden="true"></i></span></a><?php endif; ?>
        <a class="btn-action btn-add" href="/reportes/programados/nuevo"><span class="btn-action__label">Nueva programación</span><span class="btn-action__icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></a>
    </div></div>
<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-calendar-check" aria-hidden="true"></i></div><p class="empty-state__title">No hay programaciones</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Programaciones de reportes</caption>
        <thead><tr><th scope="col">Nombre</th><th scope="col">Tipo · formato</th><th scope="col">Frecuencia</th><th scope="col">Estado</th><th scope="col" class="col-hide-md">Última vez</th><th scope="col" class="col-hide-md">Próxima</th><th scope="col" class="cell-actions">Acciones</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
            <tr><td><a href="/reportes/programados/<?= e($r['id']) ?>"><?= e($r['nombre']) ?></a><?= $r['sensible'] ? ' ' . status_badge('SENSIBLE', 'Sensible: incluye RFC/CURP') : '' ?>
                    <?php if ($todos): ?><br><small class="text-muted"><?= e($r['creador']) ?></small><?php endif; ?></td>
                <td class="small"><?= e($tipos[$r['tipo']][0] ?? $r['tipo']) ?> · <?= e($r['formato']) ?><br><small class="text-muted"><?= $r['alcance'] === 'LOTE' ? e('Lote ' . $r['lote_numero'] . ' · ' . $r['lote_periodo']) : 'Cotejo más reciente' ?></small></td>
                <td class="small"><?= e($frec($r)) ?></td>
                <td><?= status_badge($r['activo'] ? 'ACTIVA' : 'PAUSADA') ?><?php if (!$r['activo'] && $r['pausa_motivo']): ?><br><small class="text-muted"><?= e($r['pausa_motivo']) ?></small><?php endif; ?></td>
                <td class="small col-hide-md"><?= $r['ultima_ejecucion_at'] ? e(fmt_datetime($r['ultima_ejecucion_at'])) . '<br>' . status_badge((string) $r['ultimo_estado']) : '—' ?></td>
                <td class="small col-hide-md"><?= $r['proxima_ejecucion_at'] ? e(fmt_datetime($r['proxima_ejecucion_at'])) : '—' ?></td>
                <td class="cell-actions">
                    <?php if ($r['activo']): ?>
                        <form class="d-inline" method="post" action="/reportes/programados/<?= e($r['id']) ?>/generar" data-busy><?= $this->csrf() ?><button class="btn-action btn-reconcile btn-action--sm" type="submit"><span class="btn-action__label">Generar ahora</span><span class="btn-action__icon"><i class="bi bi-play-fill" aria-hidden="true"></i></span></button></form>
                        <form class="d-inline" method="post" action="/reportes/programados/<?= e($r['id']) ?>/pausar" data-confirm="La programación dejará de ejecutarse hasta reactivarla." data-confirm-title="Pausar programación" data-confirm-reason="motivo"><?= $this->csrf() ?><button class="btn-action btn-cancel btn-action--sm" type="submit"><span class="btn-action__label">Pausar</span><span class="btn-action__icon"><i class="bi bi-pause-fill" aria-hidden="true"></i></span></button></form>
                    <?php else: ?>
                        <form class="d-inline" method="post" action="/reportes/programados/<?= e($r['id']) ?>/reactivar"><?= $this->csrf() ?><button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Reactivar</span><span class="btn-action__icon"><i class="bi bi-play-circle" aria-hidden="true"></i></span></button></form>
                    <?php endif; ?>
                </td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>
