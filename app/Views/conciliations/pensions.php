<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$base = '/conciliaciones/' . (int) $c['id'];
$tol = (string) $c['tolerancia'];
$grupos = ['POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos'];
$estados = ['' => 'Todas', 'CORRECTA' => 'Dispersadas', 'PENDIENTE_IDENTIDAD' => 'Pendientes de identificar', 'NO_DISPERSADA' => 'No dispersadas',
            'PENSION_MENOR' => 'Menores', 'PENSION_MAYOR' => 'Mayores', 'SIN_RETENCION_RRHH' => 'Sin retención en RH'];
$tot = is_array($c['totales']) ? $c['totales'] : [];
?>
<div class="page-header">
    <div>
        <h1>Pensiones alimenticias</h1>
        <p class="page-header__sub"><?= e($c['periodo']) ?> · cotejo del <?= e(fmt_datetime($c['created_at'])) ?>: lo que RH retuvo frente a lo que Tesorería dispersó.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-view" href="<?= e($base) ?>/empleados"><span class="btn-action__label">Personas</span><span class="btn-action__icon"><i class="bi bi-people" aria-hidden="true"></i></span></a>
        <a class="btn-action btn-view" href="<?= e($base) ?>"><span class="btn-action__label">Resumen</span><span class="btn-action__icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span></a>
    </div>
</div>

<section class="section" aria-labelledby="pob-t">
    <div class="table-card">
        <div class="table-card__toolbar">
            <h2 class="table-card__title" id="pob-t">Por población</h2>
            <span class="table-card__hint">Los archivos interbancarios no traen número de empleado: su total se cuenta en la población del espacio donde se cargaron.</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-stack">
                <caption class="visually-hidden">Pensión retenida por RH y dispersada por Tesorería, por población</caption>
                <thead><tr><th scope="col">Población</th><th scope="col" class="num">Retenido por RH</th><th scope="col" class="num">Dispersado</th>
                    <th scope="col" class="num">Con persona identificada</th><th scope="col" class="num">Sin persona identificada</th><th scope="col" class="num">Diferencia</th></tr></thead>
                <tbody>
                <?php foreach ($grupos as $g => $nombre): if (!isset($tot[$g])) { continue; } $pp = $tot[$g]['pensiones']; ?>
                    <tr>
                        <th scope="row"><?= e($nombre) ?></th>
                        <td class="num"><?= money((string) $pp['retenido']) ?></td>
                        <td class="num"><?= money((string) $pp['dispersado']) ?></td>
                        <td class="num"><?= money((string) $pp['identificado']) ?></td>
                        <td class="num"><?= money((string) $pp['pendiente_identidad']) ?></td>
                        <td class="num"><?= money_diff(bcsub((string) $pp['dispersado'], (string) $pp['retenido'], 2), $tol) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (isset($tot['TOTAL'])): $pp = $tot['TOTAL']['pensiones']; ?>
                    <tfoot><tr><th scope="row">Total</th><td class="num"><?= money((string) $pp['retenido']) ?></td><td class="num"><?= money((string) $pp['dispersado']) ?></td>
                        <td class="num"><?= money((string) $pp['identificado']) ?></td><td class="num"><?= money((string) $pp['pendiente_identidad']) ?></td>
                        <td class="num"><?= money_diff(bcsub((string) $pp['dispersado'], (string) $pp['retenido'], 2), $tol) ?></td></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</section>

<form method="get" action="<?= e($base) ?>/pensiones" class="card mb-3" aria-label="Filtrar pensiones"><div class="card-body filter-bar">
    <div><label class="form-label" for="grupo">Población</label>
        <select class="form-select form-select-sm" id="grupo" name="grupo" data-autosubmit><option value="">Todas</option>
            <?php foreach ($grupos as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['grupo'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="estado">Resultado</label>
        <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
            <?php foreach ($estados as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="filter-bar__actions">
        <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
    </div>
</div></form>

<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-person-heart" aria-hidden="true"></i></div><p class="empty-state__title">No hay pensiones con estos filtros</p></div>
    <?php else: ?>
        <div class="table-responsive<?= count($rows) > 12 ? ' table-scroll' : '' ?>"><table class="table table-hover align-middle table-stack">
            <caption class="visually-hidden">Pensión por persona: retenido por RH, dispersado identificado y resultado (el detalle está en «Detalle»)</caption>
            <thead><tr><th scope="col">Número</th><th scope="col">Trabajador</th><th scope="col" class="num">Retenido</th><th scope="col" class="num">Dispersado identificado</th>
                <th scope="col">Resultado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $cubierta = ($r['detalle']['cubierta_por_total_poblacion'] ?? false) === true; ?>
                <tr<?= $r['estado'] !== 'CORRECTA' && !$cubierta ? ' class="row-attention"' : '' ?>>
                    <td class="nowrap"><?= e($r['numero_empleado']) ?> <small class="text-muted d-sm-block"><?= e($grupos[$r['grupo']] ?? (string) $r['grupo']) ?></small></td>
                    <td class="cell-name"><?= e($r['nombre_completo']) ?></td>
                    <td class="num"><?= money((string) $r['retenido']) ?></td>
                    <td class="num"><?= money((string) $r['dispersado']) ?></td>
                    <td><?= status_badge((string) $r['estado']) ?>
                        <?php if ($cubierta): ?><small class="d-block text-muted">El total de la población cuadra; falta identificar al beneficiario.</small><?php endif; ?></td>
                    <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="<?= e($base) ?>/empleados/<?= e($r['empleado_id']) ?>" aria-label="Ver detalle de <?= e($r['numero_empleado']) ?>"><span class="btn-action__label">Detalle</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
