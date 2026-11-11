<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$l = $lot;
$open = $l['estado'] === 'ABIERTO' && $l['periodo_estado'] === 'ABIERTO';
?>
<div class="page-header">
    <div>
        <h1>Lote <?= e(sprintf('%03d', $l['numero'])) ?> · <?= e($l['nombre']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge((string) $l['estado']) ?>
            <?= status_badge((string) $l['estado_procesamiento']) ?>
            <?php if ($l['es_oficial']): ?><?= status_badge('OFICIAL') ?><?php endif; ?>
            <span class="text-muted">· <a href="/periodos/<?= e($l['periodo_id']) ?>"><?= e($l['periodo_descripcion']) ?></a></span>
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($open && $this->can('archivos.cargar')): ?>
            <a class="btn-action btn-import" href="/importaciones/nueva?lote=<?= e($l['id']) ?>"><span class="btn-action__label">Cargar archivo</span><span class="btn-action__icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></span></a>
        <?php endif; ?>
        <a class="btn-action btn-cancel" href="/periodos/<?= e($l['periodo_id']) ?>"><span class="btn-action__label">Periodo</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([['Archivos', $l['archivos'], ''], ['En proceso', $l['en_proceso'], ''], ['Con error', $l['con_error'], 'kpi--danger'], ['Duplicados exactos', $l['duplicados_binarios'], 'kpi--wine']] as [$lab, $val, $cls]): ?>
        <div class="col-6 col-lg-3">
            <div class="kpi <?= e($cls) ?>"><div class="kpi__label"><?= e($lab) ?></div><div class="kpi__value"><?= e($val) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="table-card">
            <div class="table-card__toolbar"><h2 class="table-card__title">Archivos del lote</h2>
                <?php if ($filesTotal > count($files)): ?><a class="small ms-auto" href="/importaciones?lote=<?= e($l['id']) ?>">Mostrando <?= e(count($files)) ?> de <?= e($filesTotal) ?> · ver todos</a><?php endif; ?></div>
            <?php if ($files === []): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></div>
                    <p class="empty-state__title">Sin archivos</p>
                    <p class="mb-0"><?= $open ? 'Cargue los tabulados, pólizas y dispersiones de esta entrega.' : 'El lote no admite cargas en su estado actual.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <caption class="visually-hidden">Archivos del lote (el detalle de cada uno está en «Ver»)</caption>
                        <thead><tr><th scope="col">Archivo</th><th scope="col" class="col-hide-sm">Tipo</th><th scope="col">Estado</th><th scope="col" class="col-hide-md">Duplicidad</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($files as $f): ?>
                            <tr>
                                <td class="fw-600 text-break"><?= e($f['nombre_original']) ?><br><small class="text-muted"><?= e(fmt_bytes($f['tamano_bytes'])) ?> · <?= e(fmt_datetime($f['created_at'])) ?> · <?= e($f['usuario_carga']) ?></small></td>
                                <td class="col-hide-sm"><?= e($f['tipo_nombre']) ?></td>
                                <td><?= status_badge((string) $f['estado']) ?></td>
                                <td class="col-hide-md"><?= status_badge((string) $f['clasificacion_duplicidad'], $f['clasificacion_duplicidad'] === 'PENDIENTE' ? 'Huella pendiente' : null) ?></td>
                                <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/importaciones/<?= e($f['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4 d-grid gap-3 align-content-start">
        <div class="card">
            <div class="card-header card-header--accent">Datos del lote</div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Número</dt><dd><?= e(sprintf('%03d', $l['numero'])) ?></dd>
                    <dt>Creado</dt><dd><?= e(fmt_datetime($l['created_at'])) ?> · <?= e($l['creado_por_username']) ?></dd>
                    <dt>Procedencia</dt><dd><?= e($l['origen'] ?? '—') ?></dd>
                    <dt>Observaciones</dt><dd><?= e($l['observaciones'] ?? '—') ?></dd>
                    <?php if ($l['cerrado_at']): ?><dt>Cerrado</dt><dd><?= e(fmt_datetime($l['cerrado_at'])) ?></dd><?php endif; ?>
                    <?php if ($l['motivo_estado']): ?><dt>Motivo</dt><dd><?= e($l['motivo_estado']) ?></dd><?php endif; ?>
                </dl>
                <?php if ($l['estado'] === 'ABIERTO' && $this->can('lotes.editar')): ?>
                    <form method="post" action="/lotes/<?= e($l['id']) ?>" class="form-section d-grid gap-2" data-busy>
                        <?= $this->csrf() ?>
                        <label class="form-label mb-0" for="l-nombre">Nombre</label>
                        <input class="form-control" id="l-nombre" name="nombre" maxlength="120" value="<?= e($l['nombre']) ?>">
                        <label class="form-label mb-0" for="l-origen">Procedencia</label>
                        <input class="form-control" id="l-origen" name="origen" maxlength="200" value="<?= e($l['origen'] ?? '') ?>">
                        <label class="form-label mb-0" for="l-desc">Descripción</label>
                        <textarea class="form-control" id="l-desc" name="descripcion" rows="2" maxlength="1000"><?= e($l['descripcion'] ?? '') ?></textarea>
                        <label class="form-label mb-0" for="l-obs">Observaciones</label>
                        <textarea class="form-control" id="l-obs" name="observaciones" rows="2" maxlength="2000"><?= e($l['observaciones'] ?? '') ?></textarea>
                        <div><button class="btn-action btn-edit btn-action--sm" type="submit"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php $actions = [
            ['cerrar', $l['estado'] === 'ABIERTO' && $this->can('lotes.cerrar'), 'btn-confirm', 'bi-lock', 'Cerrar lote', 'El lote dejará de recibir archivos.', '', ''],
            ['reabrir', $l['estado'] === 'CERRADO' && $this->can('lotes.reabrir'), 'btn-retry', 'bi-unlock', 'Reabrir', 'Acción excepcional auditada: el lote volverá a recibir archivos.', '', 'motivo'],
            ['oficial', $l['estado'] !== 'ANULADO' && !$l['es_oficial'] && $this->can('lotes.declarar_oficial'), 'btn-reconcile', 'bi-patch-check', 'Declarar oficial', 'Será el lote oficial del periodo; cualquier otro lote pierde esa marca.', '', 'motivo'],
            ['anular', $l['estado'] !== 'ANULADO' && $this->can('lotes.anular'), 'btn-delete', 'bi-slash-circle', 'Anular', 'El lote no se usará en conciliaciones. Sus archivos se conservan.', 'danger', 'motivo'],
        ];
        $visible = array_filter($actions, static fn ($a) => $a[1]); ?>
        <?php if ($visible !== []): ?>
            <div class="card">
                <div class="card-header card-header--accent">Acciones</div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php foreach ($visible as [$act, , $cls, $icon, $label, $msg, $variant, $reason]): ?>
                        <form method="post" action="/lotes/<?= e($l['id']) ?>/<?= e($act) ?>" data-confirm="<?= e($msg) ?>" data-confirm-title="<?= e($label) ?>"<?= $variant ? ' data-confirm-variant="' . e($variant) . '"' : '' ?><?= $reason ? ' data-confirm-reason="' . e($reason) . '"' : '' ?>>
                            <?= $this->csrf() ?>
                            <button class="btn-action <?= e($cls) ?> btn-action--sm" type="submit"><span class="btn-action__label"><?= e($label) ?></span><span class="btn-action__icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php $relTypes = \ConciliaRH\Domain\Lots\LotRelationService::TYPES; ?>
        <div class="card" id="relaciones">
            <div class="card-header card-header--accent">Relaciones con otros lotes</div>
            <div class="card-body">
                <?php if ($relations === []): ?>
                    <p class="small text-muted mb-2">Sin relaciones declaradas. Una relación sólo documenta (corrige, sustituye, complementa): no mezcla datos ni cambia el lote oficial.</p>
                <?php else: ?>
                    <ul class="list-unstyled small mb-2">
                        <?php foreach ($relations as $rel): $outgoing = (int) $rel['lote_origen_id'] === (int) $l['id'];
                            $otherId = $outgoing ? $rel['lote_destino_id'] : $rel['lote_origen_id'];
                            $otherNum = $outgoing ? $rel['destino_numero'] : $rel['origen_numero']; ?>
                            <li class="mb-2">
                                <?= status_badge((string) $rel['estado']) ?>
                                <?= $outgoing ? 'Este lote <strong>' . e(mb_strtolower($relTypes[$rel['tipo']])) . '</strong>' : '<a href="/lotes/' . e($otherId) . '">Lote ' . e(sprintf('%03d', $otherNum)) . '</a> <strong>' . e(mb_strtolower($relTypes[$rel['tipo']])) . '</strong> este lote' ?>
                                <?= $outgoing ? ' <a href="/lotes/' . e($otherId) . '">lote ' . e(sprintf('%03d', $otherNum)) . '</a>' : '' ?>
                                <br><span class="text-muted"><?= e($rel['motivo']) ?> · <?= e($rel['creado_por_usuario']) ?> · <?= e(fmt_datetime($rel['created_at'])) ?></span>
                                <?php if ($rel['estado'] === 'ACTIVA' && $this->can('lotes.relacionar')): ?>
                                    <form method="post" action="/lotes/<?= e($l['id']) ?>/relaciones/<?= e($rel['id']) ?>/anular" class="d-inline" data-confirm="La relación se anula (se conserva en el historial)." data-confirm-title="Anular relación" data-confirm-variant="danger" data-confirm-reason="motivo">
                                        <?= $this->csrf() ?><button class="btn-action btn-delete btn-action--sm ms-1" type="submit"><span class="btn-action__label">Anular</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button></form>
                                <?php elseif ($rel['estado'] === 'ANULADA'): ?><br><span class="text-muted">Anulada: <?= e($rel['motivo_anulacion']) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($this->can('lotes.relacionar') && $l['estado'] !== 'ANULADO' && $relationCandidates !== []): ?>
                    <form method="post" action="/lotes/<?= e($l['id']) ?>/relaciones" class="d-grid gap-2" data-confirm="Se registrará la relación entre lotes (no se mezclan datos)." data-confirm-title="Relacionar lotes" data-confirm-reason="motivo">
                        <?= $this->csrf() ?>
                        <label class="form-label small mb-0" for="rel-tipo">Este lote…</label>
                        <select class="form-select form-select-sm" id="rel-tipo" name="tipo"><?php foreach ($relTypes as $v => $lab): ?><option value="<?= e($v) ?>"><?= e($lab) ?></option><?php endforeach; ?></select>
                        <label class="form-label small mb-0" for="rel-dest">Lote</label>
                        <select class="form-select form-select-sm" id="rel-dest" name="destino"><?php foreach ($relationCandidates as $c): ?><option value="<?= e($c['id']) ?>">Lote <?= e(sprintf('%03d', $c['numero'])) ?> · <?= e($c['nombre']) ?><?= $c['es_oficial'] ? ' (oficial)' : '' ?></option><?php endforeach; ?></select>
                        <div><button class="btn-action btn-add btn-action--sm" type="submit"><span class="btn-action__label">Relacionar</span><span class="btn-action__icon"><i class="bi bi-diagram-2" aria-hidden="true"></i></span></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($conciliations !== null): ?>
<div class="row g-3 mt-1" id="polizas">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header card-header--accent d-flex justify-content-between"><span>Conciliaciones del lote</span><a class="small" href="/conciliaciones">Todas</a></div>
            <?php if ($conciliations === []): ?><div class="card-body small text-muted">El lote aún no se ha conciliado.</div>
            <?php else: ?><ul class="list-group list-group-flush">
                <?php foreach ($conciliations as $cn): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2"><a href="/conciliaciones/<?= e($cn['id']) ?>">v<?= e($cn['numero']) ?> · <?= e(fmt_datetime($cn['created_at'])) ?></a><?= status_badge((string) $cn['estado']) ?></li>
                <?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header card-header--accent">Relación manual póliza de egreso ↔ dispersión</div>
            <div class="card-body small">
                <p class="text-muted">El motor relaciona cada hoja bancaria de la póliza de egreso con la dispersión del mismo banco y el mismo importe. Si hay ambigüedad, queda en revisión manual; aquí se fija la relación (auditada) y se aplica en la siguiente ejecución.</p>
                <?php foreach ($policyRelations as $pr): ?>
                    <div class="mb-2"><?= status_badge((string) $pr['estado']) ?> <?= e($pr['egreso']) ?> · hoja <?= e($pr['hoja_indice'] + 1) ?> → <?= e($pr['dispersion']) ?><br><span class="text-muted"><?= e($pr['motivo']) ?> · <?= e($pr['username']) ?></span>
                        <?php if ($pr['estado'] === 'ACTIVA' && $this->can('conciliaciones.relacionar')): ?>
                            <form method="post" action="/lotes/<?= e($l['id']) ?>/relaciones-poliza/<?= e($pr['id']) ?>/anular" class="d-inline" data-confirm="La relación se anula (se conserva en el historial)." data-confirm-title="Anular relación" data-confirm-variant="danger" data-confirm-reason="motivo"><?= $this->csrf() ?><button class="btn-action btn-delete btn-action--sm ms-1" type="submit"><span class="btn-action__label">Anular</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button></form>
                        <?php endif; ?></div>
                <?php endforeach; ?>
                <?php if ($policyCandidates !== null && $policyCandidates['hojas'] !== [] && $policyCandidates['dispersiones'] !== []): ?>
                    <form method="post" action="/lotes/<?= e($l['id']) ?>/relaciones-poliza" class="d-grid gap-2" data-confirm="Se registrará la relación; aplicará en la siguiente ejecución de la conciliación." data-confirm-title="Relacionar póliza y dispersión" data-confirm-reason="motivo">
                        <?= $this->csrf() ?>
                        <label class="form-label small mb-0" for="pr-hoja">Hoja de la póliza de egreso</label>
                        <select class="form-select form-select-sm" id="pr-hoja" name="hoja"><?php foreach ($policyCandidates['hojas'] as $h): ?><option value="<?= e($h['archivo_id']) ?>:<?= e($h['hoja_indice']) ?>"><?= e($h['nombre_original']) ?> · <?= e($h['hoja_nombre']) ?></option><?php endforeach; ?></select>
                        <label class="form-label small mb-0" for="pr-disp">Dispersión</label>
                        <select class="form-select form-select-sm" id="pr-disp" name="dispersion"><?php foreach ($policyCandidates['dispersiones'] as $dp): ?><option value="<?= e($dp['id']) ?>"><?= e($dp['nombre_original']) ?><?= $dp['banco'] ? ' · ' . e($dp['banco']) : '' ?></option><?php endforeach; ?></select>
                        <div><button class="btn-action btn-add btn-action--sm" type="submit"><span class="btn-action__label">Relacionar</span><span class="btn-action__icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
