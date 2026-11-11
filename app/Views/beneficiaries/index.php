<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header"><div><h1>Beneficiarios de pensión</h1>
    <p class="page-header__sub">Personas que reciben pensión alimenticia. El sistema no registra parentesco, RFC ni CURP: los archivos no los contienen.</p></div></div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/beneficiarios" role="search" aria-label="Filtrar beneficiarios">
        <div class="search"><i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por nombre o número del trabajador</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre o número del trabajador…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        <div class="filter-bar">
            <?php foreach ([['estado', 'Estado', ['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo']], ['origen', 'Origen', ['EVIDENCIA_FUERTE' => 'Evidencia fuerte', 'CONFIRMACION_MANUAL' => 'Confirmación manual']],
                            ['banco', 'Banco', ['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria']]] as [$k, $l, $opts]): ?>
                <div><label class="form-label small mb-1" for="b-<?= e($k) ?>"><?= e($l) ?></label>
                    <select class="form-select form-select-sm" id="b-<?= e($k) ?>" name="<?= e($k) ?>" data-autosubmit><option value="">Todos</option>
                        <?php foreach ($opts as $v => $t): ?><option value="<?= e($v) ?>"<?= $filters[$k] === $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <?php endforeach; ?>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($searchError): ?><div class="notice notice--warning m-3" role="alert"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($searchError) ?></div></div><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-people" aria-hidden="true"></i></div><p class="empty-state__title">No hay beneficiarios con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Beneficiarios de pensión</caption>
        <thead><tr><th scope="col">Nombre</th><th scope="col">Trabajadores relacionados</th><th scope="col" class="col-hide-sm">Banco</th><th scope="col" class="col-hide-sm">Cuenta</th><th scope="col">Estado · origen</th><th scope="col" class="col-hide-md">Última aparición</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
            <tr><td><a href="/beneficiarios/<?= e($r['id']) ?>"><?= e($r['nombre_original']) ?></a><br><small class="text-muted">BEN-<?= e(sprintf('%06d', $r['id'])) ?></small></td>
                <td class="small"><?= e($r['trabajadores'] ?? '—') ?></td>
                <td class="small col-hide-sm"><?= e($r['bancos'] ?? '—') ?></td>
                <td class="col-hide-sm"><code><?= e(\ConciliaRH\Domain\Identity\AccountVault::mask($r['ultimos4'])) ?></code></td>
                <td><?= status_badge($r['estado'] === 'ACTIVO' ? 'ACTIVO' : 'INACTIVO') ?><br><small class="text-muted"><?= e(status_label((string) $r['origen'])) ?></small></td>
                <td class="small col-hide-md"><?= e($r['ultima_aparicion']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
