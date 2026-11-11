<?php
/**
 * Comparación técnica entre dos archivos (FASE 3): hashes, perfil, hojas, filas, columnas, controles y
 * registros en común (por hash de registro). NO es un diff persona por persona (FASE 4+).
 * @var \ConciliaRH\Core\Template $this
 */
$this->layout('layouts/app', ['title' => $title]);
$nombresControl = ['CANTIDAD_EMPLEADOS' => 'Personas', 'CANTIDAD_EMPLEADOS_DECLARADOS' => 'Personas declaradas', 'CANTIDAD_FILAS_DETALLE' => 'Filas de detalle',
    'CANTIDAD_FILAS_TOTAL' => 'Filas en total', 'TOTAL_DEBE' => 'Total del debe', 'TOTAL_DEDUCCIONES' => 'Total de deducciones', 'TOTAL_HABER' => 'Total del haber',
    'TOTAL_IMPORTE' => 'Total de importes', 'TOTAL_INVALIDO' => 'Importes no válidos', 'TOTAL_NETO' => 'Total neto', 'TOTAL_PERCEPCIONES' => 'Total de percepciones'];
$f = $file;
$side = static fn (?array $s, string $k) => $s[$k] ?? null;
?>
<div class="page-header">
    <div>
        <h1>Comparar archivos</h1>
        <p class="page-header__sub">A: <a href="/importaciones/<?= e($f['id']) ?>">#<?= e($f['id']) ?> · <?= e($f['nombre_original']) ?></a></p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/importaciones/<?= e($f['id']) ?>#analisis"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>

<form class="card mb-3" method="get" action="/importaciones/<?= e($f['id']) ?>/comparar" aria-label="Elegir el archivo B">
    <div class="card-body filter-bar">
        <div>
            <label class="form-label" for="c-con">Archivo B (número)</label>
            <input class="form-control form-control-sm" autocomplete="off" id="c-con" name="con" inputmode="numeric" pattern="[0-9]{1,18}" value="<?= e($other) ?>" required>
        </div>
        <?php if ($related !== []): ?>
            <div class="small text-muted">Relacionados: <?php foreach ($related as $r): ?><a class="me-2" href="?con=<?= e($r['id']) ?>">#<?= e($r['id']) ?></a><?php endforeach; ?></div>
        <?php endif; ?>
        <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Comparar</span><span class="btn-action__icon"><i class="bi bi-layout-split" aria-hidden="true"></i></span></button>
    </div>
</form>

<?php if ($error): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($error) ?></div></div>
<?php endif; ?>

<?php if ($data !== null): $A = $data['a']; $B = $data['b']; ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="kpi <?= $data['mismo_sha'] ? 'kpi--success' : '' ?>"><div class="kpi__label">Binario (SHA-256)</div><div class="kpi__value"><?= $data['mismo_sha'] ? 'Idéntico' : 'Distinto' ?></div></div></div>
        <div class="col-md-4"><div class="kpi <?= $data['misma_huella'] ? 'kpi--success' : 'kpi--wine' ?>"><div class="kpi__label">Huella lógica</div><div class="kpi__value"><?= $data['misma_huella'] ? 'Misma' : 'Distinta' ?></div><div class="kpi__hint"><?= $data['misma_huella'] ? 'Mismo detalle relevante' : 'O sin huella en alguno' ?></div></div></div>
        <div class="col-md-4"><div class="kpi"><div class="kpi__label">Registros en común</div>
            <?php if (!empty($data['registros']['no_comparable'])): ?>
            <div class="kpi__value">—</div>
            <div class="kpi__hint">No comparables: los análisis usan llaves HMAC distintas (hubo una rotación). Reanalice el archivo anterior para comparar registros.</div></div></div>
            <?php else: ?>
            <div class="kpi__value"><?= $data['registros'] ? e(number_format((int) $data['registros']['comunes'])) : '—' ?></div>
            <div class="kpi__hint"><?= $data['registros'] ? 'A: ' . e($data['registros']['total_a']) . ' · B: ' . e($data['registros']['total_b']) . ' idénticos · ' . e($data['registros']['claves_comunes']) . ' con la misma clave (empleado/cuenta)' : 'Requiere análisis de ambos' ?></div></div></div>
            <?php endif; ?>
    </div>

    <div class="card mb-3">
        <div class="card-header card-header--accent">Estructura</div>
        <div class="card-body">
            <div class="compare-grid">
                <div></div><div class="compare-grid__head">A · #<?= e($A['archivo']['id']) ?></div><div class="compare-grid__head">B · <a href="/importaciones/<?= e($B['archivo']['id']) ?>">#<?= e($B['archivo']['id']) ?></a></div>
                <div class="compare-grid__label">Archivo</div><div class="text-break"><?= e($A['archivo']['nombre_original']) ?></div><div class="text-break"><?= e($B['archivo']['nombre_original']) ?></div>
                <div class="compare-grid__label">Quincena · lote</div><div><?= e($A['archivo']['periodo_descripcion']) ?> · <?= e($A['archivo']['lote_nombre']) ?></div><div><?= e($B['archivo']['periodo_descripcion']) ?> · <?= e($B['archivo']['lote_nombre']) ?></div>
                <div class="compare-grid__label">SHA-256</div><div><code class="small text-break"><?= e($A['archivo']['sha256']) ?></code></div><div><code class="small text-break"><?= e($B['archivo']['sha256']) ?></code></div>
                <div class="compare-grid__label">Huella lógica</div><div><code class="small text-break"><?= e($A['archivo']['huella_contenido'] ? substr((string) $A['archivo']['huella_contenido'], 0, 16) . '…' : '—') ?></code></div><div><code class="small text-break"><?= e($B['archivo']['huella_contenido'] ? substr((string) $B['archivo']['huella_contenido'], 0, 16) . '…' : '—') ?></code></div>
                <div class="compare-grid__label">Relación</div><div><?= status_badge((string) $A['archivo']['clasificacion_duplicidad']) ?></div><div><?= status_badge((string) $B['archivo']['clasificacion_duplicidad']) ?></div>
                <div class="compare-grid__label">Perfil</div><div><code class="small"><?= e($side($A['analisis'], 'perfil_codigo') ?? '—') ?></code></div><div><code class="small"><?= e($side($B['analisis'], 'perfil_codigo') ?? '—') ?></code></div>
                <div class="compare-grid__label">Hojas</div><div><?= e(count($A['hojas'])) ?></div><div><?= e(count($B['hojas'])) ?></div>
                <div class="compare-grid__label">Hoja principal</div><div><?= e($A['seleccionada']['nombre_original'] ?? '—') ?></div><div><?= e($B['seleccionada']['nombre_original'] ?? '—') ?></div>
                <div class="compare-grid__label">Registros de detalle</div><div><?= e(number_format((int) ($side($A['analisis'], 'filas_detalle') ?? 0))) ?></div><div><?= e(number_format((int) ($side($B['analisis'], 'filas_detalle') ?? 0))) ?></div>
                <div class="compare-grid__label">Columnas</div><div><?= e(count($A['encabezados'])) ?></div><div><?= e(count($B['encabezados'])) ?></div>
                <div class="compare-grid__label">Sólo en A / B</div><div class="small"><?= e($data['solo_a'] === [] ? '—' : implode(', ', $data['solo_a'])) ?></div><div class="small"><?= e($data['solo_b'] === [] ? '—' : implode(', ', $data['solo_b'])) ?></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card__toolbar"><h2 class="table-card__title">Controles</h2></div>
        <div class="table-responsive">
            <table class="table align-middle table-sticky-first">
                <caption class="visually-hidden">Controles de los archivos A y B</caption>
                <thead><tr><th scope="col">Control</th><th scope="col" class="num">A declarado</th><th scope="col" class="num">A calculado</th><th scope="col">A</th><th scope="col" class="num">B declarado</th><th scope="col" class="num">B calculado</th><th scope="col">B</th></tr></thead>
                <tbody>
                <?php foreach ($data['codigos_control'] as $code): $ca = $A['controles'][$code] ?? null; $cb = $B['controles'][$code] ?? null; $int = ($ca['tipo_valor'] ?? $cb['tipo_valor'] ?? '') === 'ENTERO'; ?>
                    <tr>
                        <td><?php [$base, $hoja] = array_pad(explode('#', (string) $code, 2), 2, null); ?><?= e(($nombresControl[$base] ?? $base) . ($hoja !== null && ctype_digit($hoja) ? ' (hoja ' . ((int) $hoja + 1) . ')' : '')) ?></td>
                        <td class="num"><?= e(fmt_decimal($ca['valor_declarado'] ?? null, $int ? 0 : 2)) ?></td><td class="num"><?= e(fmt_decimal($ca['valor_calculado'] ?? null, $int ? 0 : 2)) ?></td>
                        <td><?= $ca ? status_badge('CTL_' . $ca['estado']) : '—' ?></td>
                        <td class="num"><?= e(fmt_decimal($cb['valor_declarado'] ?? null, $int ? 0 : 2)) ?></td><td class="num"><?= e(fmt_decimal($cb['valor_calculado'] ?? null, $int ? 0 : 2)) ?></td>
                        <td><?= $cb ? status_badge('CTL_' . $cb['estado']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">La comparación es técnica (estructura, totales y conteo de registros iguales). La diferencia persona por persona está en los resultados del cotejo.</p>
<?php endif; ?>
