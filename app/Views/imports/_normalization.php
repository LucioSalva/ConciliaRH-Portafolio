<?php
/**
 * Sección "Registros normalizados" (FASE 4): resultado de NORMALIZAR_REGISTROS con conteos agregados.
 * Sin nombres, RFC, CURP ni cuentas; el detalle por registro está en /asociaciones (con su permiso).
 * @var \ConciliaRH\Core\Template $this
 */
$f = $file;
$n = $normalization;
$m = $n['metricas'] ?? [];
$st = $m['por_estado'] ?? [];
$running = $normJob !== null && in_array($normJob['estado'], ['PENDIENTE', 'EN_EJECUCION'], true);
$importable = in_array((string) $f['estado'], ['ANALIZADO', 'ANALIZADO_CON_ADVERTENCIAS'], true);
$pend = ($st['SUGERENCIA'] ?? 0) + ($st['AMBIGUO'] ?? 0) + ($st['CONFLICTO'] ?? 0) + ($st['NO_IDENTIFICADO'] ?? 0);
$tipoLabels = ['RRHH' => 'Registros RRHH', 'TESORERIA' => 'Registros de Tesorería', 'POLIZA_GLOBAL' => 'Líneas de póliza GLOBAL', 'POLIZA_EGRESO' => 'Líneas de póliza de egreso'];
$motivos = ['VISTA_DERIVADA' => 'vista derivada (filtro o filas ocultas)', 'DUPLICADO_CONTENIDO' => 'duplicado de contenido',
            'DUPLICADO_BINARIO' => 'duplicado exacto', 'LOTE_O_PERIODO_ANULADO' => 'lote o periodo anulado', 'SOLO_RRHH_ACTUALIZA_MAESTRO' => 'sólo RRHH alimenta el maestro'];
?>
<section class="mt-4" id="normalizacion" aria-labelledby="normalizacion-titulo">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h2 class="h5 mb-0" id="normalizacion-titulo">Registros normalizados</h2>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($n !== null && $pend > 0): ?>
                <a class="btn-action btn-view btn-action--sm" href="/asociaciones?archivo=<?= e($f['id']) ?>"><span class="btn-action__label">Revisar pendientes (<?= e(number_format($pend)) ?>)</span><span class="btn-action__icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span></a>
            <?php endif; ?>
            <?php if ($importable && !$running && $this->can('importacion.normalizar')): ?>
                <form method="post" action="/importaciones/<?= e($f['id']) ?>/normalizar" data-confirm="Se re-evaluarán sólo los registros pendientes con el maestro actual. Las decisiones manuales no cambian." data-confirm-title="Re-asociar pendientes">
                    <?= $this->csrf() ?><input type="hidden" name="modo" value="reasociar">
                    <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Re-asociar pendientes</span><span class="btn-action__icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span></button>
                </form>
                <form method="post" action="/importaciones/<?= e($f['id']) ?>/normalizar" data-confirm="Se creará una NUEVA importación de este análisis; la anterior queda SUSTITUIDA (se conserva) y las decisiones manuales se trasladan a los registros idénticos." data-confirm-title="Normalizar de nuevo">
                    <?= $this->csrf() ?><input type="hidden" name="modo" value="completa">
                    <button class="btn-action btn-retry btn-action--sm" type="submit"><span class="btn-action__label">Normalizar de nuevo</span><span class="btn-action__icon"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($normJob !== null && $normJob['estado'] === 'ERROR'): ?>
        <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon notice__icon" aria-hidden="true"></i>
            <div class="notice__body"><p class="notice__title">La última normalización falló</p><?= e($normJob['error_mensaje'] ?? 'Error no especificado.') ?></div></div>
    <?php endif; ?>
    <?php if ($n === null): ?>
        <div class="notice notice--info" role="status"><i class="bi bi-hourglass-split notice__icon" aria-hidden="true"></i>
            <div class="notice__body"><p class="notice__title">Sin registros normalizados</p>
                <?= $running ? 'La normalización está en curso.' : ($importable ? 'La normalización se ejecuta automáticamente al terminar el análisis.' : 'Sólo un archivo analizado (sin revisión manual pendiente) se normaliza.') ?></div></div>
    <?php else: ?>
        <?php if ($n['estado'] !== 'VIGENTE'): ?>
            <div class="notice notice--warning" role="status"><i class="bi bi-exclamation-triangle notice__icon" aria-hidden="true"></i>
                <div class="notice__body"><p class="notice__title">Importación <?= e(strtolower((string) $n['estado'])) ?></p>
                    Sus registros se conservan para trazabilidad pero no cuentan. <?= $running ? 'La nueva normalización está en curso.' : '' ?></div></div>
        <?php endif; ?>
        <div class="row g-3 mb-3">
            <?php foreach ([[$tipoLabels[$n['tipo_registro']] ?? 'Registros', (int) $n['registros'], ''],
                            ['Asociados automáticamente', $st['ASOCIADO_AUTOMATICO'] ?? 0, ''],
                            ['Nuevos empleados (RRHH)', $m['altas'] ?? 0, ''],
                            ['Revisión manual', $pend, $pend ? 'kpi--wine' : ''],
                            ['Incidencias abiertas', $n['incidencias'], $n['incidencias'] ? 'kpi--danger' : '']] as [$lab, $val, $cls]):
                if (!in_array($n['tipo_registro'], ['RRHH', 'TESORERIA'], true) && $lab !== ($tipoLabels[$n['tipo_registro']] ?? '')) { continue; } ?>
                <div class="col-6 col-lg"><div class="kpi <?= e($cls) ?>"><div class="kpi__label"><?= e($lab) ?></div><div class="kpi__value"><?= e(number_format((int) $val)) ?></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-header card-header--accent">Importación #<?= e($n['numero']) ?> · <?= status_badge((string) $n['estado']) ?></div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Perfil</dt><dd><code class="small"><?= e($n['perfil']) ?></code><?= $n['grupo'] ? ' · ' . e($n['grupo']) : '' ?></dd>
                    <?php if ($n['tipo_registro'] === 'RRHH'): ?>
                        <dt>Maestro de empleados</dt><dd><?= $n['puede_actualizar_maestro'] ? status_badge('MAESTRO_CONFIABLE', 'Fuente confiable: crea y actualiza empleados')
                            : status_badge('MAESTRO_SOLO_ASOCIA', 'Sólo asocia: ' . ($motivos[$n['motivo_sin_maestro']] ?? (string) $n['motivo_sin_maestro'])) ?></dd>
                        <dt>Maestro actualizado</dt><dd><?= e(number_format((int) ($m['maestro_actualizados'] ?? 0))) ?> empleado(s) con cambios (historial por campo)</dd>
                        <dt>Conceptos</dt><dd><?= e(number_format((int) ($m['conceptos'] ?? 0))) ?> importes en <?= e($m['conceptos_codigos'] ?? 0) ?> códigos
                            <?php $ci = $m['conceptos_tipo_inferido'] ?? []; if (($ci['resultado'] ?? '') === 'CORTE'): ?><small class="text-muted">· tipo inferido por cuadre en <?= e(number_format((int) $ci['filas_cuadran'])) ?> de <?= e(number_format((int) $ci['filas'])) ?> filas</small><?php endif; ?></dd>
                    <?php elseif ($n['tipo_registro'] === 'TESORERIA'): ?>
                        <dt>Banco · categoría</dt><dd><?= e($m['banco'] ?? '—') ?> · <?= e($m['categoria'] ?? '—') ?></dd>
                        <dt>Cuentas</dt><dd><?= e(number_format((int) ($m['cuentas_nuevas'] ?? 0))) ?> nuevas por evidencia fuerte · <?= e(number_format((int) ($m['cuentas_vistas'] ?? 0))) ?> ya conocidas</dd>
                    <?php endif; ?>
                    <?php if (in_array($n['tipo_registro'], ['RRHH', 'TESORERIA'], true)): ?>
                        <dt>Por estado</dt><dd class="d-flex flex-wrap gap-2"><?php foreach ($st as $k => $v): ?><?= status_badge((string) $k, null) ?> <small class="text-muted"><?= e(number_format((int) $v)) ?></small><?php endforeach; ?></dd>
                        <dt>Por método</dt><dd class="small"><?php foreach (($m['por_metodo'] ?? []) as $k => $v): ?><span class="me-3"><code><?= e($k) ?></code> <?= e(number_format((int) $v)) ?></span><?php endforeach; ?></dd>
                        <dt>Calidad</dt><dd class="small">Número inválido: <?= e($m['numero_invalido'] ?? 0) ?> · RFC inválido: <?= e($m['rfc_invalido'] ?? 0) ?><?= $n['tipo_registro'] === 'RRHH' ? ' · CURP inválida: ' . e($m['curp_invalida'] ?? 0) : '' ?></dd>
                    <?php endif; ?>
                    <dt>Versión de reglas</dt><dd>matching v<?= e($n['matching_version']) ?> · <?= e(fmt_datetime($n['created_at'])) ?></dd>
                </dl>
            </div>
        </div>
    <?php endif; ?>
</section>
