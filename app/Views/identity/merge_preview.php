<?php
/** @var \ConciliaRH\Core\Template $this */
use ConciliaRH\Domain\Identity\AccountVault;
use ConciliaRH\Domain\Identity\MergeService;

$this->layout('layouts/app', ['title' => $title]);
$a = $p['analisis'];
$o = $p['origen'];
$d = $p['destino'];
$co = $a['conteos'];
$filas = [
    ['Número de empleado', 'numero_empleado'], ['Nombre', 'nombre_completo'], ['RFC', 'rfc'], ['CURP', 'curp'], ['ISSEMYM', 'issemym'], ['Grupo', 'grupo'],
    ['Primera quincena', 'primer_periodo'], ['Última quincena', 'ultimo_periodo'],
];
$conteos = [['registros_rrhh', 'Registros de RH'], ['registros_tesoreria', 'Pagos de nómina (Tesorería)'], ['pensiones', 'Pagos de pensión'],
    ['beneficiarios', 'Beneficiarios relacionados'], ['cuentas_activas', 'Cuentas activas'], ['cuentas_historicas', 'Cuentas históricas'],
    ['incidencias_abiertas', 'Incidencias abiertas'], ['periodos', 'Quincenas con registros de RH'], ['alias', 'Empleados ya fusionados en él'],
    ['conciliaciones_historicas', 'Cotejos anteriores (no cambian)'], ['lotes_afectables', 'Lotes que cambiarán al volver a cotejar']];
$resumen = sprintf('El empleado %s quedará FUSIONADO en %s. No se borra nada: sus registros, cuentas e historial se conservan y los cotejos anteriores no cambian; los nuevos usarán la identidad canónica.',
    $o['numero_empleado'], $d['numero_empleado']);
?>
<div class="page-header"><div><h1>Previsualizar fusión</h1>
    <p class="page-header__sub">Nada se ha modificado todavía. El empleado <strong>origen</strong> queda como alias del <strong>destino</strong> (identidad canónica).</p></div>
    <div class="page-header__actions"><a class="btn-action btn-view" href="/empleados/fusion?origen=<?= e($d['id']) ?>&amp;destino=<?= e($o['id']) ?>"><span class="btn-action__label">Invertir dirección</span><span class="btn-action__icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span></a></div></div>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center"><span class="fw-600">Confianza:</span> <?= status_badge('CONF_' . ($a['confianza'] === 'NINGUNA' ? 'SIN_EVIDENCIA' : $a['confianza'])) ?>
    <?php foreach ($a['evidencias'] as $ev): ?><?= status_badge('FUERZA_' . $ev['fuerza'], (MergeService::EVIDENCIAS[$ev['codigo']] ?? $ev['codigo']) . ' · ' . mb_strtolower(status_label('FUERZA_' . $ev['fuerza']))) ?><?php endforeach; ?>
</div>
<div class="table-card mb-3"><div class="table-responsive"><table class="table align-middle table-sticky-first"><caption class="visually-hidden">Comparación de los dos empleados</caption>
    <thead><tr><th scope="col">Campo</th><th scope="col">Origen (quedará fusionado)</th><th scope="col">Destino (canónico)</th><th scope="col">Comparación</th></tr></thead>
    <tbody>
    <?php foreach ($filas as [$l, $k]): $cmp = ['numero_empleado' => $a['comparacion']['numero'], 'nombre_completo' => $a['comparacion']['nombre'], 'rfc' => $a['comparacion']['rfc'],
        'curp' => $a['comparacion']['curp'], 'issemym' => $a['comparacion']['issemym'], 'grupo' => $a['comparacion']['grupo']][$k] ?? null; ?>
        <tr><th scope="row" class="small"><?= e($l) ?></th><?php $ver = static fn (mixed $v): string => $k === 'grupo' && $v !== null ? (['POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos'][$v] ?? (string) $v) : (string) ($v ?? '—'); ?><td><?= e($ver($o[$k] ?? null)) ?></td><td><?= e($ver($d[$k] ?? null)) ?></td>
            <td><?= $cmp === null ? '' : status_badge($cmp === 'IGUAL' ? 'CTL_CUADRA' : ($cmp === 'FALTA' ? 'CTL_SIN_TOTAL' : 'CTL_NO_CUADRA'), status_label($cmp)) ?></td></tr>
    <?php endforeach; ?>
    <tr><th scope="row" class="small">Cuentas</th>
        <?php foreach ([$o, $d] as $x): ?><td class="small"><?php if ($x['cuentas'] === []): ?>—<?php else: foreach ($x['cuentas'] as $c): ?>
            <code><?= e(AccountVault::mask($c['cuenta_ultimos4'])) ?></code> <?= e(status_label($c['uso'])) ?> · <?= e(bank_label($c['banco'])) ?> <?= status_badge($c['estado']) ?><br>
        <?php endforeach; endif; ?></td><?php endforeach; ?><td></td></tr>
    <?php foreach ($conteos as [$k, $l]): ?>
        <tr><th scope="row" class="small"><?= e($l) ?></th><td><?= e((string) ($co[(string) $o['id']][$k] ?? 0)) ?></td><td><?= e((string) ($co[(string) $d['id']][$k] ?? 0)) ?></td><td></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php if ($a['conflictos'] !== []): ?>
<div class="table-card mb-3"><div class="table-card__toolbar"><h2 class="table-card__title">Conflictos</h2></div><ul class="list-group list-group-flush">
    <?php foreach ($a['conflictos'] as $c): ?><li class="list-group-item d-flex gap-2 align-items-center">
        <?= status_badge('SEV_' . $c['severidad']) ?> <?= e(MergeService::CONFLICTOS[$c['codigo']] ?? $c['codigo']) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
<?php if (!$a['fusionable']): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= $a['confianza'] === 'BLOQUEADA' ? 'La fusión está bloqueada (mismo empleado o alguno ya fusionado).'
        : ($a['evidencias'] === [] ? 'No hay evidencia de que sean la misma persona: no se puede fusionar.'
        : 'Evidencia insuficiente: el nombre por sí solo nunca permite fusionar. Se requiere RFC, CURP, ISSEMYM, una cuenta confirmada u otra evidencia media.') ?>
        <a href="/empleados/duplicados">Volver</a></div></div>
<?php elseif ($p['graves'] !== [] && !$p['puede_conflictos']): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i><div class="notice__body">Hay conflictos graves: sólo un usuario autorizado (empleados.fusionar_conflictos) puede aceptarlos. <a href="/empleados/duplicados">Volver</a></div></div>
<?php else: ?>
<?php $critica = $p['graves'] !== []; ?>
<?php if ($critica): ?>
    <div class="notice notice--warning" role="note"><i class="bi bi-people-fill notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Requiere una segunda persona</p>Por tener conflictos graves, al confirmar se crea una
            <strong>solicitud</strong>: la fusión sólo se aplica cuando la aprueba otro usuario autorizado (cuatro ojos). Vence si nadie la aprueba a tiempo.</div></div>
<?php endif; ?>
<form method="post" action="/empleados/fusion" class="card" data-confirm="<?= e($critica ? 'Se enviará una solicitud de fusión con conflictos graves para que otra persona la apruebe. ' . $resumen : $resumen) ?>"
      data-confirm-title="<?= $critica ? 'Solicitar aprobación de la fusión' : 'Confirmar fusión de empleados' ?>"<?= $critica ? ' data-confirm-variant="approval"' : ' data-confirm-accept="Fusionar"' ?> data-busy>
    <div class="card-body">
        <?= $this->csrf() ?>
        <input type="hidden" name="origen" value="<?= e($o['id']) ?>"><input type="hidden" name="destino" value="<?= e($d['id']) ?>">
        <input type="hidden" name="huella" value="<?= e($p['huella']) ?>">
        <p class="fw-600 mb-2"><?= e($resumen) ?></p>
        <?php foreach ($p['graves'] as $g): ?>
            <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="aceptados[]" value="<?= e($g) ?>" id="acepto-<?= e($g) ?>" required>
                <label class="form-check-label" for="acepto-<?= e($g) ?>">Acepto el conflicto grave: <?= e(MergeService::CONFLICTOS[$g] ?? $g) ?> (lo justifico en el motivo).</label></div>
        <?php endforeach; ?>
        <label class="form-label mt-2" for="motivo">Motivo (obligatorio, mínimo 20 caracteres; queda en la fusión, el historial y la auditoría)</label>
        <textarea class="form-control mb-2" id="motivo" name="motivo" rows="3" minlength="20" maxlength="2000" required></textarea>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="revisado" required><label class="form-check-label" for="revisado">Revisé la evidencia; entiendo que no hay «deshacer»: una fusión equivocada se corrige con una separación auditada.</label></div>
        <div class="d-flex gap-2">
            <button class="btn-action btn-confirm" type="submit"><span class="btn-action__label"><?= $critica ? 'Solicitar aprobación' : 'Fusionar' ?></span><span class="btn-action__icon"><i class="bi <?= $critica ? 'bi-people' : 'bi-intersect' ?>" aria-hidden="true"></i></span></button>
            <a class="btn-action btn-cancel" href="/empleados/duplicados"><span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
        </div>
        <p class="small text-muted mt-2 mb-0">Si alguno de los dos empleados cambia antes de confirmar (otra fusión, una carga o una asociación), no se aplicará nada y deberá previsualizar de nuevo.</p>
    </div>
</form>
<?php endif; ?>
