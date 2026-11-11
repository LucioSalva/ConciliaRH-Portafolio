<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$states = ['RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'RECHAZADO', 'ANALIZANDO', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'ERROR', 'ANULADO'];
$dups = ['PENDIENTE' => 'Huella pendiente', 'NUEVO' => 'Nuevo', 'DUPLICADO_BINARIO' => 'Duplicado exacto', 'DUPLICADO_CONTENIDO' => 'Mismo contenido', 'POSIBLE_VERSION' => 'Posible versión'];
?>
<div class="page-header">
    <div>
        <h1>Archivos cargados</h1>
        <p class="page-header__sub">Todos los archivos recibidos. El original se guarda sin cambios y en un lugar protegido.</p>
    </div>
    <?php if ($this->can('archivos.cargar')): ?>
        <div class="page-header__actions">
            <a class="btn-action btn-import" href="/importaciones/nueva"><span class="btn-action__label">Cargar archivo</span><span class="btn-action__icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></span></a>
        </div>
    <?php endif; ?>
</div>
<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/importaciones" role="search" aria-label="Filtrar importaciones">
        <div class="search">
            <i class="bi bi-search search__icon" aria-hidden="true"></i>
            <label for="q" class="visually-hidden">Buscar por nombre de archivo o SHA-256</label>
            <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre de archivo o SHA-256…" maxlength="80">
            <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="filter-bar">
            <?php if ($filters['lote'] !== null): ?><input type="hidden" name="lote" value="<?= e($filters['lote']) ?>"><?php endif; ?>
            <?php if ($filters['periodo'] !== null): ?><input type="hidden" name="periodo" value="<?= e($filters['periodo']) ?>"><?php endif; ?>
            <div>
                <label class="form-label small mb-1" for="tipo">Tipo</label>
                <select class="form-select form-select-sm" id="tipo" name="tipo" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>"<?= $filters['tipo'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($states as $s): ?><option value="<?= e($s) ?>"<?= $filters['estado'] === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="duplicidad">Duplicidad</label>
                <select class="form-select form-select-sm" id="duplicidad" name="duplicidad" data-autosubmit>
                    <option value="">Todas</option>
                    <?php foreach ($dups as $v => $lab): ?><option value="<?= e($v) ?>"<?= $filters['duplicidad'] === $v ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-inboxes" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay archivos que coincidan</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sticky-first align-middle">
                <caption class="visually-hidden">Archivos importados (el detalle de cada uno está en «Ver»)</caption>
                <thead><tr><th scope="col">Archivo</th><th scope="col" class="col-hide-sm">Quincena · lote</th><th scope="col" class="col-hide-md">Tipo</th><th scope="col">Estado</th><th scope="col" class="col-hide-md">Duplicidad</th><th scope="col" class="col-hide-sm">SHA-256</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $f): ?>
                    <tr>
                        <td class="fw-600 text-break"><?= e($f['nombre_original']) ?><br><small class="text-muted"><?= e(fmt_bytes($f['tamano_bytes'])) ?> · <?= e(fmt_datetime($f['created_at'])) ?> · <?= e($f['usuario_carga']) ?></small></td>
                        <td class="col-hide-sm"><?= e($f['periodo_descripcion']) ?><br><a class="small" href="/lotes/<?= e($f['lote_id']) ?>">Lote <?= e(sprintf('%03d', $f['lote_numero'])) ?> · <?= e($f['lote_nombre']) ?></a></td>
                        <td class="col-hide-md"><?= e($f['tipo_nombre']) ?></td>
                        <td><?= status_badge((string) $f['estado']) ?></td>
                        <td class="col-hide-md"><?= status_badge((string) $f['clasificacion_duplicidad'], $dups[$f['clasificacion_duplicidad']] ?? null) ?></td>
                        <td class="col-hide-sm"><code class="small" title="<?= e($f['sha256']) ?>"><?= e(substr((string) $f['sha256'], 0, 12)) ?>…</code></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/importaciones/<?= e($f['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
