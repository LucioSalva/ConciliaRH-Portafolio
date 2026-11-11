<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$canBulk = $this->can('asociaciones.masivo');
$estados = ['SUGERENCIA' => 'Sugerencia', 'AMBIGUO' => 'Ambiguo', 'CONFLICTO' => 'Conflicto', 'NO_IDENTIFICADO' => 'No identificado'];
?>
<div class="page-header">
    <div>
        <h1>Revisión masiva</h1>
        <p class="page-header__sub">Resuelva en lote transferencias de nómina y pensión pendientes. Sólo se confirman sugerencias con <strong>un</strong> candidato; conflictos y ambigüedades se deciden uno por uno.</p>
    </div>
</div>
<div class="figures" aria-label="Pendientes de asociación">
    <?php foreach ([['Nómina por asociar', $counts['nomina'] ?? 0, 'de todos los bancos'], ['Transferencias interbancarias', $counts['nomina_interbancaria'] ?? 0, 'dentro de la nómina'],
                    ['Pensiones sin trabajador', $counts['pension'] ?? 0, 'pagos a beneficiarios'], ['Con una sugerencia', $counts['sugerencias'] ?? 0, 'un solo candidato propuesto']] as [$l, $v, $h]): ?>
        <div class="figure"><p class="figure__label"><?= e($l) ?></p><p class="figure__value"><?= e(number_format((int) $v)) ?></p><p class="figure__hint"><?= e($h) ?></p></div>
    <?php endforeach; ?>
</div>

<form method="get" action="/revision-masiva" class="card mb-3" role="search" aria-label="Filtrar registros pendientes"><div class="card-body filter-bar">
    <div class="search filter-bar__field--wide">
        <i class="bi bi-search search__icon" aria-hidden="true"></i>
        <label for="q" class="visually-hidden">Buscar por nombre</label>
        <input id="q" class="search__input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre en el archivo…" maxlength="80">
        <button type="button" class="search__clear" data-search-clear="q" aria-label="Limpiar búsqueda" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <?php foreach ([['categoria', 'Tipo', ['NOMINA' => 'Nómina', 'PENSION' => 'Pensión']], ['estado', 'Estado', $estados],
                    ['banco', 'Banco', ['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria']],
                    ['grupo', 'Grupo', ['POLICIA' => 'Policía', 'BOMBEROS' => 'Bomberos']], ['confianza', 'Confianza', ['MEDIA' => 'Media', 'BAJA' => 'Baja', 'NINGUNA' => 'Sin evidencia']],
                    ['orden', 'Ordenar', ['' => 'Prioridad', 'importe' => 'Importe', 'nombre' => 'Nombre', 'cuenta' => 'Misma CLABE', 'periodo' => 'Periodo']]] as [$k, $l, $opts]): ?>
        <div><label class="form-label" for="f-<?= e($k) ?>"><?= e($l) ?></label>
            <select class="form-select form-select-sm" id="f-<?= e($k) ?>" name="<?= e($k) ?>" data-autosubmit>
                <?php if ($k !== 'orden'): ?><option value="">Todos</option><?php endif; ?>
                <?php foreach ($opts as $v => $t): ?><option value="<?= e($v) ?>"<?= (string) $filters[$k] === (string) $v ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
    <?php endforeach; ?>
    <div><label class="form-label" for="f-min">Importe mín.</label><input class="form-control form-control-sm" id="f-min" name="min" value="<?= e($filters['min'] ?? '') ?>" inputmode="decimal" placeholder="0.00" autocomplete="off"></div>
    <div><label class="form-label" for="f-max">Importe máx.</label><input class="form-control form-control-sm" id="f-max" name="max" value="<?= e($filters['max'] ?? '') ?>" inputmode="decimal" placeholder="0.00" autocomplete="off"></div>
    <?php foreach (['periodo', 'lote', 'grupo_cuenta'] as $k): if ($filters[$k] !== null && $filters[$k] !== ''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
    <div class="filter-bar__actions"><button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button></div>
    <?php if ($filters['grupo_cuenta'] !== ''): ?><div class="w-100 small">Mostrando sólo transferencias con la <strong>misma CLABE</strong> · <a href="/revision-masiva">quitar</a></div><?php endif; ?>
</div>
<div class="px-3 pb-3"><?= $this->insert('partials/filter-status', ['filtros' => $filters, 'limpiar' => '/revision-masiva', 'ignorar' => ['orden']]) ?></div>
</form>

<form method="post" action="/revision-masiva/previsualizar" class="table-card" data-bulk-form>
    <?= $this->csrf() ?>
    <div class="table-card__toolbar bulk-toolbar">
        <span class="small" aria-live="polite"><strong data-select-count>0</strong> seleccionados · $<strong data-select-sum>0.00</strong> · <?= e(number_format($total)) ?> pendientes en el filtro (<?= money($importeTotal) ?>)</span>
        <?php if ($canBulk): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <label class="visually-hidden" for="accion">Acción</label>
            <select class="form-select form-select-sm w-auto" id="accion" name="accion">
                <option value="CONFIRMAR_PROPUESTA">Confirmar asociaciones propuestas</option>
                <option value="IGNORAR_SUGERENCIA">Ignorar sugerencia (rechazar candidato)</option>
                <option value="MARCAR_NO_IDENTIFICADO">Marcar como no identificado</option>
            </select>
            <?php if ($this->can('cuentas.confirmar')): ?><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" id="confirmar_cuenta" name="confirmar_cuenta" value="1"><label class="form-check-label" for="confirmar_cuenta">Confirmar cuentas/CLABE</label></div><?php endif; ?>
            <?php if ($this->can('beneficiarios.confirmar')): ?><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" id="registrar_beneficiario" name="registrar_beneficiario" value="1" checked><label class="form-check-label" for="registrar_beneficiario">Pensiones: registrar beneficiario</label></div><?php endif; ?>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Previsualizar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></div><p class="empty-state__title">No hay registros pendientes con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle table-stack bulk-table">
        <caption class="visually-hidden">Registros pendientes de asociación: nombre, banco, monto, confianza, evidencia, candidato y estado</caption>
        <thead><tr>
            <th scope="col" class="bulk-table__check"><?php if ($canBulk): ?><input class="form-check-input" type="checkbox" data-select-all aria-label="Seleccionar todos los visibles"><?php endif; ?><span class="visually-hidden">Seleccionar</span></th>
            <th scope="col">Nombre</th><th scope="col">Banco</th><th scope="col" class="num">Monto</th>
            <th scope="col">Confianza</th><th scope="col" class="col-hide-md">Evidencia</th><th scope="col">Candidato</th><th scope="col">Estado</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $ev = $r['asociacion_evidencia'];
            $nivel = (string) ($ev['categoria'] ?? match ((string) $r['asociacion_confianza']) { 'ALTA' => 'FUERTE', 'MEDIA' => 'MEDIA', 'BAJA' => 'BAJA', default => 'SIN_EVIDENCIA' });
            $senales = array_map(static fn ($x) => \ConciliaRH\Domain\Identity\PensionReviewService::SENALES[$x] ?? status_label((string) $x), (array) ($ev['senales'] ?? [])); ?>
            <tr<?= in_array($r['asociacion_estado'], ['CONFLICTO', 'AMBIGUO'], true) ? ' class="row-attention"' : '' ?>>
                <td class="bulk-table__check"><?php if ($canBulk): ?><input class="form-check-input" type="checkbox" name="items[]" value="<?= e($r['id'] . ':' . $r['version']) ?>" data-importe="<?= e($r['importe'] ?? '0') ?>" aria-label="Seleccionar el pago #<?= e($r['id']) ?> de <?= e($r['nombre_original'] ?? 'sin nombre') ?>"><?php endif; ?></td>
                <td class="cell-name"><span class="fw-600"><?= e($r['nombre_original'] ?? '—') ?></span>
                    <br><small class="text-muted"><?= e($r['categoria'] === 'PENSION' ? 'Pensión (beneficiario)' : 'Nómina') ?> · <a href="/asociaciones/tesoreria/<?= e($r['id']) ?>">#<?= e($r['id']) ?></a> · <?= e($r['periodo']) ?>, lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></small></td>
                <td><?= e(bank_label($r['banco'])) ?>
                    <?php if ($r['cuenta_ultimos4']): ?><br><small class="font-mono"><?= e(\ConciliaRH\Domain\Identity\AccountVault::mask($r['cuenta_ultimos4'])) ?></small><?php endif; ?>
                    <?php if ($r['grupo_cuenta'] !== null): ?><br><?= $r['cuenta_registrada'] ? status_badge('CUENTA_REGISTRADA') : status_badge('CLABE_NUEVA') ?>
                        <?php if ($r['grupo_cuenta_n'] > 1): ?><br><a class="small" href="/revision-masiva?grupo_cuenta=<?= e($r['grupo_cuenta']) ?>">misma CLABE ×<?= e($r['grupo_cuenta_n']) ?></a><?php endif; ?><?php endif; ?></td>
                <td class="num"><?= money($r['importe']) ?></td>
                <td><?= $this->insert('partials/evidence', ['nivel' => $nivel, 'razon' => '', 'senales' => count($senales), 'compact' => true]) ?></td>
                <td class="small col-hide-md"><?= e(\ConciliaRH\Domain\Identity\PensionReviewService::MOTIVOS[$ev['motivo'] ?? ''] ?? status_label((string) ($ev['motivo'] ?? 'SIN_EVIDENCIA'))) ?>
                    <?php if ($senales !== []): ?><ul class="evidence-list mt-1"><?php foreach ($senales as $sn): ?><li><i class="bi bi-dot" aria-hidden="true"></i><?= e($sn) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
                <td class="small">
                    <?php if ($r['candidatos_info'] === []): ?><span class="text-muted">Sin candidato</span>
                    <?php else: foreach ($r['candidatos_info'] as $c): ?><div><a href="/empleados/<?= e($c['id']) ?>"><?= e($c['numero_empleado']) ?></a> <?= e($c['nombre_completo']) ?> <span class="text-muted">· <?= e(ucfirst(strtolower((string) $c['grupo']))) ?></span></div><?php endforeach; endif; ?>
                    <?php if ($r['beneficiario_candidato_nombre']): ?><div class="text-muted">Beneficiario conocido: <?= e($r['beneficiario_candidato_nombre']) ?></div><?php endif; ?></td>
                <td><?= status_badge((string) $r['asociacion_estado']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</form>

<?php if ($recent !== []): ?>
<div class="table-card mt-3"><div class="table-card__toolbar"><h2 class="table-card__title">Acciones masivas recientes</h2></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($recent as $a): ?><li class="list-group-item small d-flex justify-content-between gap-2"><span><a href="/revision-masiva/acciones/<?= e($a['id']) ?>">#<?= e($a['id']) ?></a> <?= e(status_label((string) $a['accion'])) ?> · <?= e($a['registros']) ?> registro(s) · <?= money($a['importe_total']) ?> · <?= e($a['username']) ?></span><span class="text-muted"><?= e(fmt_datetime($a['created_at'])) ?></span></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>
