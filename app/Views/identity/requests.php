<?php
/** @var \ConciliaRH\Core\Template $this */
use ConciliaRH\Domain\Identity\MergeService;

$this->layout('layouts/app', ['title' => $title]);
$labels = ['PENDIENTE_APROBACION' => 'Pendientes', 'EJECUTADA' => 'Ejecutadas', 'RECHAZADA' => 'Rechazadas', 'CANCELADA' => 'Canceladas', 'EXPIRADA' => 'Expiradas'];
?>
<div class="page-header">
    <div>
        <h1>Solicitudes de fusión</h1>
        <p class="page-header__sub">Fusiones con conflictos graves: una persona solicita y otra distinta aprueba. Nada se aplica sin la segunda firma.</p>
    </div>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/empleados/solicitudes-fusion" aria-label="Filtrar solicitudes">
        <div class="filter-bar">
            <div><label class="form-label" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit><option value="">Todos</option>
                    <?php foreach ($labels as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div class="filter-bar__actions"><button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button></div>
        </div>
        <?= $this->insert('partials/filter-status', ['filtros' => $filters, 'limpiar' => '/empleados/solicitudes-fusion']) ?>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-intersect" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay solicitudes<?= $filters['estado'] !== '' ? ' con este estado' : '' ?></p>
            <p class="mb-0">Se crean al confirmar, desde Posibles duplicados, una fusión que tiene conflictos graves.</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack">
        <caption class="visually-hidden">Solicitudes de fusión con conflictos graves</caption>
        <thead><tr><th scope="col">Solicitud</th><th scope="col">Origen → destino</th><th scope="col">Conflictos graves</th><th scope="col">Solicitó</th>
            <th scope="col">Vence / decidió</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $graves = array_filter(explode(',', trim((string) $r['conflictos_graves'], '{}'))); ?>
            <tr<?= $r['estado'] === 'PENDIENTE_APROBACION' ? ' class="row-attention"' : '' ?>>
                <td>#<?= e($r['id']) ?></td>
                <td><?= e($r['origen_numero']) ?> → <?= e($r['destino_numero']) ?></td>
                <td><?= e(implode(' · ', array_map(static fn ($c) => MergeService::CONFLICTOS[$c] ?? $c, $graves))) ?></td>
                <td><?= e($r['solicitante']) ?><br><small class="text-muted"><?= e(fmt_datetime($r['solicitado_at'])) ?></small></td>
                <td><?= $r['estado'] === 'PENDIENTE_APROBACION' ? 'Vence ' . e(fmt_datetime($r['expira_at'])) : e(($r['decisor'] ?? 'sistema') . ' · ' . fmt_datetime($r['decidido_at'])) ?></td>
                <td><?= status_badge('SOL_' . $r['estado']) ?></td>
                <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/empleados/solicitudes-fusion/<?= e($r['id']) ?>" aria-label="Ver solicitud #<?= e($r['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
