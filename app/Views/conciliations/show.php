<?php
/** @var \ConciliaRH\Core\Template $this */
/** @var \ConciliaRH\Domain\Auth\CurrentUser $currentUser */
$this->layout('layouts/app', ['title' => $title]);
$id = (int) $c['id'];
$base = '/conciliaciones/' . $id;
$grupos = array_diff(array_keys($c['totales']), ['TOTAL']);
$terminada = in_array($c['estado'], ['COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS', 'REVISION_MANUAL'], true);
$money = static fn (mixed $v): string => money($v);
$niveles = [4 => 'Neto de RH contra lo pagado por Tesorería (nómina)', 5 => 'Pensión retenida por RH contra la dispersada',
            1 => 'Pólizas: tabulado de RH contra póliza GLOBAL', 2 => 'Pólizas: póliza GLOBAL contra pólizas de egreso',
            3 => 'Pólizas: póliza de egreso contra dispersión y total del archivo'];
$porNivel = [];
foreach ($controls as $k) {
    $porNivel[(int) $k['nivel']][] = $k;
}
?>
<div class="page-header">
    <div>
        <h1>Cotejo de <?= e($c['periodo']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge((string) $c['estado']) ?>
            <?= $c['lote_oficial'] ? status_badge('OFICIAL', 'Oficial') : status_badge('NO_OFICIAL', 'No oficial: resultado provisional') ?>
            <span class="small text-muted"><?= $currentUser->isGod() ? 'Lote ' . e(sprintf('%03d', $c['lote_numero'])) . ' · cotejo n.º ' . e($c['numero']) . ' · motor v' . e($c['motor_version'] ?? '—') . ' · ' : '' ?>solicitado por <?= e($c['solicitado_por_usuario']) ?> el <?= e(fmt_datetime($c['created_at'])) ?><?= $c['terminada_at'] ? ' · terminada ' . e(fmt_datetime($c['terminada_at'])) : '' ?></span>
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($terminada || $c['estado'] === 'CERRADA'): ?>
            <a class="btn-action btn-view" href="<?= e($base) ?>/empleados"><span class="btn-action__label">Personas</span><span class="btn-action__icon"><i class="bi bi-people" aria-hidden="true"></i></span></a>
            <a class="btn-action btn-view" href="<?= e($base) ?>/pensiones"><span class="btn-action__label">Pensiones</span><span class="btn-action__icon"><i class="bi bi-person-heart" aria-hidden="true"></i></span></a>
            <?php if ($currentUser->can('incidencias.ver')): ?><a class="btn-action btn-view" href="<?= e($base) ?>/incidencias"><span class="btn-action__label">Incidencias</span><span class="btn-action__icon"><i class="bi bi-flag" aria-hidden="true"></i></span></a><?php endif; ?>
            <?php if ($currentUser->can('conciliaciones.exportar')): ?>
                <?php foreach (['xlsx' => 'XLSX', 'csv' => 'CSV'] as $fmt => $lbl): ?>
                    <form method="post" action="<?= e($base) ?>/exportar" class="d-inline" data-confirm="La exportación contiene nombres y queda registrada en auditoría." data-confirm-title="Exportar <?= e($lbl) ?>">
                        <?= $this->csrf() ?><input type="hidden" name="formato" value="<?= e($fmt) ?>">
                        <button class="btn-action btn-export" type="submit"><span class="btn-action__label"><?= e($lbl) ?></span><span class="btn-action__icon"><i class="bi bi-download" aria-hidden="true"></i></span></button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($terminada && $currentUser->can('conciliaciones.cerrar')): ?>
            <form method="post" action="<?= e($base) ?>/cerrar" class="d-inline" data-confirm="Al cerrar, el cotejo y sus incidencias ya no admiten cambios. Las incidencias abiertas quedan registradas en el cierre." data-confirm-title="Cerrar cotejo" data-confirm-reason="motivo">
                <?= $this->csrf() ?><button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Cerrar</span><span class="btn-action__icon"><i class="bi bi-lock" aria-hidden="true"></i></span></button>
            </form>
        <?php endif; ?>
        <?php if (!in_array($c['estado'], ['INVALIDADA', 'EJECUTANDO'], true) && $currentUser->can('conciliaciones.invalidar') && ($c['estado'] !== 'CERRADA' || $currentUser->isGod())): ?>
            <form method="post" action="<?= e($base) ?>/invalidar" class="d-inline" data-confirm="El cotejo se marca como inválido; se conserva completo en el historial." data-confirm-title="Invalidar cotejo" data-confirm-variant="danger" data-confirm-reason="motivo">
                <?= $this->csrf() ?><button class="btn-action btn-delete" type="submit"><span class="btn-action__label">Invalidar</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($c['estado'] === 'CERRADA'): ?>
    <div class="notice notice--success" role="status"><i class="bi bi-lock-fill notice__icon" aria-hidden="true"></i><div class="notice__body">Cerrada por <?= e($c['cerrada_por_usuario']) ?> el <?= e(fmt_datetime($c['cerrada_at'])) ?>: <?= e($c['motivo_cierre']) ?></div></div>
<?php elseif ($c['estado'] === 'INVALIDADA'): ?>
    <div class="notice notice--error" role="status"><i class="bi bi-slash-circle notice__icon" aria-hidden="true"></i><div class="notice__body">Invalidada por <?= e($c['invalidada_por_usuario']) ?> el <?= e(fmt_datetime($c['invalidada_at'])) ?>: <?= e($c['motivo_invalidacion']) ?></div></div>
<?php elseif ($c['estado'] === 'ERROR'): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon notice__icon" aria-hidden="true"></i><div class="notice__body">El sistema no pudo terminar el cotejo (<?= e($c['error_codigo'] ?? 'ERROR') ?>). <?= e($c['error_mensaje'] ?? '') ?> Vuelva a cotejar; si se repite, avise a la administración técnica.</div></div>
<?php elseif ($c['estado'] === 'REVISION_MANUAL'): ?>
    <div class="notice notice--warning" role="status"><i class="bi bi-exclamation-diamond notice__icon" aria-hidden="true"></i><div class="notice__body">Hay incidencias <strong>críticas</strong>: los resultados se calcularon pero requieren revisión manual antes de darlos por buenos.</div></div>
<?php endif; ?>
<?php if ($c['igual_a_id']): ?>
    <div class="notice notice--info" role="status"><i class="bi bi-fingerprint notice__icon" aria-hidden="true"></i><div class="notice__body">Mismos archivos y mismas reglas que <a href="/conciliaciones/<?= e($c['igual_a_id']) ?>">otro cotejo de esta quincena</a>: el resultado es idéntico.</div></div>
<?php endif; ?>

<?php if ($esUltima && $cambios['hay_cambios'] && $c['estado'] !== 'INVALIDADA'): ?>
    <div class="notice notice--warning" role="status">
        <i class="bi bi-arrow-repeat notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Hubo cambios después de este cotejo</p>Este cotejo no cambia; vuelva a cotejar para reflejarlos.
            <ul class="small mb-2 mt-1">
                <?php if ($cambios['asociaciones']): ?><li><?= e($cambios['asociaciones']) ?> pago(s) con identificación nueva (<?= e($cambios['empleados']) ?> persona(s))</li><?php endif; ?>
                <?php if ($cambios['cuentas']): ?><li><?= e($cambios['cuentas']) ?> cuenta(s)/CLABE confirmadas</li><?php endif; ?>
                <?php if ($cambios['beneficiarios']): ?><li><?= e($cambios['beneficiarios']) ?> relación(es) beneficiario–trabajador nuevas o corregidas</li><?php endif; ?>
                <?php if ($cambios['importaciones']): ?><li><?= e($cambios['importaciones']) ?> archivo(s) nuevos o sustituidos</li><?php endif; ?>
                <?php if (!empty($cambios['fusiones'])): ?><li><?= e($cambios['fusiones']) ?> fusión(es) o separación(es) de empleados con registros en la quincena</li><?php endif; ?>
                <li><?= e($cambios['incidencias']) ?> incidencia(s) abiertas potencialmente afectadas</li>
            </ul>
            <span class="small text-muted">Los importes finales se calculan al volver a cotejar.</span>
        </div>
        <?php if ($c['estado'] !== 'CERRADA' && $this->can('conciliaciones.reprocesar')): ?>
            <form method="post" action="/conciliaciones" data-confirm="Se hará el cotejo n.º <?= e((int) $c['numero'] + 1) ?>. El actual se conserva sin cambios." data-confirm-title="Volver a cotejar" data-confirm-reason="motivo">
                <?= $this->csrf() ?><input type="hidden" name="lote_id" value="<?= e($c['lote_id']) ?>">
                <button class="btn-action btn-reconcile btn-action--sm" type="submit"><span class="btn-action__label">Volver a cotejar</span><span class="btn-action__icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span></button></form>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($siblings !== []): ?>
    <p class="small mb-3">Comparar con:
        <?php foreach ($siblings as $sb): ?><a class="ms-1" href="/conciliaciones/<?= e($c['id']) ?>/comparar/<?= e($sb['id']) ?>">cotejo n.º <?= e($sb['numero']) ?></a><?php endforeach; ?></p>
<?php endif; ?>
<?= $this->insert('conciliations/_summary', ['c' => $c]) ?>
<?= $this->insert('conciliations/_charts', ['c' => $c]) ?>

<?php if ($c['totales'] !== []): ?>
<div class="row g-3 mb-3">
    <?php foreach (array_merge($grupos, ['TOTAL']) as $g): $b = $c['totales'][$g]; ?>
        <div class="col-lg-<?= count($grupos) > 1 ? '4' : '6' ?>">
            <div class="card h-100">
                <div class="card-header card-header--accent d-flex justify-content-between"><span><?= e(['TOTAL' => 'Total', 'POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos'][$g] ?? ucfirst(strtolower($g))) ?></span>
                    <span class="small text-muted"><?= e(number_format((int) $b['rrhh']['registros'])) ?> en RH · <?= e(number_format((int) $b['tesoreria_nomina']['registros'])) ?> pagos</span></div>
                <div class="card-body">
                    <dl class="dl-grid dl-grid--num">
                        <dt>Neto de RH</dt><dd><?= $money($b['rrhh']['neto']) ?></dd>
                        <dt>Pagado por Tesorería</dt><dd><?= $money($b['tesoreria_nomina']['total']) ?></dd>
                        <dt class="dl-grid__sub">con persona identificada</dt><dd><?= $money($b['tesoreria_nomina']['identificado']) ?></dd>
                        <dt class="dl-grid__sub">sin persona identificada</dt><dd><?= $money($b['tesoreria_nomina']['pendiente_identidad']) ?></dd>
                        <dt>Diferencia (pagado − RH)</dt><dd class="fw-bold"><?= money_diff($b['diferencia_global_nomina'], (string) $c['tolerancia']) ?></dd>
                    </dl>
                    <div class="bank-grid mt-3">
                        <?php foreach ($b['tesoreria_nomina']['por_banco'] as $bk => $v): if ($v === '0.00' || $v === '0') continue; ?>
                            <div class="bank-grid__item"><div class="bank-grid__label"><?= e(bank_label($bk)) ?></div><div class="bank-grid__value"><?= money($v) ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <dl class="dl-grid dl-grid--num">
                        <dt>Pensión retenida por RH</dt><dd><?= $money($b['pensiones']['retenido']) ?></dd>
                        <dt>Pensión dispersada</dt><dd><?= $money($b['pensiones']['dispersado']) ?></dd>
                        <dt class="dl-grid__sub">sin persona identificada</dt><dd><?= $money($b['pensiones']['pendiente_identidad']) ?></dd>
                    </dl>
                    <div class="state-pills mt-3">
                        <?php foreach ($b['empleados'] as $st => $n): ?>
                            <a class="text-decoration-none" href="<?= e($base) ?>/empleados?estado=<?= e($st) ?><?= $g !== 'TOTAL' ? '&grupo=' . e($g) : '' ?>"><?= status_badge((string) $st, status_label((string) $st) . ': ' . $n) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $polizasAbiertas = false; foreach ($niveles as $n => $titulo): if (empty($porNivel[$n])) continue; ?>
    <?php if ($n <= 3 && !$polizasAbiertas): $polizasAbiertas = true; ?><details class="slot-optional mb-3"><summary>Cuadre contable con pólizas</summary><?php endif; ?>
    <div class="table-card mb-3">
        <div class="table-card__toolbar"><h2 class="table-card__title"><?= e($titulo) ?></h2></div>
        <div class="table-responsive"><table class="table align-middle table-stack">
            <caption class="visually-hidden"><?= e($titulo) ?>. A, B y C son los importes comparados (cada celda indica su origen)</caption>
            <thead><tr><th scope="col">Control</th><th scope="col" class="col-hide-sm">Población / banco</th><th scope="col" class="num">A</th><th scope="col" class="num">B</th><th scope="col" class="num col-hide-md">C</th><th scope="col" class="num">Diferencia</th><th scope="col">Estado</th></tr></thead>
            <tbody>
            <?php foreach ($porNivel[$n] as $k): ?>
                <tr>
                    <td><?= e($k['descripcion']) ?><?php if (!empty($k['detalle']['hoja'])): ?><br><small class="text-muted">Hoja «<?= e($k['detalle']['hoja']) ?>»<?= isset($k['detalle']['relacion']) ? ' · relación ' . e(strtolower((string) $k['detalle']['relacion'])) : '' ?></small><?php endif; ?></td>
                    <td class="small col-hide-sm"><?= e(['POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos', 'TOTAL' => 'Total', 'SIN_GRUPO' => 'Sin población'][$k['grupo'] ?? ''] ?? ($k['grupo'] ?? '—')) ?><?= $k['banco'] ? ' · ' . e($k['banco']) : '' ?></td>
                    <td class="num"><?php if ($k['etiqueta_a']): ?><small class="text-muted d-block"><?= e($k['etiqueta_a']) ?></small><?php endif; ?><?= $money($k['valor_a']) ?></td>
                    <td class="num"><?php if ($k['etiqueta_b']): ?><small class="text-muted d-block"><?= e($k['etiqueta_b']) ?></small><?php endif; ?><?= $money($k['valor_b']) ?></td>
                    <td class="num col-hide-md"><?php if ($k['etiqueta_c']): ?><small class="text-muted d-block"><?= e($k['etiqueta_c']) ?></small><?= $money($k['valor_c']) ?><?php endif; ?></td>
                    <td class="num"><?= money_diff($k['diferencia']) ?></td>
                    <td><?= status_badge((string) $k['estado']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endforeach; ?>

<?php if ($polizasAbiertas): ?></details><?php endif; ?>

<?php if ($files !== []):
    $usos = ['FUENTE_RRHH' => 'Nómina de RH', 'TESORERIA_NOMINA' => 'Pagos de nómina', 'TESORERIA_PENSION' => 'Pensiones alimenticias',
             'POLIZA_GLOBAL' => 'Póliza GLOBAL de RH', 'POLIZA_EGRESO' => 'Póliza de egreso bancaria',
             'EXCLUIDO_DERIVADO' => 'No se usó: se generó a partir de otro archivo', 'EXCLUIDO_DUPLICADO' => 'No se usó: duplicado',
             'EXCLUIDO_SUBCONJUNTO' => 'No se usó: su contenido ya está en otro archivo', 'EXCLUIDO_NO_NORMALIZADO' => 'No se usó: no se terminó de leer',
             'EXCLUIDO_ANULADO' => 'No se usó: anulado', 'EXCLUIDO_VERSION_AMBIGUA' => 'No se usó: hay más de una versión'];
?>
<details class="table-card mb-3">
    <summary class="table-card__toolbar"><h2 class="table-card__title">Archivos usados y excluidos (<?= count($files) ?>)</h2></summary>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <caption class="visually-hidden">Archivos usados y excluidos</caption>
        <thead><tr><th scope="col">Archivo</th><th scope="col">Uso en el cotejo</th><th scope="col" class="col-hide-sm">Motivo</th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): ?>
            <tr><td class="small"><a href="/importaciones/<?= e($f['archivo_id']) ?>"><?= e($f['nombre_original']) ?></a></td>
                <td><?= status_badge(str_starts_with((string) $f['rol'], 'EXCLUIDO') ? 'SEV_BAJA' : 'VIGENTE', $usos[(string) $f['rol']] ?? (string) $f['rol']) ?></td>
                <td class="small col-hide-sm"><?= e($f['motivo'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</details>
<?php endif; ?>

<?php if ($c['metricas'] !== [] && $currentUser->isGod()): ?>
    <p class="small text-muted">Rendimiento: <?= e($c['metricas']['total_ms'] ?? '—') ?> ms · <?= e($c['metricas']['consultas_lectura'] ?? '—') ?> consultas · <?= e($c['metricas']['registros_por_segundo'] ?? '—') ?> registros/s · huella <code><?= e(substr((string) $c['huella_entradas'], 0, 16)) ?>…</code></p>
<?php endif; ?>
