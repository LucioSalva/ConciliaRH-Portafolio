<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$p = $period;
$open = $p['estado'] === 'ABIERTO';
$pid = (int) $p['id'];
$puedeCargar = $open && $this->can('archivos.cargar') && ($q['lot'] === null || $q['lot']['estado'] === 'ABIERTO');
$estadoArchivo = static function (array $f): string {
    $e = (string) $f['estado'];
    if (in_array($e, ['RECIBIDO', 'VERIFICANDO', 'VERIFICADO', 'ANALIZANDO'], true)
        || ($f['normalizacion'] === null && in_array($e, ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true))) {
        return status_badge('PROCESANDO', 'Leyendo');
    }
    return match ($e) {
        'ANALIZADO' => status_badge('CORRECTO', 'Listo'),
        'ANALIZADO_CON_ADVERTENCIAS' => status_badge('ANALIZADO_CON_ADVERTENCIAS', 'Listo con avisos'),
        'RECHAZADO' => status_badge('RECHAZADO', 'Rechazado'),
        'ERROR' => status_badge('ERROR', 'No se pudo leer'),
        'REVISION_MANUAL' => status_badge('REVISION_MANUAL', 'Requiere revisión'),
        default => status_badge($e),
    };
};
$c = $q['conciliacion'];
$tot = $c !== null && $c['totales'] !== null ? (json_decode((string) $c['totales'], true)['TOTAL'] ?? null) : null;
$res = $c !== null && $c['resumen'] !== null ? json_decode((string) $c['resumen'], true) : null;
$tol = $c !== null ? (string) $c['tolerancia'] : '0.01';
$renderFile = function (array $f) use ($estadoArchivo, $pid): string {
    ob_start(); ?>
    <li class="slot-file">
        <a class="slot-file__name" href="/importaciones/<?= e($f['id']) ?>"><?= e($f['nombre_original']) ?></a>
        <span class="slot-file__state"><?= $estadoArchivo($f) ?></span>
        <?php if ($f['normalizacion'] !== null): ?>
            <span class="slot-file__meta">
                <?php if ($f['banco'] !== null): ?><?= e(bank_label($f['banco'])) ?> · <?php endif; ?>
                <?= e(number_format((int) $f['registros'])) ?> <?= (int) $f['registros'] === 1 ? 'registro' : 'registros' ?>
                <?php if ($f['total'] !== null): ?> · <span class="money"><?= money((string) $f['total']) ?></span><?php endif; ?>
            </span>
            <?php if ((int) ($f['sin_identificar'] ?? 0) > 0): ?>
                <span class="slot-file__note"><?= e(number_format((int) $f['sin_identificar'])) ?> sin número de empleado: se identifican al cotejar o en «Pendientes de identificar».</span>
            <?php endif; ?>
        <?php elseif (in_array($f['estado'], ['RECHAZADO', 'ERROR'], true) && $f['error_resumen']): ?>
            <span class="slot-file__note slot-file__note--error"><?= e($f['error_resumen']) ?></span>
        <?php endif; ?>
    </li>
    <?php return (string) ob_get_clean();
};
$renderUpload = function (string $columna, ?string $poblacion, string $etiqueta, bool $multiple) use ($pid, $duplicado): string {
    $fid = 'carga-' . strtolower($columna) . '-' . strtolower((string) $poblacion);
    ob_start(); ?>
    <form method="post" action="/periodos/<?= e($pid) ?>/archivos" enctype="multipart/form-data" class="slot-upload" data-busy>
        <?= $this->csrf() ?>
        <input type="hidden" name="columna" value="<?= e($columna) ?>">
        <?php if ($poblacion !== null): ?><input type="hidden" name="poblacion" value="<?= e($poblacion) ?>"><?php endif; ?>
        <label class="visually-hidden" for="<?= e($fid) ?>"><?= e($etiqueta) ?></label>
        <input class="form-control form-control-sm" type="file" id="<?= e($fid) ?>" name="archivos[]" accept=".xls,.xlsx,.xlsm" required<?= $multiple ? ' multiple' : '' ?>>
        <?php if ($duplicado === $columna . ':' . ($poblacion ?? '')): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="<?= e($fid) ?>-dup" name="confirmar_duplicado" value="1">
                <label class="form-check-label" for="<?= e($fid) ?>-dup">El archivo ya se cargó en otra quincena y aun así corresponde a esta</label>
            </div>
        <?php endif; ?>
        <button class="btn-action btn-import btn-action--sm" type="submit"><span class="btn-action__label">Cargar</span><span class="btn-action__icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></span></button>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-header">
    <div>
        <h1><?= e($p['descripcion']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge((string) $p['estado']) ?>
            <span><?= e(date('d/m/Y', strtotime((string) $p['fecha_inicio']))) ?> – <?= e(date('d/m/Y', strtotime((string) $p['fecha_fin']))) ?></span>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/periodos"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<?php if ($p['motivo_estado']): ?>
    <div class="notice notice--info" role="note"><i class="bi bi-info-circle-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><p class="notice__title">Último cambio de estado</p><?= e($p['motivo_estado']) ?></div></div>
<?php endif; ?>
<?php if ($q['en_proceso'] > 0): ?>
    <div class="notice notice--info" role="status"><span class="loader" aria-hidden="true"></span><div class="notice__body">
        El sistema está leyendo <?= e($q['en_proceso']) ?> <?= $q['en_proceso'] === 1 ? 'archivo' : 'archivos' ?>. Recargue la página en unos momentos para ver el resultado.</div></div>
<?php endif; ?>

<section class="section" aria-labelledby="archivos-t">
    <div class="table-card">
        <div class="table-card__toolbar">
            <h2 class="table-card__title" id="archivos-t">Archivos de la quincena</h2>
            <span class="table-card__hint">Cargue cada archivo en su lugar: el sistema sabe por el lugar qué es y de qué población.</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-stack slot-grid">
                <caption class="visually-hidden">Archivos cargados por población (filas) y por tipo de documento (columnas)</caption>
                <thead><tr><th scope="col">Población</th>
                    <?php foreach ($columnas as $col): ?><th scope="col"><?= e($col['titulo']) ?><span class="slot-grid__help"><?= e($col['ayuda']) ?></span></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($poblaciones as $pob => $pobNombre): ?>
                    <tr>
                        <th scope="row"><?= e($pobNombre) ?></th>
                        <?php foreach ($columnas as $colKey => $col): $lista = $q['celdas'][$pob][$colKey]; ?>
                            <td class="slot<?= $lista === [] ? ' slot--empty' : '' ?>" data-label="<?= e($col['titulo']) ?>">
                                <?php if ($lista === []): ?>
                                    <p class="slot__missing"><?= $colKey === 'PENSIONES' ? 'Sin archivos (sólo si hubo pensiones)' : 'Falta este archivo' ?></p>
                                <?php else: ?>
                                    <ul class="slot-files"><?php foreach ($lista as $f): ?><?= $renderFile($f) ?><?php endforeach; ?></ul>
                                <?php endif; ?>
                                <?php if ($puedeCargar && ($col['multiple'] || $lista === [])): ?>
                                    <?= $renderUpload($colKey, $pob, $col['titulo'] . ' de ' . $pobNombre, (bool) $col['multiple']) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($q['sin_poblacion'] !== []): ?>
        <div class="notice notice--warning" role="status"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body">
            <p class="notice__title">Archivos sin población</p>
            Se cargaron antes de esta pantalla y el sistema no puede saber si son de Policías o de Bomberos. Indíquelo para poder cotejar.
            <ul class="slot-files slot-files--inline">
                <?php foreach ($q['sin_poblacion'] as $f): ?>
                    <li class="slot-file">
                        <a class="slot-file__name" href="/importaciones/<?= e($f['id']) ?>"><?= e($f['nombre_original']) ?></a>
                        <?php if ($open && $this->can('archivos.clasificar')): ?>
                            <span class="d-flex flex-wrap gap-2">
                                <?php foreach ($poblaciones as $pob => $pobNombre): ?>
                                    <form method="post" action="/importaciones/<?= e($f['id']) ?>/poblacion">
                                        <?= $this->csrf() ?><input type="hidden" name="periodo_id" value="<?= e($pid) ?>"><input type="hidden" name="poblacion" value="<?= e($pob) ?>">
                                        <button class="btn-action btn-edit btn-action--sm" type="submit" aria-label="Asignar «<?= e($f['nombre_original']) ?>» a <?= e($pobNombre) ?>"><span class="btn-action__label">Es de <?= e($pobNombre) ?></span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button>
                                    </form>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div></div>
    <?php endif; ?>

    <details class="slot-optional"<?= array_merge(...array_values($q['opcionales'])) !== [] ? ' open' : '' ?>>
        <summary>Pólizas contables (opcional)</summary>
        <p class="form-text">Sólo si quiere cuadrar también contra la contabilidad. Sin pólizas, el cotejo compara RH contra lo pagado por Tesorería.</p>
        <div class="row g-3">
            <?php foreach ($opcionales as $key => $def): ?>
                <div class="col-lg-6">
                    <h3 class="slot-optional__title"><?= e($def['titulo']) ?></h3>
                    <?php if ($q['opcionales'][$key] !== []): ?><ul class="slot-files"><?php foreach ($q['opcionales'][$key] as $f): ?><?= $renderFile($f) ?><?php endforeach; ?></ul><?php endif; ?>
                    <?php if ($puedeCargar): ?><?= $renderUpload($key, null, $def['titulo'], true) ?><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
</section>

<section class="section" aria-labelledby="cotejo-t">
    <div class="card">
        <div class="card-header card-header--accent" id="cotejo-t">Cotejo de RH contra Tesorería</div>
        <div class="card-body">
            <?php if ($c !== null): ?>
                <p class="d-flex flex-wrap gap-2 align-items-center">
                    <?= status_badge((string) $c['estado']) ?>
                    <span>Último cotejo: <?= e(fmt_datetime($c['created_at'])) ?></span>
                </p>
                <?php if ($tot !== null): ?>
                    <dl class="dl-grid dl-grid--num cotejo-figures">
                        <dt>Neto que autorizó RH</dt><dd><?= money((string) $tot['rrhh']['neto']) ?></dd>
                        <dt>Pagado por Tesorería</dt><dd><?= money((string) $tot['tesoreria_nomina']['total']) ?></dd>
                        <dt>Diferencia</dt><dd><?= money_diff((string) $tot['diferencia_global_nomina'], $tol) ?></dd>
                        <dt>Pensión retenida / dispersada</dt><dd><?= money((string) $tot['pensiones']['retenido']) ?> / <?= money((string) $tot['pensiones']['dispersado']) ?></dd>
                    </dl>
                    <?php if (is_array($res)): ?>
                        <p class="mb-3"><?= e(number_format((int) $res['correctos'])) ?> de <?= e(number_format((int) $res['evaluables'])) ?> personas pagadas correctamente ·
                            <?= e(number_format((int) $res['con_diferencia'])) ?> con diferencia · <?= e(number_format((int) $res['pendientes_identidad'])) ?> pendientes de identificar</p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($q['desactualizada']): ?>
                    <div class="notice notice--warning notice--compact" role="status"><i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i><div class="notice__body">Hay archivos cargados o leídos después de este cotejo: vuelva a cotejar para incluirlos.</div></div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn-action btn-view" href="/conciliaciones/<?= e($c['id']) ?>"><span class="btn-action__label">Ver resultados</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a>
                    <a class="btn-action btn-view" href="/conciliaciones/<?= e($c['id']) ?>/empleados"><span class="btn-action__label">Ver personas</span><span class="btn-action__icon"><i class="bi bi-people" aria-hidden="true"></i></span></a>
                </div>
            <?php endif; ?>
            <?php if ($q['pendientes'] > 0 && $this->can('asociaciones.ver')): ?>
                <div class="notice notice--warning notice--compact mt-3" role="status"><i class="bi bi-person-exclamation notice__icon" aria-hidden="true"></i><div class="notice__body">
                    <?= e(number_format($q['pendientes'])) ?> <?= $q['pendientes'] === 1 ? 'pago de nómina no tiene' : 'pagos de nómina no tienen' ?> número de empleado.
                    <a href="/periodos/<?= e($pid) ?>/pendientes">Identificarlos</a></div></div>
            <?php endif; ?>
            <?php if ($c === null && $q['archivos'] === 0): ?>
                <div class="empty-state empty-state--sm"><p class="mb-0">Cargue la nómina de RH y los pagos de Tesorería para poder cotejar.</p></div>
            <?php endif; ?>

            <?php if ($this->can('conciliaciones.ejecutar') && $q['lot'] !== null): ?>
                <?php if ($c !== null && $c['estado'] === 'CERRADA'): ?>
                    <p class="form-text mt-3 mb-0">El cotejo está cerrado y ya no admite cambios.</p>
                <?php elseif ($q['listo']): ?>
                    <form method="post" action="/periodos/<?= e($pid) ?>/cotejar" class="mt-3" data-busy
                        <?php if ($c !== null): ?> data-confirm="Se hará un cotejo nuevo con los archivos actuales; el anterior se conserva para consulta." data-confirm-title="Volver a cotejar" data-confirm-reason="motivo"<?php endif; ?>>
                        <?= $this->csrf() ?>
                        <?php if ($c !== null): ?><input type="hidden" name="motivo" value=""><?php endif; ?>
                        <button class="btn-action btn-reconcile" type="submit"><span class="btn-action__label"><?= $c === null ? 'Cotejar quincena' : 'Volver a cotejar' ?></span><span class="btn-action__icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span></button>
                    </form>
                <?php elseif ($q['faltan'] !== []): ?>
                    <p class="form-text mt-3 mb-0">Para cotejar falta: <?= e(implode(', ', $q['faltan'])) ?>.</p>
                <?php elseif ($q['en_proceso'] > 0): ?>
                    <p class="form-text mt-3 mb-0">Podrá cotejar cuando el sistema termine de leer los archivos.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="row g-3">
    <?php if ($lots !== []): ?>
        <div class="col-xl-8">
            <div class="table-card">
                <div class="table-card__toolbar">
                    <h2 class="table-card__title">Lotes de carga</h2>
                    <span class="table-card__hint">Sólo administración: la pantalla trabaja con el lote <?= $q['lot'] !== null ? e(sprintf('%03d', (int) $q['lot']['numero'])) : '—' ?>.</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <caption class="visually-hidden">Lotes de la quincena (el detalle de cada uno está en «Ver»)</caption>
                        <thead><tr><th scope="col" class="num">#</th><th scope="col">Lote</th><th scope="col">Estado</th><th scope="col" class="num">Archivos</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($lots as $l): ?>
                            <tr>
                                <td class="num"><?= e(sprintf('%03d', $l['numero'])) ?></td>
                                <td class="fw-600"><?= e($l['nombre']) ?><?php if ($l['es_oficial']): ?> <?= status_badge('OFICIAL') ?><?php endif; ?></td>
                                <td><?= status_badge((string) $l['estado']) ?></td>
                                <td class="num"><?= e($l['archivos']) ?></td>
                                <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/lotes/<?= e($l['id']) ?>" aria-label="Ver lote <?= e(sprintf('%03d', $l['numero'])) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($open && $this->can('lotes.crear')): ?>
                    <form method="post" action="/periodos/<?= e($pid) ?>/lotes" class="table-card__footer" data-busy
                          data-confirm="Se creará otro lote en esta quincena. La pantalla de la quincena seguirá usando el lote oficial o, si no hay, el abierto más reciente." data-confirm-title="Crear otro lote">
                        <?= $this->csrf() ?>
                        <label class="visually-hidden" for="lote-nombre">Nombre del lote</label>
                        <input class="form-control form-control-sm w-auto" id="lote-nombre" name="nombre" maxlength="120" placeholder="Carga <?= e(sprintf('%03d', (int) $p['ultimo_numero_lote'] + 1)) ?>">
                        <button class="btn-action btn-add btn-action--sm" type="submit"><span class="btn-action__label">Crear otro lote</span><span class="btn-action__icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="<?= $lots !== [] ? 'col-xl-4' : 'col-xl-6' ?> d-grid gap-3 align-content-start">
        <div class="card">
            <div class="card-header card-header--accent">Datos de la quincena</div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Periodo</dt><dd><?= e($meses[(int) $p['mes']] ?? '') ?> <?= e($p['anio']) ?>, quincena <?= e($p['quincena']) ?> (<?= e(mb_strtolower((string) $p['tipo'])) ?>)</dd>
                    <dt>Creada</dt><dd><?= e(fmt_datetime($p['created_at'])) ?> por <?= e($p['creado_por_username']) ?></dd>
                    <?php if ($p['cerrado_at']): ?><dt>Cerrada</dt><dd><?= e(fmt_datetime($p['cerrado_at'])) ?></dd><?php endif; ?>
                </dl>
                <?php if ($open && $this->can('periodos.editar')): ?>
                    <form method="post" action="/periodos/<?= e($pid) ?>" class="form-section d-grid gap-2" data-busy>
                        <?= $this->csrf() ?>
                        <label class="form-label mb-0" for="p-desc">Descripción</label>
                        <input class="form-control" id="p-desc" name="descripcion" maxlength="150" value="<?= e($p['descripcion']) ?>">
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label mb-0" for="p-ini">Inicio</label><input class="form-control" type="date" id="p-ini" name="fecha_inicio" value="<?= e($p['fecha_inicio']) ?>"></div>
                            <div class="col-6"><label class="form-label mb-0" for="p-fin">Fin</label><input class="form-control" type="date" id="p-fin" name="fecha_fin" value="<?= e($p['fecha_fin']) ?>"></div>
                        </div>
                        <div><button class="btn-action btn-edit btn-action--sm" type="submit"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php $canClose = $open && $this->can('periodos.cerrar');
        $canReopen = $p['estado'] === 'CERRADO' && $this->can('periodos.reabrir');
        $canAnnul = $p['estado'] !== 'ANULADO' && $this->can('periodos.anular'); ?>
        <?php if ($canClose || $canReopen || $canAnnul): ?>
            <div class="card">
                <div class="card-header card-header--accent">Acciones</div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php if ($canClose): ?>
                        <form method="post" action="/periodos/<?= e($pid) ?>/cerrar" data-confirm="La quincena dejará de recibir archivos. Reabrirla queda registrado y requiere un motivo." data-confirm-title="Cerrar quincena">
                            <?= $this->csrf() ?>
                            <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Cerrar quincena</span><span class="btn-action__icon"><i class="bi bi-lock" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canReopen): ?>
                        <form method="post" action="/periodos/<?= e($pid) ?>/reabrir" data-confirm="Acción excepcional: la quincena volverá a admitir cargas. Quedará auditada con su motivo." data-confirm-title="Reabrir quincena" data-confirm-reason="motivo">
                            <?= $this->csrf() ?>
                            <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Reabrir</span><span class="btn-action__icon"><i class="bi bi-unlock" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canAnnul): ?>
                        <form method="post" action="/periodos/<?= e($pid) ?>/anular" data-confirm="Sólo es posible si no tiene archivos vigentes." data-confirm-title="Anular quincena" data-confirm-variant="danger" data-confirm-reason="motivo">
                            <?= $this->csrf() ?>
                            <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Anular</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
