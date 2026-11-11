<?php
/**
 * Sección "Análisis del archivo" (FASE 3). Sólo estructura, controles y decisiones: NUNCA filas de datos
 * (sin vista previa de personas). Hojas, encabezados, columnas y comparación exigen analisis.ver_diagnostico.
 * @var \ConciliaRH\Core\Template $this
 */
$f = $file;
$an = $analysis;
$diag = $this->can('analisis.ver_diagnostico');
$state = (string) $f['estado'];
$analyzable = in_array($state, \ConciliaRH\Domain\Analysis\AnalysisService::ANALYZABLE, true);
$running = in_array((string) ($f['proceso_estado'] ?? ''), ['PENDIENTE', 'EN_EJECUCION'], true);
$confLabels = ['ALTA' => 'Confianza alta', 'MEDIA' => 'Confianza media', 'BAJA' => 'Confianza baja', 'MANUAL' => 'Elegido manualmente', 'NINGUNA' => 'Sin perfil'];
$sev = ['REVISION' => 'AVISO_REVISION', 'ADVERTENCIA' => 'AVISO_ADVERTENCIA', 'INFO' => 'AVISO_INFO'];
?>
<section class="mt-4" id="analisis" aria-labelledby="analisis-titulo">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h2 class="h5 mb-0" id="analisis-titulo">Análisis del archivo</h2>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($diag): ?>
                <a class="btn-action btn-view btn-action--sm" href="/importaciones/<?= e($f['id']) ?>/comparar"><span class="btn-action__label">Comparar con otro archivo</span><span class="btn-action__icon"><i class="bi bi-layout-split" aria-hidden="true"></i></span></a>
            <?php endif; ?>
            <?php if ($analyzable && !$running && $this->can('analisis.reprocesar')): ?>
                <form method="post" action="/importaciones/<?= e($f['id']) ?>/analisis/reprocesar" data-confirm="Se ejecutará de nuevo el análisis del original (sin modificarlo). El resultado anterior queda en el historial." data-confirm-title="Reprocesar análisis">
                    <?= $this->csrf() ?>
                    <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Reprocesar análisis</span><span class="btn-action__icon"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($an === null): ?>
        <div class="notice notice--info" role="status">
            <i class="bi bi-hourglass-split notice__icon" aria-hidden="true"></i>
            <div class="notice__body"><p class="notice__title">Sin análisis todavía</p>
                <?= $state === 'ANALIZANDO' || $running ? 'El análisis está en curso; esta página se actualizará al terminar.' : 'El análisis se ejecuta automáticamente al terminar la verificación de seguridad.' ?></div>
        </div>
    <?php else: ?>
        <?php if ($an['resultado'] === 'REVISION_MANUAL'): ?>
            <div class="notice notice--warning" role="alert">
                <i class="bi bi-person-check notice__icon" aria-hidden="true"></i>
                <div class="notice__body"><p class="notice__title">Requiere revisión manual</p>
                    El análisis no pudo decidir con seguridad (hoja o perfil ambiguos, o perfil desconocido). Un usuario autorizado debe elegir la hoja o el perfil; la decisión queda auditada y se vuelve a analizar el original.</div>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header card-header--accent">Resultado (análisis #<?= e($an['numero']) ?>)</div>
                    <div class="card-body">
                        <dl class="dl-grid">
                            <dt>Resultado</dt><dd><?= status_badge((string) $an['resultado']) ?></dd>
                            <dt>Perfil</dt><dd><?= $an['perfil_codigo'] ? e($an['perfil_nombre']) . ' <code class="small">' . e($an['perfil_codigo']) . '</code>' : '<span class="text-muted">No determinado</span>' ?>
                                <br><?= status_badge('CONF_' . $an['perfil_confianza'], $confLabels[$an['perfil_confianza']] ?? null) ?></dd>
                            <dt>Hoja principal</dt><dd><?= $an['hoja_indice'] !== null ? 'Índice ' . e($an['hoja_indice']) : '<span class="text-muted">No seleccionada</span>' ?>
                                <?= $an['hoja_origen'] === 'MANUAL' ? ' ' . status_badge('CONF_MANUAL', 'Manual') : '' ?></dd>
                            <dt>Registros de detalle</dt><dd><?= e(number_format((int) $an['filas_detalle'])) ?></dd>
                            <dt>Huella lógica</dt><dd><?= $an['huella_contenido'] ? '<code class="small text-break" title="Se muestran los primeros 16 caracteres">' . e(substr((string) $an['huella_contenido'], 0, 16) . '…') . '</code> <small class="text-muted">v' . e($an['huella_version']) . '</small>' : '<span class="text-muted">Sin huella (' . e($an['resultado'] === 'REVISION_MANUAL' ? 'pendiente de revisión' : 'sin perfil') . ')</span>' ?></dd>
                            <dt>Relación</dt><dd><?= status_badge((string) $f['clasificacion_duplicidad']) ?></dd>
                            <dt>Motor</dt><dd><small class="text-muted">v<?= e($an['motor_version']) ?> · reglas v<?= e($an['reglas_version']) ?> · <?= e(fmt_datetime($an['created_at'], true)) ?></small></dd>
                        </dl>
                        <?php if ($related !== []): ?>
                            <h3 class="h6 mt-3">Archivos relacionados</h3>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($related as $r): ?>
                                    <li class="mb-1"><?= status_badge((string) $r['clasificacion_duplicidad']) ?>
                                        <a href="/importaciones/<?= e($r['id']) ?>">#<?= e($r['id']) ?> · <?= e($r['nombre_original']) ?></a>
                                        <span class="text-muted">(Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?>)</span>
                                        <?php if ($diag): ?> · <a href="/importaciones/<?= e($f['id']) ?>/comparar?con=<?= e($r['id']) ?>">comparar</a><?php endif; ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="small text-muted mt-2 mb-0">Una relación es sólo una sugerencia: ningún archivo reemplaza a otro automáticamente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header card-header--accent">Advertencias</div>
                    <div class="card-body">
                        <?php $warns = $an['advertencias'] ?? []; ?>
                        <?php if ($warns === []): ?>
                            <div class="empty-state empty-state--sm"><p class="empty-state__title">Sin advertencias</p></div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0 d-grid gap-2">
                                <?php foreach ($warns as $w): ?>
                                    <li><?= status_badge($sev[$w['severidad']] ?? 'AVISO_INFO', (string) $w['codigo']) ?>
                                        <span class="small"><?= e($w['mensaje']) ?><?= isset($w['hoja']) && $w['hoja'] !== null ? ' <span class="text-muted">(hoja ' . e($w['hoja']) . ')</span>' : '' ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-card mt-3">
            <div class="table-card__toolbar"><h3 class="table-card__title">Controles del archivo</h3></div>
            <?php if ($controls === []): ?>
                <div class="empty-state empty-state--sm"><p class="empty-state__title">No se calcularon controles</p><p class="mb-0">El archivo no tiene perfil o está en revisión manual.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle table-stack">
                        <caption class="visually-hidden">Controles del archivo: declarado frente a calculado</caption>
                        <thead><tr><th scope="col">Control</th><th scope="col" class="col-hide-sm">Hoja</th><th scope="col" class="num">Declarado</th><th scope="col" class="num">Calculado</th><th scope="col" class="num col-hide-sm">Diferencia</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($controls as $c): $int = $c['tipo_valor'] === 'ENTERO'; ?>
                            <tr>
                                <td><span class="fw-600"><?= e($c['descripcion']) ?></span><br><code class="small"><?= e($c['codigo']) ?></code><?= $c['mensaje'] ? '<br><small class="text-muted">' . e($c['mensaje']) . '</small>' : '' ?></td>
                                <td class="col-hide-sm small"><?= $c['hoja_nombre'] !== null ? e($c['hoja_nombre']) : '—' ?></td>
                                <td class="num"><?= e(fmt_decimal($c['valor_declarado'], $int ? 0 : 2)) ?></td>
                                <td class="num"><?= e(fmt_decimal($c['valor_calculado'], $int ? 0 : 2)) ?></td>
                                <td class="num col-hide-sm"><?= e(fmt_decimal($c['diferencia'], $int ? 0 : 2)) ?></td>
                                <td><?= status_badge('CTL_' . $c['estado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($diag): ?>
            <div class="table-card mt-3">
                <div class="table-card__toolbar"><h3 class="table-card__title">Hojas del libro (<?= e(count($sheets)) ?>)</h3></div>
                <div class="table-responsive">
                    <table class="table align-middle table-sticky-first">
                        <caption class="visually-hidden">Hojas inspeccionadas</caption>
                        <thead><tr><th scope="col">#</th><th scope="col">Hoja</th><th scope="col" class="num">Filas</th><th scope="col" class="num col-hide-sm">Detalle / TOTAL</th><th scope="col" class="col-hide-md">Encabezado</th><th scope="col" class="col-hide-md">Señales</th><th scope="col" class="num">Puntaje</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($sheets as $s): ?>
                            <tr<?= $s['seleccionada'] ? ' class="table-row--selected"' : '' ?>>
                                <td><?= e($s['indice']) ?></td>
                                <td class="text-break"><span class="fw-600"><?= e($s['nombre_original']) ?></span>
                                    <?= $s['visibilidad'] !== 'VISIBLE' ? ' ' . status_badge($s['visibilidad'] === 'OCULTA' ? 'OCULTO' : 'MUY_OCULTO') : '' ?>
                                    <br><small class="text-muted"><?= e($s['motivo_seleccion'] ?? '') ?></small>
                                    <?= $s['perfil_codigo'] ? '<br><code class="small">' . e($s['perfil_codigo']) . '</code>' : '' ?></td>
                                <td class="num"><?= e(number_format((int) $s['filas_detectadas'])) ?><br><small class="text-muted"><?= e($s['columnas_detectadas']) ?> col.</small></td>
                                <td class="num col-hide-sm"><?= e(number_format((int) $s['filas_detalle'])) ?> / <?= e($s['filas_total']) ?></td>
                                <td class="col-hide-md small"><?= $s['encabezado_fila'] !== null ? 'Fila ' . e($s['encabezado_fila']) . ((int) $s['encabezado_filas'] > 1 ? ' (' . e($s['encabezado_filas']) . ' niveles)' : '') : '—' ?><br><span class="text-muted"><?= e($s['rango_usado'] ?? '') ?></span></td>
                                <td class="col-hide-md small">
                                    <?= $s['tiene_filtros'] ? status_badge('FILTRO_ACTIVO') . ' ' : '' ?>
                                    <?= $s['tiene_filas_ocultas'] ? status_badge('FILAS_OCULTAS', $s['filas_ocultas'] . ' filas ocultas') . ' ' : '' ?>
                                    <?= $s['tiene_columnas_ocultas'] ? status_badge('COLUMNAS_OCULTAS') . ' ' : '' ?>
                                    <?= $s['tiene_formulas'] === null ? status_badge('FORMULAS_ND') : ($s['tiene_formulas'] ? status_badge('FORMULAS', $s['cantidad_formulas'] . ' fórmulas') : '') ?>
                                    <?= (int) ($s['formulas_sin_cache'] ?? 0) > 0 ? ' ' . status_badge('FORMULAS_SIN_CACHE', $s['formulas_sin_cache'] . ' sin caché') : '' ?></td>
                                <td class="num" title="<?= e(json_encode($s['metadata']['puntaje_detalle'] ?? [], JSON_UNESCAPED_UNICODE)) ?>"><?= e($s['puntaje_candidata']) ?></td>
                                <td><?= status_badge('HOJA_' . $s['estado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($columns !== []): ?>
                <div class="table-card mt-3">
                    <div class="table-card__toolbar"><h3 class="table-card__title">Columnas de la hoja principal</h3>
                        <span class="small text-muted">Sólo metadatos; no se muestran valores.</span></div>
                    <div class="table-responsive">
                        <table class="table align-middle table-sticky-first">
                            <caption class="visually-hidden">Columnas detectadas de la hoja principal</caption>
                            <thead><tr><th scope="col">Col.</th><th scope="col">Encabezado original</th><th scope="col" class="col-hide-md">Normalizado</th><th scope="col">Campo</th><th scope="col">Tipo</th><th scope="col" class="num col-hide-sm">% vacío</th><th scope="col" class="col-hide-sm">Identificador</th></tr></thead>
                            <tbody>
                            <?php foreach ($columns as $c): ?>
                                <tr>
                                    <td><?= e($c['letra']) ?></td>
                                    <td class="text-break"><?= e($c['encabezado_original'] ?? '—') ?><?= $c['es_auxiliar'] ? ' ' . status_badge('COLUMNA_AUXILIAR') : '' ?></td>
                                    <td class="col-hide-md"><code class="small"><?= e($c['encabezado_normalizado'] ?? '') ?></code></td>
                                    <td><?= $c['campo_canonico'] ? '<code class="small">' . e($c['campo_canonico']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= e($c['tipo_inferido']) ?></td>
                                    <td class="num col-hide-sm"><?= e(fmt_decimal($c['pct_nulos'], 1)) ?></td>
                                    <td class="col-hide-sm"><?= e($c['identificador_probable'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php $canSheet = $analyzable && !$running && $this->can('analisis.seleccionar_hoja') && $diag;
        $canProfile = $analyzable && !$running && $this->can('analisis.seleccionar_perfil'); ?>
        <?php if ($canSheet || $canProfile): ?>
            <div class="card mt-3">
                <div class="card-header card-header--accent">Decisión manual</div>
                <div class="card-body row g-3">
                    <?php if ($canSheet && $sheets !== []): ?>
                        <form class="col-md-6 d-grid gap-2" method="post" action="/importaciones/<?= e($f['id']) ?>/analisis/hoja" data-confirm="Se fijará la hoja principal, se invalidará la huella y se volverá a analizar el original. La decisión queda auditada." data-confirm-title="Elegir hoja" data-confirm-reason="motivo">
                            <?= $this->csrf() ?>
                            <label class="form-label mb-0" for="a-hoja">Hoja principal</label>
                            <select class="form-select" id="a-hoja" name="hoja">
                                <option value="auto">Selección automática</option>
                                <?php foreach ($sheets as $s): ?><option value="<?= e($s['indice']) ?>"<?= $s['seleccionada'] ? ' selected' : '' ?>><?= e($s['indice']) ?> · <?= e($s['nombre_original']) ?> (puntaje <?= e($s['puntaje_candidata']) ?>)</option><?php endforeach; ?>
                            </select>
                            <div><button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Elegir hoja</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button></div>
                        </form>
                    <?php endif; ?>
                    <?php if ($canProfile && $profiles !== []): ?>
                        <form class="col-md-6 d-grid gap-2" method="post" action="/importaciones/<?= e($f['id']) ?>/analisis/perfil" data-confirm="Se fijará el perfil de importación, se invalidará la huella y se volverá a analizar el original. La decisión queda auditada." data-confirm-title="Elegir perfil" data-confirm-reason="motivo">
                            <?= $this->csrf() ?>
                            <label class="form-label mb-0" for="a-perfil">Perfil de importación</label>
                            <select class="form-select" id="a-perfil" name="perfil">
                                <option value="auto">Detección automática</option>
                                <?php foreach ($profiles as $p): ?><option value="<?= e($p['id']) ?>"<?= $an['perfil_codigo'] === $p['codigo'] ? ' selected' : '' ?>><?= e($p['nombre']) ?> (<?= e($p['codigo']) ?>)</option><?php endforeach; ?>
                            </select>
                            <div><button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Elegir perfil</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($diag && count($history) > 1): ?>
            <details class="mt-3">
                <summary class="small fw-600">Historial de análisis (<?= e(count($history)) ?>)</summary>
                <div class="table-responsive mt-2"><table class="table table-sm align-middle">
                    <caption class="visually-hidden">Historial de análisis del archivo</caption>
                    <thead><tr><th scope="col">#</th><th scope="col">Fecha</th><th scope="col">Resultado</th><th scope="col">Perfil</th><th scope="col">Huella</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr><td><?= e($h['numero']) ?><?= $h['vigente'] ? ' ' . status_badge('VIGENTE') : '' ?></td><td class="small"><?= e(fmt_datetime($h['created_at'], true)) ?></td>
                            <td><?= status_badge((string) $h['resultado']) ?></td><td><code class="small"><?= e($h['perfil_codigo'] ?? '—') ?></code> <small class="text-muted"><?= e($h['perfil_origen']) ?> / <?= e($h['hoja_origen']) ?></small></td>
                            <td><code class="small"><?= e($h['huella_contenido'] ? substr((string) $h['huella_contenido'], 0, 12) . '…' : '—') ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
