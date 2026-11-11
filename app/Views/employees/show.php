<?php
/**
 * Detalle del empleado maestro (FASE 4). Pestañas por enlace (?tab=): el servidor decide qué mostrar.
 * RFC/CURP completos sólo con empleados.ver_sensible; cuentas siempre enmascaradas salvo "Revelar" (POST auditado).
 * @var \ConciliaRH\Core\Template $this
 */
$this->layout('layouts/app', ['title' => $title]);
$e = $emp;
$tabs = ['resumen' => ['Resumen', 'bi-person-badge'], 'identidad' => ['Identidad', 'bi-fingerprint'], 'rrhh' => ['Historial de RH', 'bi-journal-text'],
         'tesoreria' => ['Pagos / Tesorería', 'bi-bank'], 'cuentas' => ['Cuentas', 'bi-credit-card-2-front'], 'incidencias' => ['Incidencias', 'bi-exclamation-diamond'],
         'auditoria' => ['Auditoría relacionada', 'bi-journal-check']];
if (!$this->can('auditoria.ver')) {
    unset($tabs['auditoria']);
}
$openInc = count(array_filter($incidents, static fn ($i) => $i['estado'] === 'ABIERTA'));
?>
<div class="page-header">
    <div>
        <h1><?= e($e['nombre_completo']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
            <code>No. <?= e($e['numero_empleado']) ?></code> <?= status_badge($e['activo'] ? 'ACTIVO' : 'INACTIVO') ?>
            <?= status_badge('GRUPO', (string) $e['grupo']) ?>
            <?php if ($openInc): ?><?= status_badge('ABIERTA', $openInc . ' incidencia(s)') ?><?php endif; ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/empleados"><span class="btn-action__label">Empleados</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>
<?php if (!empty($e['canonico'])): ?>
    <div class="notice notice--info" role="note"><i class="bi bi-intersect notice__icon" aria-hidden="true"></i><div class="notice__body">
        Este empleado está <strong>fusionado</strong> en <a href="/empleados/<?= e($e['canonico']['id']) ?>"><?= e($e['canonico']['numero_empleado'] . ' · ' . $e['canonico']['nombre_completo']) ?></a>
        (<a href="/empleados/fusiones/<?= e($e['fusion_id']) ?>">fusión #<?= e($e['fusion_id']) ?></a>). Sus registros se conservan y las consultas actuales los muestran bajo la identidad canónica.</div></div>
<?php endif; ?>
<?php if (!empty($e['alias'])): ?>
    <div class="notice notice--info" role="note"><i class="bi bi-intersect notice__icon" aria-hidden="true"></i><div class="notice__body">
        Identidad canónica de: <?php foreach ($e['alias'] as $i => $al): ?><?= $i ? ', ' : '' ?><a href="/empleados/<?= e($al['id']) ?>"><?= e($al['numero_empleado']) ?></a><?php endforeach; ?>.
        Los registros, pagos y cuentas de esos números aparecen aquí.</div></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $k => [$lab, $ic]): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="/empleados/<?= e($e['id']) ?>?tab=<?= e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="bi <?= e($ic) ?> me-1" aria-hidden="true"></i><?= e($lab) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'resumen'): ?>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent">Datos actuales (última quincena conocida)</div><div class="card-body">
            <dl class="dl-grid">
                <dt>Área</dt><dd><?= e($e['area'] ?? '—') ?></dd>
                <dt>Departamento</dt><dd><?= e($e['departamento'] ?? '—') ?></dd>
                <dt>Puesto · cargo</dt><dd><?= e($e['puesto'] ?? '—') ?> · <?= e($e['cargo'] ?? '—') ?></dd>
                <dt>Ingreso · baja</dt><dd><?= e($e['fecha_ingreso'] ?? '—') ?> · <?= e($e['fecha_baja'] ?? '—') ?></dd>
                <dt>Quincenas</dt><dd>Desde <?= e($e['primer_periodo']) ?> hasta <?= e($e['ultimo_periodo']) ?></dd>
            </dl>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent">Origen del registro maestro</div><div class="card-body">
            <dl class="dl-grid">
                <dt>Fuente</dt><dd><?= e($e['fuente_creacion']) ?> · confianza <?= e($e['confianza_identidad']) ?></dd>
                <dt>Archivo · fila</dt><dd><?php if ($e['origen_archivo_id']): ?><a href="/importaciones/<?= e($e['origen_archivo_id']) ?>"><?= e($e['origen_archivo']) ?></a> · hoja «<?= e($e['origen_hoja']) ?>» fila <?= e($e['origen_fila']) ?><?php else: ?>—<?php endif; ?></dd>
                <dt>Registros</dt><dd><?= e(count($rrhh)) ?> de RH · <?= e(count($treasury)) ?> Tesorería · <?= e(count(array_filter($accounts, static fn ($a) => $a['estado'] === 'ACTIVA'))) ?> cuenta(s) activa(s)</dd>
                <dt>Alta</dt><dd><?= e(fmt_datetime($e['created_at'])) ?></dd>
            </dl>
        </div></div></div>
    </div>

<?php elseif ($tab === 'identidad'): ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="card"><div class="card-header card-header--accent">Identidad</div><div class="card-body">
            <dl class="dl-grid">
                <dt>Nombre completo</dt><dd><?= e($e['nombre_completo']) ?><br><small class="text-muted">Clave de comparación: <?= e($e['nombre_normalizado']) ?></small></dd>
                <dt>Apellidos · nombres</dt><dd><?= e($e['apellido_paterno'] ?? '—') ?> · <?= e($e['apellido_materno'] ?? '—') ?> · <?= e($e['nombres'] ?? '—') ?></dd>
                <dt>RFC</dt><dd><code><?= e($e['rfc'] ?? '—') ?></code> <?= $e['rfc'] !== null ? status_badge($e['rfc_valido'] ? 'VIGENTE' : 'INVALIDO', $e['rfc_valido'] ? 'Estructura válida' : 'Estructura inválida') : '' ?></dd>
                <dt>CURP</dt><dd><code><?= e($e['curp'] ?? '—') ?></code> <?= $e['curp'] !== null ? status_badge($e['curp_valida'] ? 'VIGENTE' : 'INVALIDO', $e['curp_valida'] ? 'Estructura válida' : 'Estructura inválida') : '' ?></dd>
                <dt>ISSEMYM</dt><dd><?= e($e['issemym'] ?? '—') ?></dd>
                <dt>Observaciones</dt><dd><?= e($e['observaciones'] ?? '—') ?></dd>
            </dl>
            <?php if (!$this->can('empleados.ver_sensible')): ?><p class="small text-muted mb-0"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>RFC, CURP e ISSEMYM enmascarados según su perfil.</p><?php endif; ?>
        </div></div>
        <?php if ($this->can('empleados.editar')): ?>
            <div class="card mt-3"><div class="card-header card-header--accent">Corregir datos (con motivo)</div><div class="card-body">
                <form method="post" action="/empleados/<?= e($e['id']) ?>" data-confirm="El cambio queda en el historial del empleado y en la auditoría." data-confirm-title="Corregir datos del empleado" data-confirm-reason="motivo">
                    <?= $this->csrf() ?>
                    <div class="row g-2">
                        <?php foreach (['apellido_paterno' => 'Apellido paterno', 'apellido_materno' => 'Apellido materno', 'nombres' => 'Nombre(s)', 'issemym' => 'ISSEMYM', 'rfc' => 'RFC', 'curp' => 'CURP'] as $k => $l): ?>
                            <div class="col-sm-6"><label class="form-label small mb-1" for="f_<?= e($k) ?>"><?= e($l) ?></label>
                                <input class="form-control form-control-sm<?= isset($errors[$k]) ? ' is-invalid' : '' ?>" id="f_<?= e($k) ?>" name="<?= e($k) ?>" maxlength="100"
                                       value="<?= e($this->old($k, (string) ($this->can('empleados.ver_sensible') || !in_array($k, ['rfc', 'curp', 'issemym'], true) ? ($e[$k] ?? '') : ''))) ?>"
                                       autocomplete="off"<?= field_invalid('f_' . $k, $errors[$k] ?? null) ?><?= !$this->can('empleados.ver_sensible') && in_array($k, ['rfc', 'curp', 'issemym'], true) ? ' disabled' : '' ?>>
                                <?= field_error('f_' . $k, $errors[$k] ?? null) ?></div>
                        <?php endforeach; ?>
                        <div class="col-12"><label class="form-label small mb-1" for="f_obs">Observaciones</label><textarea class="form-control form-control-sm" id="f_obs" name="observaciones" maxlength="2000" rows="2"><?= e($this->old('observaciones', (string) ($e['observaciones'] ?? ''))) ?></textarea></div>
                        <div class="col-12 form-check ms-2"><input type="hidden" name="activo" value="0"><input class="form-check-input" type="checkbox" id="f_act" name="activo" value="1"<?= $e['activo'] ? ' checked' : '' ?>><label class="form-check-label small" for="f_act">Activo</label></div>
                    </div>
                    <p class="small text-muted mt-2 mb-2">El número de empleado y el origen no se modifican. Un RFC/CURP que ya pertenece a otro empleado se rechaza (no hay fusión automática).</p>
                    <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Guardar corrección</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button>
                </form>
            </div></div>
        <?php endif; ?>
        </div>
        <div class="col-lg-7"><div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Historial de identidad</h2></div>
            <?php if ($history === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin cambios registrados</p></div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Historial de identidad</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Campo</th><th scope="col">Anterior → nuevo</th><th scope="col">Fuente</th></tr></thead><tbody>
                <?php foreach ($history as $h): ?>
                    <tr><td class="small"><?= e(fmt_datetime($h['created_at'])) ?></td><td><code><?= e($h['campo']) ?></code></td>
                        <td class="small text-break"><?= e($h['valor_anterior'] ?? '—') ?> → <strong><?= e($h['valor_nuevo'] ?? '—') ?></strong><?= $h['motivo'] ? '<br><span class="text-muted">' . e($h['motivo']) . '</span>' : '' ?></td>
                        <td class="small"><?= e($h['fuente']) ?><?= $h['username'] ? ' · ' . e($h['username']) : '' ?><?= $h['periodo'] ? '<br>' . e($h['periodo']) : '' ?><?= $h['archivo_id'] ? ' · <a href="/importaciones/' . e($h['archivo_id']) . '">archivo</a> fila ' . e($h['fila_origen']) : '' ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div></div>
    </div>

<?php elseif ($tab === 'rrhh'): ?>
    <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Snapshots de nómina RRHH por quincena</h2><span class="table-card__hint">Datos tal como venían en cada quincena</span></div>
        <?php if ($rrhh === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin registros RRHH</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle table-sticky-first"><caption class="visually-hidden">Registros RRHH por quincena</caption>
            <thead><tr><th scope="col">Quincena · lote</th><th scope="col">Nombre en el tabulado</th><th scope="col" class="col-hide-md">Área · puesto · cargo</th><th scope="col" class="num">Percep.</th><th scope="col" class="num">Deduc.</th><th scope="col" class="num">Neto</th><th scope="col" class="num col-hide-sm">Pensión</th><th scope="col">Origen</th></tr></thead><tbody>
            <?php foreach ($rrhh as $r): ?>
                <tr><td class="small"><?= e($r['periodo']) ?><br><a href="/lotes/<?= e($r['lote_id']) ?>">Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></a><?= $r['es_oficial'] ? ' ' . status_badge('OFICIAL') : '' ?></td>
                    <td class="small text-break"><?= e($r['nombre_original']) ?></td>
                    <td class="small col-hide-md"><?= e($r['area'] ?? '—') ?><br><span class="text-muted"><?= e($r['puesto'] ?? '') ?> · <?= e($r['cargo'] ?? '') ?></span></td>
                    <td class="num small"><?= money($r['percepciones']) ?></td><td class="num small"><?= money($r['deducciones']) ?></td>
                    <td class="num small fw-600"><?= money($r['neto']) ?></td><td class="num small col-hide-sm"><?= (string) $r['pension'] !== '0' && (string) $r['pension'] !== '0.00' ? money($r['pension']) : '<span class="diff--none">—</span>' ?></td>
                    <td class="small"><a href="/importaciones/<?= e($r['archivo_id']) ?>">Archivo</a> · fila <?= e($r['fila_origen']) ?><br><a href="/asociaciones/rrhh/<?= e($r['id']) ?>"><?= status_badge((string) $r['asociacion_estado']) ?></a></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'tesoreria'): ?>
    <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Registros de Tesorería asociados</h2><span class="table-card__hint">En pensiones el empleado es el trabajador; el nombre es del beneficiario</span></div>
        <?php if ($treasury === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin registros de Tesorería</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle table-stack"><caption class="visually-hidden">Registros de Tesorería asociados</caption>
            <thead><tr><th scope="col">Quincena · lote</th><th scope="col">Banco · categoría</th><th scope="col">Nombre en el archivo</th><th scope="col">Cuenta</th><th scope="col" class="num">Importe</th><th scope="col">Origen</th></tr></thead><tbody>
            <?php foreach ($treasury as $r): ?>
                <tr><td class="small"><?= e($r['periodo']) ?><br><a href="/lotes/<?= e($r['lote_id']) ?>">Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></a></td>
                    <td class="small"><?= e($r['banco']) ?><br><span class="text-muted"><?= e($r['categoria']) ?></span></td>
                    <td class="small text-break"><?= e($r['nombre_original'] ?? '—') ?></td>
                    <td><code><?= e(\ConciliaRH\Domain\Identity\AccountVault::mask($r['cuenta_ultimos4'])) ?></code></td>
                    <td class="num small fw-600"><?= money($r['importe']) ?></td>
                    <td class="small"><a href="/importaciones/<?= e($r['archivo_id']) ?>">Archivo</a> · fila <?= e($r['fila_origen']) ?><br><a href="/asociaciones/tesoreria/<?= e($r['id']) ?>"><?= status_badge((string) $r['asociacion_estado']) ?></a></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'cuentas'): ?>
    <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Cuentas y CLABE</h2><span class="table-card__hint">Una cuenta nueva nunca reemplaza a la anterior</span></div>
        <?php if ($accounts === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin cuentas registradas</p><p class="mb-0">Se registran por evidencia fuerte (número de empleado) o por confirmación manual.</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Cuentas y CLABE del empleado</caption>
            <thead><tr><th scope="col">Cuenta</th><th scope="col">Uso</th><th scope="col">Origen</th><th scope="col">Vista</th><th scope="col">Estado</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
            <?php foreach ($accounts as $a): ?>
                <tr><td><code class="fs-6"><?= isset($revealed[(int) $a['id']]) ? e($revealed[(int) $a['id']]) : e(\ConciliaRH\Domain\Identity\AccountVault::mask($a['cuenta_ultimos4'])) ?></code><br><small class="text-muted"><?= e($a['tipo']) ?> · <?= e($a['banco'] ?? '—') ?></small></td>
                    <td class="small"><?= $a['uso'] === 'NOMINA' ? 'Nómina' : 'Pensión (beneficiario)' ?><?= $a['beneficiario_nombre'] ? '<br><span class="text-muted">' . e($a['beneficiario_nombre']) . '</span>' : '' ?></td>
                    <td class="small"><?= $a['origen'] === 'EVIDENCIA_FUERTE' ? 'Evidencia fuerte' : 'Confirmación manual' ?><?= $a['confirmado_por_usuario'] ? '<br>' . e($a['confirmado_por_usuario']) . ' · ' . e(fmt_datetime($a['confirmado_at'])) : '' ?><br><a href="/importaciones/<?= e($a['origen_archivo_id']) ?>">Archivo</a> fila <?= e($a['origen_fila']) ?></td>
                    <td class="small"><?= e($a['primer_periodo']) ?> → <?= e($a['ultimo_periodo']) ?><br><span class="text-muted"><?= e($a['veces_vista']) ?> vez/veces</span></td>
                    <td><?= status_badge((string) $a['estado']) ?><?= $a['desactivacion_motivo'] ? '<br><small class="text-muted">' . e($a['desactivacion_motivo']) . '</small>' : '' ?></td>
                    <td class="cell-actions"><div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                        <?php if ($this->can('cuentas.ver_sensible') && !isset($revealed[(int) $a['id']])): ?>
                            <form method="post" action="/empleados/<?= e($e['id']) ?>/cuentas/<?= e($a['id']) ?>/revelar" data-confirm="El número completo se mostrará una sola vez y el acceso quedará registrado en la auditoría." data-confirm-title="Revelar cuenta">
                                <?= $this->csrf() ?><button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Revelar</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></button></form>
                        <?php endif; ?>
                        <?php if ($a['estado'] === 'ACTIVA' && $this->can('cuentas.confirmar')): ?>
                            <form method="post" action="/empleados/<?= e($e['id']) ?>/cuentas/<?= e($a['id']) ?>/desactivar" data-confirm="La cuenta deja de identificar al empleado en cargas futuras. No se borra." data-confirm-title="Desactivar cuenta" data-confirm-variant="danger" data-confirm-reason="motivo">
                                <?= $this->csrf() ?><button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Desactivar</span><span class="btn-action__icon"><i class="bi bi-pause-circle" aria-hidden="true"></i></span></button></form>
                        <?php endif; ?>
                    </div></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'incidencias'): ?>
    <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Incidencias de identidad</h2></div>
        <?php if ($incidents === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin incidencias</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle table-stack"><caption class="visually-hidden">Incidencias de identidad del empleado</caption>
            <thead><tr><th scope="col">Tipo</th><th scope="col">Severidad</th><th scope="col">Estado</th><th scope="col">Registro</th><th scope="col">Fecha</th></tr></thead><tbody>
            <?php foreach ($incidents as $i): ?>
                <tr><td><code><?= e($i['tipo']) ?></code></td><td><?= status_badge('SEV_' . $i['severidad']) ?></td><td><?= status_badge((string) $i['estado']) ?><?= $i['resolucion'] ? '<br><small class="text-muted">' . e($i['resolucion']) . '</small>' : '' ?></td>
                    <td class="small"><?= $i['registro_id'] ? '<a href="/asociaciones/' . e(strtolower((string) $i['registro_tipo'])) . '/' . e($i['registro_id']) . '">' . e($i['registro_tipo']) . ' #' . e($i['registro_id']) . '</a>' : 'Maestro' ?></td>
                    <td class="small"><?= e(fmt_datetime($i['created_at'])) ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'auditoria'): ?>
    <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Auditoría relacionada</h2></div>
        <?php if ($audit === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin eventos</p></div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Auditoría relacionada con el empleado</caption>
            <thead><tr><th scope="col">Fecha</th><th scope="col">Acción</th><th scope="col">Usuario</th><th scope="col" class="cell-actions"><span class="visually-hidden">Ver</span></th></tr></thead><tbody>
            <?php foreach ($audit as $a): ?>
                <tr><td class="small"><?= e(fmt_datetime($a['creado_at'], true)) ?></td><td><code><?= e($a['accion']) ?></code></td><td class="small"><?= e($a['username'] ?? $a['origen']) ?></td>
                    <td class="cell-actions"><a class="small" href="/auditoria/<?= e($a['id']) ?>">Detalle</a></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
