<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$verbo = ['CONFIRMAR_PROPUESTA' => 'Confirmará', 'IGNORAR_SUGERENCIA' => 'Rechazará la sugerencia de', 'MARCAR_NO_IDENTIFICADO' => 'Marcará como no identificados'][$p['accion']];
$objeto = ['CONFIRMAR_PROPUESTA' => 'asociaciones', 'IGNORAR_SUGERENCIA' => 'registros', 'MARCAR_NO_IDENTIFICADO' => 'registros'][$p['accion']];
$resumen = sprintf('%s %d %s por un total de %s (%d empleado(s)%s%s). Esta decisión queda en el historial de cada registro.',
    $verbo, $p['registros'], $objeto, money_plain($p['importe_total']), $p['empleados'],
    $p['beneficiarios'] ? ', ' . $p['beneficiarios'] . ' beneficiario(s)' : '', $p['cuentas_nuevas'] ? ', ' . $p['cuentas_nuevas'] . ' cuenta(s) nueva(s)' : '');
?>
<div class="page-header"><div><h1>Previsualización · <?= e(status_label($p['accion'])) ?></h1>
    <p class="page-header__sub">Nada se ha modificado todavía. Revise el resumen y confirme.</p></div></div>
<div class="row g-3 mb-3">
    <?php foreach ([['Registros', $p['registros']], ['Monto total', money_plain($p['importe_total'])],
                    ['Empleados afectados', $p['empleados']], ['Beneficiarios', $p['beneficiarios'] . ($p['beneficiarios_nuevos'] ? ' (' . $p['beneficiarios_nuevos'] . ' nuevos)' : '')],
                    ['Cuentas que se crearán', $p['cuentas_nuevas']], ['Incidencias que se resolverán', $p['incidencias']]] as [$l, $v]): ?>
        <div class="col-6 col-lg-2"><div class="kpi"><div class="kpi__label"><?= e($l) ?></div><div class="kpi__value kpi__value--money"><?= e($v) ?></div></div></div>
    <?php endforeach; ?>
</div>
<?php foreach ($p['advertencias'] as $w): ?><div class="notice notice--warning mb-2" role="alert"><i class="bi bi-exclamation-triangle notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($w) ?></div></div><?php endforeach; ?>
<?php if ($p['rechazados'] !== []): ?>
    <details class="table-card mb-3" open><summary class="table-card__toolbar"><h2 class="table-card__title">No se incluirán (<?= count($p['rechazados']) ?>)</h2></summary>
        <ul class="list-group list-group-flush small">
            <?php foreach ($p['rechazados'] as $id => $why): ?><li class="list-group-item">Registro <a href="/asociaciones/tesoreria/<?= e($id) ?>">#<?= e($id) ?></a>: <?= e(status_label((string) $why)) ?></li><?php endforeach; ?>
        </ul></details>
<?php endif; ?>
<?php if ($p['token'] === null): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i><div class="notice__body">Ningún registro seleccionado es elegible para esta acción. <a href="/revision-masiva">Volver</a></div></div>
<?php else: ?>
<form method="post" action="/revision-masiva/ejecutar" class="card" data-confirm="<?= e($resumen) ?>" data-confirm-title="Confirmar acción masiva" data-busy>
    <div class="card-body">
        <?= $this->csrf() ?>
        <input type="hidden" name="accion" value="<?= e($p['accion']) ?>"><input type="hidden" name="token" value="<?= e($p['token']) ?>">
        <?php if ($p['opciones']['confirmar_cuenta']): ?><input type="hidden" name="confirmar_cuenta" value="1"><?php endif; ?>
        <?php if ($p['opciones']['registrar_beneficiario']): ?><input type="hidden" name="registrar_beneficiario" value="1"><?php endif; ?>
        <?php foreach ($p['elegibles'] as $id => $e): ?><input type="hidden" name="items[]" value="<?= e($id . ':' . $e['version'] . ':' . ($e['candidato'] ?? '')) ?>"><?php endforeach; ?>
        <p class="fw-600 mb-2"><?= e($resumen) ?></p>
        <label class="form-label" for="motivo">Motivo (obligatorio, queda en cada registro)</label>
        <textarea class="form-control mb-2" id="motivo" name="motivo" rows="2" minlength="5" maxlength="1000" required></textarea>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="revisado" required><label class="form-check-label" for="revisado">Revisé la evidencia y el resumen; entiendo que la acción no se deshace (una corrección es una nueva decisión).</label></div>
        <div class="d-flex gap-2">
            <button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Confirmar</span><span class="btn-action__icon"><i class="bi bi-check2-all" aria-hidden="true"></i></span></button>
            <a class="btn-action btn-cancel" href="/revision-masiva"><span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
        </div>
        <p class="small text-muted mt-2 mb-0">La previsualización vence en 15 minutos. Si otra persona modifica alguno de estos registros antes de confirmar, no se aplicará nada.</p>
    </div>
</form>
<?php endif; ?>
