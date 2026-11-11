<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$f = $file;
$dupLabels = ['PENDIENTE' => 'Huella pendiente', 'NUEVO' => 'Nuevo', 'DUPLICADO_BINARIO' => 'Duplicado exacto', 'DUPLICADO_CONTENIDO' => 'Mismo contenido', 'POSIBLE_VERSION' => 'Posible versión'];
$preAnalysis = in_array($f['estado'], ['RECIBIDO', 'VERIFICADO', 'RECHAZADO', 'ERROR', 'ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS', 'REVISION_MANUAL'], true);
$cont = is_string($f['contenedor']) ? json_decode($f['contenedor'], true) : null;
?>
<div class="page-header">
    <div>
        <h1 class="text-break"><?= e($f['nombre_original']) ?></h1>
        <p class="page-header__sub d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge((string) $f['estado']) ?>
            <?= status_badge((string) $f['clasificacion_duplicidad'], $dupLabels[$f['clasificacion_duplicidad']] ?? null) ?>
            <?php if ($f['tiene_macros']): ?><?= status_badge('MACROS') ?><?php endif; ?>
            <span class="text-muted">· <a href="/lotes/<?= e($f['lote_id']) ?>">Lote <?= e(sprintf('%03d', $f['lote_numero'])) ?> · <?= e($f['lote_nombre']) ?></a> · <?= e($f['periodo_descripcion']) ?></span>
        </p>
    </div>
    <div class="page-header__actions">
        <?php if ($this->can('archivos.descargar_original') && $f['estado'] !== 'RECHAZADO' && !$f['tiene_macros']): ?>
            <form method="post" action="/importaciones/<?= e($f['id']) ?>/descargar">
                <?= $this->csrf() ?>
                <button class="btn-action btn-export" type="submit"><span class="btn-action__label">Descargar original</span><span class="btn-action__icon"><i class="bi bi-download" aria-hidden="true"></i></span></button>
            </form>
        <?php endif; ?>
        <a class="btn-action btn-cancel" href="/lotes/<?= e($f['lote_id']) ?>"><span class="btn-action__label">Lote</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<ol class="pipeline" aria-label="Avance del archivo">
    <?php foreach ($pipeline as $st): ?>
        <?php $lectura = ['done' => 'completado', 'current' => 'en curso', 'warning' => 'espera una decisión', 'error' => 'con problema', 'stopped' => 'detenido', 'pending' => 'pendiente']; ?>
        <li class="pipeline__step pipeline__step--<?= e(isset($lectura[$st['estado']]) ? $st['estado'] : 'pending') ?>"<?= in_array($st['estado'], ['current', 'warning'], true) ? ' aria-current="step"' : '' ?>>
            <span class="pipeline__label"><?= e($st['titulo']) ?><span class="visually-hidden">: <?= e($lectura[$st['estado']] ?? 'pendiente') ?></span></span>
            <span class="pipeline__state"><?= e($st['detalle']) ?></span>
        </li>
    <?php endforeach; ?>
</ol>

<?php if (in_array($f['estado'], ['RECHAZADO', 'ERROR'], true)): ?>
    <div class="notice notice--error" role="alert">
        <i class="bi bi-shield-x notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title"><?= $f['estado'] === 'RECHAZADO' ? 'Archivo rechazado por la verificación de seguridad' : 'Error al verificar' ?> <code><?= e($f['error_codigo'] ?? '') ?></code></p><?= e($f['error_resumen'] ?? '') ?></div>
    </div>
<?php endif; ?>
<?php if ($f['tiene_macros'] && $f['estado'] !== 'RECHAZADO' && $this->can('archivos.descargar_original')): ?>
    <div class="notice notice--warning" role="status">
        <i class="bi bi-exclamation-triangle notice__icon" aria-hidden="true"></i>
        <div class="notice__body">
            <p class="notice__title">El libro contiene macros</p>
            ConciliaRH nunca ejecuta macros, pero Excel podría hacerlo al abrir el archivo. Descárguelo sólo si lo necesita y ábralo con las macros deshabilitadas.
            <form method="post" action="/importaciones/<?= e($f['id']) ?>/descargar" class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <?= $this->csrf() ?>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="confirmar_macros" value="1" id="confirmar-macros" required>
                    <label class="form-check-label" for="confirmar-macros">Entiendo el riesgo y confirmo la descarga</label>
                </div>
                <button class="btn-action btn-export btn-action--sm" type="submit"><span class="btn-action__label">Descargar original</span><span class="btn-action__icon"><i class="bi bi-download" aria-hidden="true"></i></span></button>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php if ($f['clasificacion_duplicidad'] === 'DUPLICADO_BINARIO' && $f['archivo_relacionado_id']): ?>
    <div class="notice notice--warning" role="status">
        <i class="bi bi-files notice__icon" aria-hidden="true"></i>
        <div class="notice__body"><p class="notice__title">Duplicado exacto</p>Idéntico byte a byte al <a href="/importaciones/<?= e($f['archivo_relacionado_id']) ?>">archivo #<?= e($f['archivo_relacionado_id']) ?></a> (<?= e($f['relacionado_nombre']) ?>). Se cargó con confirmación explícita.</div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header card-header--accent">Identidad del archivo</div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>SHA-256 (binario)</dt><dd><code class="small text-break"><?= e($f['sha256']) ?></code></dd>
                    <dt>Huella de contenido</dt><dd><?= $f['huella_contenido'] ? '<code class="small text-break">' . e($f['huella_contenido']) . '</code> <small class="text-muted">v' . e($f['huella_version']) . '</small>' : '<span class="text-muted">Pendiente: se calcula al analizar el libro</span>' ?></dd>
                    <dt>Tamaño</dt><dd><?= e(fmt_bytes($f['tamano_bytes'])) ?> (<?= e(number_format((float) $f['tamano_bytes'])) ?> bytes)</dd>
                    <dt>Formato</dt><dd>.<?= e($f['extension']) ?> · firma <?= e($f['firma']) ?> · <code class="small"><?= e($f['mime_detectado']) ?></code></dd>
                    <?php if (is_array($cont) && isset($cont['entradas'])): ?><dt>Contenedor</dt><dd><?= e($cont['entradas']) ?> partes · <?= e(fmt_bytes($cont['descomprimido_declarado'] ?? 0)) ?> descomprimido</dd><?php endif; ?>
                    <dt>Cargado</dt><dd><?= e(fmt_datetime($f['created_at'], true)) ?> · <?= e($f['usuario_carga']) ?></dd>
                    <?php if ($f['verificado_at']): ?><dt>Verificado</dt><dd><?= e(fmt_datetime($f['verificado_at'], true)) ?></dd><?php endif; ?>
                    <dt>Observaciones</dt><dd><?= e($f['observaciones'] ?? '—') ?></dd>
                    <?php if ($f['estado'] === 'ANULADO'): ?><dt>Anulado</dt><dd><?= e(fmt_datetime($f['anulado_at'])) ?> · <?= e($f['motivo_anulacion']) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5 d-grid gap-3 align-content-start">
        <?php if ($f['proceso_id'] !== null):
            $jEstado = (string) ($job['estado'] ?? $f['proceso_estado']);
            $jEtapa = $job['etapa'] ?? $f['proceso_etapa'];
            $jProgreso = $job['progreso'] ?? $f['proceso_progreso']; ?>
            <div class="card" data-job-poll="<?= e($f['proceso_id']) ?>" data-job-poll-url="/api/importaciones/<?= e($f['id']) ?>" data-job-state="<?= e($jEstado) ?>">
                <div class="card-header card-header--accent">Procesamiento (verificación y análisis)<?php if ($job !== null): ?> (proceso <a href="/procesos/<?= e($job['id']) ?>">#<?= e($job['id']) ?></a>)<?php endif; ?></div>
                <div class="card-body">
                    <p class="mb-2"><?= status_badge($jEstado) ?> <span class="small text-muted ms-2" data-job-state-text aria-live="polite"><?= e($jEtapa ?? '') ?></span></p>
                    <div class="progress" role="progressbar" aria-label="Progreso del procesamiento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($jProgreso) ?>">
                        <div class="progress-bar js-progress-bar" data-progress="<?= e($jProgreso) ?>"></div>
                    </div>
                    <?php if ($job !== null): ?><p class="small text-muted mt-2 mb-0"><?= e($job['mensaje'] ?? '') ?></p><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header card-header--accent">Clasificación</div>
            <div class="card-body">
                <dl class="dl-grid mb-0">
                    <dt>Tipo documental</dt><dd><?= e($f['tipo_nombre']) ?></dd>
                    <dt>Origen</dt><dd><?= e($f['origen_nombre']) ?></dd>
                    <dt>Perfil</dt><dd><?= e($f['perfil_codigo'] ?? 'Se detecta al analizar el libro') ?></dd>
                </dl>
                <?php if ($preAnalysis && $this->can('archivos.clasificar')): ?>
                    <form method="post" action="/importaciones/<?= e($f['id']) ?>/clasificar" class="form-section d-grid gap-2" data-confirm="Se cambiará la clasificación del archivo. El cambio queda auditado." data-confirm-title="Cambiar clasificación">
                        <?= $this->csrf() ?>
                        <label class="form-label mb-0" for="c-tipo">Tipo documental</label>
                        <select class="form-select" id="c-tipo" name="tipo_documento_id"><?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>"<?= (int) $t['id'] === (int) $f['tipo_documento_id'] ? ' selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?></select>
                        <label class="form-label mb-0" for="c-origen">Origen</label>
                        <select class="form-select" id="c-origen" name="origen_administrativo_id"><?php foreach ($origins as $o): ?><option value="<?= e($o['id']) ?>"<?= (int) $o['id'] === (int) $f['origen_administrativo_id'] ? ' selected' : '' ?>><?= e($o['nombre']) ?></option><?php endforeach; ?></select>
                        <div><button class="btn-action btn-edit btn-action--sm" type="submit"><span class="btn-action__label">Guardar clasificación</span><span class="btn-action__icon"><i class="bi bi-tag" aria-hidden="true"></i></span></button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php $canRe = $f['estado'] === 'ERROR' && $this->can('archivos.reprocesar');
        $canAn = !in_array($f['estado'], ['ANULADO', 'ANALIZANDO'], true) && $this->can('archivos.anular'); ?>
        <?php if ($canRe || $canAn): ?>
            <div class="card">
                <div class="card-header card-header--accent">Acciones</div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php if ($canRe): ?>
                        <form method="post" action="/importaciones/<?= e($f['id']) ?>/reprocesar" data-confirm="Se encolará de nuevo la verificación del archivo original." data-confirm-title="Reprocesar">
                            <?= $this->csrf() ?>
                            <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Reprocesar</span><span class="btn-action__icon"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canAn): ?>
                        <form method="post" action="/importaciones/<?= e($f['id']) ?>/anular" data-confirm="El archivo no se usará en ninguna conciliación. El original se conserva para auditoría." data-confirm-title="Anular archivo" data-confirm-variant="danger" data-confirm-reason="motivo">
                            <?= $this->csrf() ?>
                            <button class="btn-action btn-delete btn-action--sm" type="submit"><span class="btn-action__label">Anular</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($this->can('analisis.ver')): ?>
<?= $this->insert('imports/_analysis', get_defined_vars()) ?>
<?php endif; ?>
<?php if ($this->can('asociaciones.ver')): ?>
<?= $this->insert('imports/_normalization', get_defined_vars()) ?>
<?php endif; ?>
