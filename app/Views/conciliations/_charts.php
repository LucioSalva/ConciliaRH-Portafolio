<?php
/**
 * Gráficas accesibles del tablero (FASE 6): barras CSS con la TABLA de datos como fuente (no dependen del color: cada fila
 * trae etiqueta, número y porcentaje; lo pendiente lleva patrón rayado). Anchos por data-progress (CSP: sin estilos en línea).
 * @var array<string,mixed> $c
 */
$t = $c['totales']['TOTAL'] ?? null;
if ($t === null) {
    return;
}
$porEstado = $c['resumen']['por_estado'] ?? [];
$totalP = max(1, array_sum(array_map('intval', $porEstado)));
$orden = ['CORRECTO', 'NO_PAGADO', 'PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD', 'PAGO_MENOR', 'PAGO_MAYOR', 'POSIBLE_PAGO_DUPLICADO', 'PAGO_SIN_RESPALDO_RRHH', 'ERROR_DATOS_RRHH', 'NO_APLICA'];
$fill = static fn (string $s): string => match ($s) {
    'CORRECTO' => 'chart-fill--success', 'PENDIENTE_IDENTIDAD', 'CONFLICTO_IDENTIDAD' => 'chart-fill--pending', 'NO_APLICA' => 'chart-fill--neutral', default => 'chart-fill--danger',
};
$texto = [];
foreach ($orden as $s) {
    if (!empty($porEstado[$s])) {
        $texto[] = $porEstado[$s] . ' ' . mb_strtolower(status_label($s));
    }
}
$bancos = [];
foreach (['SANTANDER', 'BBVA', 'INTERBANCARIA', 'OTROS'] as $b) {
    $imp = (string) ($t['tesoreria_nomina']['por_banco'][$b] ?? '0');
    if (bccomp($imp, '0', 2) > 0) {
        $id = (string) ($t['tesoreria_nomina']['por_banco_identificado'][$b] ?? '0');
        $bancos[$b] = [$imp, $id, bcsub($imp, $id, 2)];
    }
}
$maxB = '1';
foreach ($bancos as [$imp]) {
    $maxB = bccomp($imp, $maxB, 2) > 0 ? $imp : $maxB;
}
$pct = static fn (string $v, string $max): string => bcmul(bcdiv($v, $max, 6), '100', 1);
?>
<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent" id="chart-estados-t">Estado de las personas</div><div class="card-body">
        <?php if ($texto === []): ?><div class="empty-state empty-state--sm" id="chart-estados-d"><p class="empty-state__title">Sin personas evaluadas en esta ejecución</p></div><?php else: ?>
        <p class="small text-muted mb-2" id="chart-estados-d">De <?= e($totalP) ?> personas: <?= e(implode(', ', $texto)) ?>.</p>
        <table class="chart-table" aria-labelledby="chart-estados-t" aria-describedby="chart-estados-d">
            <thead class="visually-hidden"><tr><th scope="col">Estado</th><th scope="col">Personas</th><th scope="col">Porcentaje</th></tr></thead>
            <tbody><?php foreach ($orden as $s): if (empty($porEstado[$s])) continue; $p = number_format(100 * (int) $porEstado[$s] / $totalP, 1); ?>
                <tr><th scope="row" class="chart-table__label"><a href="/conciliaciones/<?= e($c['id']) ?>/empleados?estado=<?= e($s) ?>"><?= e(status_label($s)) ?></a></th>
                    <td class="chart-table__bar"><div class="chart-bar" aria-hidden="true"><div class="chart-bar__fill <?= e($fill($s)) ?>" data-progress="<?= e($p) ?>"></div></div></td>
                    <td class="chart-table__num"><?= e(number_format((int) $porEstado[$s])) ?> <span class="text-muted">(<?= e($p) ?> %)</span></td></tr>
            <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header card-header--accent" id="chart-bancos-t">Pagos de nómina por banco</div><div class="card-body">
        <p class="small text-muted mb-2" id="chart-bancos-d">Importe dispersado, identificado y pendiente de identidad por banco. Lo pendiente cuenta en el banco pero no se asigna a nadie.</p>
        <?php if ($bancos === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Sin pagos de nómina dispersados</p></div><?php else: ?>
        <table class="chart-table" aria-labelledby="chart-bancos-t" aria-describedby="chart-bancos-d">
            <thead class="visually-hidden"><tr><th scope="col">Banco</th><th scope="col">Serie</th><th scope="col">Barra</th><th scope="col">Importe</th></tr></thead>
            <tbody><?php foreach ($bancos as $b => [$imp, $id, $pe]): ?>
                <?php foreach ([['Importe', $imp, 'chart-fill--wine'], ['Identificado', $id, 'chart-fill--success'], ['Pendiente', $pe, 'chart-fill--pending']] as $i => [$l, $v, $cls]): if ($l === 'Pendiente' && bccomp($v, '0', 2) === 0) continue; ?>
                <tr><?php if ($i === 0): ?><th scope="rowgroup" class="chart-table__label"><?= e(bank_label($b)) ?></th><?php else: ?><td></td><?php endif; ?>
                    <td class="chart-table__serie small"><?= e($l) ?></td>
                    <td class="chart-table__bar"><div class="chart-bar" aria-hidden="true"><div class="chart-bar__fill <?= e($cls) ?>" data-progress="<?= e($pct($v, $maxB)) ?>"></div></div></td>
                    <td class="chart-table__num"><?= money($v) ?></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div></div></div>
</div>
