<?php
/**
 * Detalle de un registro importado y su asociación (FASE 4). Muestra origen (archivo → hoja → fila),
 * identificadores disponibles, candidatos (sugerencia/ambiguo/conflicto), parecidos (apoyo visual) y
 * permite ASOCIAR, RECHAZAR SUGERENCIA o MARCAR NO IDENTIFICADO (asociaciones.resolver).
 * @var \ConciliaRH\Core\Template $this
 */
$this->layout('layouts/app', ['title' => $title]);
$pending = in_array($r['asociacion_estado'], \ConciliaRH\Domain\Identity\AssociationService::PENDING, true);
$editable = $this->can('asociaciones.resolver') && $r['importacion_estado'] === 'VIGENTE' && $r['periodo_estado'] === 'ABIERTO' && $r['lote_estado'] !== 'ANULADO';
$canAssoc = $editable && ($pending || in_array($r['asociacion_estado'], ['ASOCIADO_AUTOMATICO', 'ASOCIADO_MANUAL', 'NO_IDENTIFICADO_CONFIRMADO'], true));
$tes = $r['registro_tipo'] === 'TESORERIA';
$pension = $tes && ($r['categoria'] ?? '') === 'PENSION';
$canConfirm = $tes && $r['tiene_cuenta'] && $this->can('cuentas.confirmar');
$recKey = \ConciliaRH\Domain\Identity\EmployeeService::nameKey((string) ($r['nombre_original'] ?? ''));
$recTokens = array_filter(explode(' ', $recKey));
$diff = static function (string $name) use ($recTokens): string {
    $out = [];
    foreach (array_filter(explode(' ', \ConciliaRH\Domain\Identity\EmployeeService::nameKey($name))) as $t) {
        $out[] = in_array($t, $recTokens, true) ? e($t) : '<mark title="No aparece en el registro">' . e($t) . '</mark>';
    }
    return implode(' ', $out);
};
$metodos = ['NUMERO_EMPLEADO' => 'Número de empleado', 'RFC' => 'RFC', 'CURP' => 'CURP', 'CUENTA_CONFIRMADA' => 'Cuenta/CLABE conocida'];
$assocForm = function (int $empId, string $label) use ($r, $base, $canConfirm, $pension): string {
    ob_start(); ?>
    <form method="post" action="<?= e($base) ?>/asociar" class="d-inline" data-confirm="<?= e($pension ? 'Se asociará el pago de pensión al TRABAJADOR indicado (el nombre del archivo es del beneficiario).' : 'Se asociará el registro a este empleado. La decisión queda en el historial y en la auditoría.') ?>" data-confirm-title="Asociar registro" data-confirm-reason="motivo">
        <?= $this->csrf() ?><input type="hidden" name="empleado_id" value="<?= e($empId) ?>"><input type="hidden" name="version" value="<?= e($r['asociacion_version']) ?>">
        <?php if ($canConfirm): ?><div class="form-check form-check-inline small mb-1"><input class="form-check-input" type="checkbox" name="confirmar_cuenta" value="1" id="cc<?= e($empId) ?>"><label class="form-check-label" for="cc<?= e($empId) ?>">Confirmar esta <?= e($r['tipo_cuenta'] === 'CLABE' ? 'CLABE' : 'cuenta') ?> como medio de pago conocido</label></div><?php endif; ?>
        <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label"><?= e($label) ?></span><span class="btn-action__icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span></button>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-header">
    <div>
        <h1>Registro <?= e($r['registro_tipo']) ?> #<?= e($r['id']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center"><?= status_badge((string) $r['asociacion_estado']) ?>
            <?php if ($r['asociacion_metodo']): ?><span class="text-muted small">Cómo se identificó: <?= e(mb_strtolower(status_label((string) $r['asociacion_metodo']))) ?><?= $r['asociacion_confianza'] && $r['asociacion_confianza'] !== $r['asociacion_metodo'] ? ' · confianza ' . e(mb_strtolower((string) $r['asociacion_confianza'])) : '' ?></span><?php endif; ?>
            <?php if ($r['importacion_estado'] !== 'VIGENTE'): ?><?= status_badge((string) $r['importacion_estado']) ?><?php endif; ?></p>
    </div>
    <div class="page-header__actions"><a class="btn-action btn-cancel" href="/asociaciones"><span class="btn-action__label">Asociaciones</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a></div>
</div>
<?php if ($pension): ?>
    <div class="notice notice--info mb-3" role="note"><i class="bi bi-info-circle notice__icon" aria-hidden="true"></i><div class="notice__body"><p class="notice__title">Pago de pensión alimenticia</p>
        El número de empleado (si existe) es del TRABAJADOR que genera la pensión; el nombre y la cuenta son del BENEFICIARIO. El nombre por sí solo nunca asocia un pago.</div></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-header card-header--accent">Origen y datos del registro</div><div class="card-body">
            <dl class="dl-grid">
                <dt>Archivo</dt><dd><a href="/importaciones/<?= e($r['archivo_id']) ?>"><?= e($r['archivo']) ?></a><br><small class="text-muted"><?= e(status_label((string) $r['perfil'])) ?></small></dd>
                <dt>Hoja · fila</dt><dd><?= e($r['hoja_nombre']) ?> (índice <?= e($r['hoja_indice']) ?>) · fila <?= e($r['fila_origen']) ?><?= $r['oculta'] ? ' ' . status_badge('OCULTO') : '' ?></dd>
                <dt>Quincena · lote</dt><dd><?= e($r['periodo']) ?> · <a href="/lotes/<?= e($r['lote_id']) ?>">Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></a></dd>
                <dt><?= $pension ? 'Beneficiario' : 'Nombre' ?></dt><dd class="text-break"><?= e($r['nombre_original'] ?? '—') ?></dd>
                <dt>No. empleado</dt><dd><code><?= e($r['numero_original'] ?? '—') ?></code><?= $r['numero_original'] !== null && !$r['numero_valido'] ? ' ' . status_badge('ESTRUCTURA_INVALIDA') : '' ?></dd>
                <dt>RFC</dt><dd><code><?= e($r['rfc_original'] ?? '—') ?></code><?= $r['rfc_original'] !== null && !$r['rfc_valido'] ? ' ' . status_badge('ESTRUCTURA_INVALIDA') : '' ?></dd>
                <?php if (!$tes): ?><dt>CURP</dt><dd><code><?= e($r['curp_original'] ?? '—') ?></code></dd><dt>Área · cargo</dt><dd><?= e($r['area'] ?? '—') ?> · <?= e($r['cargo'] ?? '—') ?></dd><?php endif; ?>
                <?php if ($tes): ?><dt>Banco</dt><dd><?= e(bank_label($r['banco'])) ?> · <?= e(status_label((string) $r['categoria'])) ?><?= $r['banco_receptor'] ? ' · receptor ' . e($r['banco_receptor']) : '' ?></dd>
                    <dt>Cuenta</dt><dd><code><?= e(\ConciliaRH\Domain\Identity\AccountVault::mask($r['cuenta_ultimos4'])) ?></code> <?= e($r['tipo_cuenta'] ?? '') ?></dd><?php endif; ?>
                <dt>Importe</dt><dd class="fw-600"><?= money($r['importe']) ?></dd>
                <?php if ($r['empleado']): ?><dt>Asociado a</dt><dd><a href="/empleados/<?= e($r['empleado']['id']) ?>"><code><?= e($r['empleado']['numero_empleado']) ?></code> <?= e($r['empleado']['nombre_completo']) ?></a><?= $r['asociado_por_usuario'] ? '<br><small class="text-muted">por ' . e($r['asociado_por_usuario']) . ' · ' . e(fmt_datetime($r['asociado_at'])) . '</small>' : '' ?></dd><?php endif; ?>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-7">
        <?php if ($r['fuertes'] !== [] && $r['asociacion_estado'] === 'CONFLICTO'): ?>
            <div class="notice notice--error mb-3" role="alert"><i class="bi bi-exclamation-octagon notice__icon" aria-hidden="true"></i><div class="notice__body"><p class="notice__title">Evidencia fuerte contradictoria</p>
                <ul class="mb-0 small"><?php foreach ($r['fuertes'] as $f): ?><li><?= e($metodos[$f['metodo']] ?? $f['metodo']) ?> → <?= $f['empleado'] ? '<a href="/empleados/' . e($f['empleado']['id']) . '">' . e($f['empleado']['numero_empleado']) . ' · ' . e($f['empleado']['nombre_completo']) . '</a>' : '—' ?></li><?php endforeach; ?></ul>
                <?php foreach ($r['incidencias'] as $i): if ($i['estado'] === 'ABIERTA' && $i['severidad'] === 'ALTA'): ?><div class="small mt-1"><?= e(status_label((string) $i['tipo'])) ?></div><?php endif; endforeach; ?>
                El sistema no elige por prioridad: decida manualmente.</div></div>
        <?php endif; ?>
        <div class="table-card mb-3"><div class="table-card__toolbar"><h2 class="table-card__title">Candidatos</h2><span class="table-card__hint"><?= $r['asociacion_estado'] === 'SUGERENCIA' ? 'Propuesta del sistema: una persona debe confirmarla' : '' ?></span></div>
            <?php if ($r['candidatos'] === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin candidatos</p></div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Empleados candidatos</caption>
                <thead><tr><th scope="col">Empleado</th><th scope="col">Identificadores</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
                <?php foreach ($r['candidatos'] as $c): ?>
                    <tr><td><a href="/empleados/<?= e($c['id']) ?>"><code><?= e($c['numero_empleado']) ?></code> <?= $diff((string) $c['nombre_completo']) ?></a><br><small class="text-muted"><?= e($c['grupo']) ?> · <?= e($c['area'] ?? '') ?> · última quincena <?= e($c['ultimo_periodo']) ?></small></td>
                        <td class="small">RFC <code><?= e($c['rfc'] ?? '—') ?></code><br>CURP <code><?= e($c['curp'] ?? '—') ?></code></td>
                        <td class="cell-actions"><?php if ($canAssoc): ?><?= $assocForm((int) $c['id'], 'Asociar') ?><?php endif; ?>
                            <?php if ($editable && in_array($r['asociacion_estado'], ['SUGERENCIA', 'AMBIGUO'], true)): ?>
                                <form method="post" action="<?= e($base) ?>/rechazar" class="d-inline" data-confirm="El candidato no se volverá a proponer para este registro." data-confirm-title="Rechazar sugerencia" data-confirm-variant="danger" data-confirm-reason="motivo">
                                    <?= $this->csrf() ?><input type="hidden" name="empleado_id" value="<?= e($c['id']) ?>"><input type="hidden" name="version" value="<?= e($r['asociacion_version']) ?>">
                                    <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Rechazar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></button></form>
                            <?php endif; ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>
        <?php if ($r['similares'] !== []): ?>
            <div class="table-card mb-3"><div class="table-card__toolbar"><h2 class="table-card__title">Nombres parecidos (sólo apoyo visual)</h2><span class="table-card__hint">Parecido difflib ≥ 0.85; nunca asocia automáticamente</span></div>
                <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Nombres parecidos (sólo apoyo visual)</caption>
                <thead><tr><th scope="col">Empleado</th><th scope="col">Parecido</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
                <?php foreach ($r['similares'] as $c): ?>
                    <tr><td><a href="/empleados/<?= e($c['id']) ?>"><code><?= e($c['numero_empleado']) ?></code> <?= $diff((string) $c['nombre_completo']) ?></a></td><td class="small num"><?= e(number_format((float) $c['parecido'] * 100)) ?> %</td>
                        <td class="cell-actions"><?php if ($canAssoc): ?><?= $assocForm((int) $c['id'], 'Asociar') ?><?php endif; ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
                <p class="small text-muted px-3 pb-2 mb-0">Resaltadas: palabras del empleado que no aparecen en el registro.</p>
            </div>
        <?php endif; ?>
        <?php if ($canAssoc): ?>
            <?php if ($r['empleado'] && $canConfirm): ?>
                <div class="card mb-3"><div class="card-header card-header--accent">Confirmar la cuenta del empleado asociado</div><div class="card-body">
                    <p class="small mb-2">La asociación pasa a ser manual y la <?= e($r['tipo_cuenta'] === 'CLABE' ? 'CLABE' : 'cuenta') ?> queda como medio de pago conocido de <strong><?= e($r['empleado']['numero_empleado']) ?></strong>.</p>
                    <?= $assocForm((int) $r['empleado']['id'], 'Confirmar') ?>
                </div></div>
            <?php endif; ?>
            <div class="card mb-3"><div class="card-header card-header--accent">Asociar a otro empleado</div><div class="card-body">
                <form method="post" action="<?= e($base) ?>/asociar" class="row g-2 align-items-end" data-confirm="Se asociará el registro al empleado con ese número." data-confirm-title="Asociar registro" data-confirm-reason="motivo">
                    <?= $this->csrf() ?><input type="hidden" name="version" value="<?= e($r['asociacion_version']) ?>">
                    <div class="col-sm-5"><label class="form-label small mb-1" for="numero_empleado">Número de empleado<?= $pension ? ' (trabajador)' : '' ?></label><input class="form-control form-control-sm" id="numero_empleado" name="numero_empleado" maxlength="20" required inputmode="numeric" autocomplete="off"></div>
                    <?php if ($canConfirm): ?><div class="col-sm-7 form-check small"><input class="form-check-input" type="checkbox" name="confirmar_cuenta" value="1" id="cc_manual"><label class="form-check-label" for="cc_manual">Confirmar la cuenta/CLABE como medio de pago conocido</label></div><?php endif; ?>
                    <div class="col-12"><button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Asociar</span><span class="btn-action__icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span></button></div>
                </form>
                <?php if ($pending): ?>
                    <hr><form method="post" action="<?= e($base) ?>/no-identificado" data-confirm="El registro queda cerrado como no identificado (sin empleado). Puede asociarse después." data-confirm-title="Marcar no identificado" data-confirm-variant="danger" data-confirm-reason="motivo">
                        <?= $this->csrf() ?><input type="hidden" name="version" value="<?= e($r['asociacion_version']) ?>">
                        <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Marcar no identificado</span><span class="btn-action__icon"><i class="bi bi-person-x" aria-hidden="true"></i></span></button></form>
                <?php endif; ?>
            </div></div>
        <?php elseif ($this->can('asociaciones.resolver') && !$editable): ?>
            <p class="small text-muted">La asociación no puede modificarse: importación no vigente, periodo cerrado o lote anulado.</p>
        <?php endif; ?>
        <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Historial de la asociación</h2></div>
            <?php if ($r['historial'] === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin historial</p></div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Historial de la asociación</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Acción</th><th scope="col">Estado anterior → nuevo</th><th scope="col">Usuario</th></tr></thead><tbody>
            <?php foreach ($r['historial'] as $h): ?>
                <tr><td class="small"><?= e(fmt_datetime($h['created_at'])) ?></td><td><?= e(status_label((string) $h['accion'])) ?></td>
                    <td class="small"><?= e($h['estado_anterior'] !== null ? status_label((string) $h['estado_anterior']) : '—') ?> → <?= e(status_label((string) $h['estado_nuevo'])) ?><?= $h['motivo'] ? '<br><span class="text-muted">' . e($h['motivo']) . '</span>' : '' ?></td>
                    <td class="small"><?= e($h['username'] ?? 'El sistema') ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
