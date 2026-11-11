<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$a = $cmp['a'];
$b = $cmp['b'];
$delta = static function (int $x, int $y): string {
    $d = $y - $x;
    return $d === 0 ? '<span class="text-muted">=</span>' : '<strong>' . ($d > 0 ? '+' . $d : '−' . abs($d)) . '</strong>';
};
$money = static fn (mixed $v): string => money($v);
$pdelta = static function (mixed $x, mixed $y): string {
    if ($x === null || $y === null) {
        return '—';
    }
    $d = bcsub((string) $y, (string) $x, 2);
    return bccomp($d, '0', 2) === 0 ? '<span class="text-muted">=</span>'
        : '<strong>' . (bccomp($d, '0', 2) > 0 ? '+' : '−') . e(ltrim($d, '-')) . '</strong><span class="visually-hidden"> puntos</span>';
};
$mdelta = static function (mixed $x, mixed $y): string {
    if ($x === null || $y === null) {
        return '—';
    }
    $d = bcsub((string) $y, (string) $x, 2);
    return bccomp($d, '0', 2) === 0 ? '<span class="text-muted">=</span>' : '<strong>' . money($d, 2, true) . '</strong>';
};
?>
<div class="page-header"><div><h1>Cotejo n.º <?= e($a['numero']) ?> contra n.º <?= e($b['numero']) ?></h1>
    <p class="page-header__sub"><?= e($a['periodo']) ?> · n.º <?= e($a['numero']) ?> <?= status_badge((string) $a['estado']) ?> (<?= e(fmt_datetime($a['terminada_at'])) ?>) → n.º <?= e($b['numero']) ?> <?= status_badge((string) $b['estado']) ?> (<?= e(fmt_datetime($b['terminada_at'])) ?>)</p></div>
    <div class="page-header__actions"><a class="btn-action btn-view" href="/conciliaciones/<?= e($a['id']) ?>"><span class="btn-action__label">Ver n.º <?= e($a['numero']) ?></span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a>
        <a class="btn-action btn-view" href="/conciliaciones/<?= e($b['id']) ?>"><span class="btn-action__label">Ver n.º <?= e($b['numero']) ?></span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></div></div>
<div class="row g-3 mb-3">
    <div class="col-lg-5"><div class="table-card h-100"><div class="table-card__toolbar"><h2 class="table-card__title">Personas por estado</h2></div>
        <div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Personas por estado en cada cotejo</caption>
            <thead><tr><th scope="col">Estado</th><th scope="col" class="num">n.º <?= e($a['numero']) ?></th><th scope="col" class="num">n.º <?= e($b['numero']) ?></th><th scope="col" class="num">Cambio</th></tr></thead>
            <tbody><?php foreach ($cmp['estados'] as $st => [$x, $y]) : $x ??= 0; $y ??= 0; ?><tr><td><?= status_badge((string) $st) ?></td><td class="num"><?= e($x) ?></td><td class="num"><?= e($y) ?></td><td class="num"><?= $delta($x, $y) ?></td></tr><?php endforeach; ?>
                <tr><td><strong>% conciliado</strong> <small class="text-muted">(cambio en puntos)</small></td><td class="num"><?= e($cmp['pct'][0] ?? '—') ?> %</td><td class="num"><?= e($cmp['pct'][1] ?? '—') ?> %</td><td class="num"><?= $pdelta($cmp['pct'][0], $cmp['pct'][1]) ?></td></tr></tbody>
        </table></div></div></div>
    <div class="col-lg-7"><div class="table-card h-100"><div class="table-card__toolbar"><h2 class="table-card__title">Montos y pensiones</h2></div>
        <div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Montos y pensiones en cada cotejo</caption>
            <thead><tr><th scope="col">Concepto</th><th scope="col" class="num">n.º <?= e($a['numero']) ?></th><th scope="col" class="num">n.º <?= e($b['numero']) ?></th><th scope="col" class="num">Cambio</th></tr></thead>
            <tbody><?php foreach ($cmp['metricas'] as $l => [$x, $y]): ?><tr><td><?= e($l) ?></td><td class="num"><?= $money($x) ?></td><td class="num"><?= $money($y) ?></td><td class="num"><?= $mdelta($x, $y) ?></td></tr><?php endforeach; ?>
                <?php foreach ($cmp['pensiones'] as $st => [$x, $y]): $x ??= 0; $y ??= 0; ?><tr><td>Pensión · <?= e(status_label((string) $st)) ?></td><td class="num"><?= e($x) ?></td><td class="num"><?= e($y) ?></td><td class="num"><?= $delta($x, $y) ?></td></tr><?php endforeach; ?></tbody>
        </table></div></div></div>
</div>
<div class="table-card">
    <div class="table-card__toolbar"><h2 class="table-card__title">Personas que cambiaron (<?= e($cmp['total_cambios']) ?><?= $cmp['total_cambios'] > $cmp['limite'] ? ', se muestran ' . e($cmp['limite']) : '' ?>)</h2></div>
    <?php if ($cmp['cambios'] === []): ?><div class="empty-state empty-state--sm"><p class="empty-state__title">Ninguna persona cambió de estado ni de diferencia</p></div>
    <?php else: ?><div class="table-responsive"><table class="table align-middle table-stack"><caption class="visually-hidden">Personas que cambiaron entre versiones</caption>
        <thead><tr><th scope="col">Persona</th><th scope="col">n.º <?= e($a['numero']) ?></th><th scope="col">n.º <?= e($b['numero']) ?></th><th scope="col">Qué cambió (según la trazabilidad)</th></tr></thead>
        <tbody><?php foreach ($cmp['cambios'] as $r): ?>
            <tr><td class="small"><a href="/conciliaciones/<?= e($b['id']) ?>/empleados/<?= e($r['empleado_id']) ?>"><?= e($r['numero_empleado']) ?></a> <?= e($r['nombre_completo']) ?></td>
                <td><?= $r['estado_a'] ? status_badge((string) $r['estado_a']) . '<br><small>' . $money($r['dif_a']) . '</small>' : '<span class="text-muted">—</span>' ?></td>
                <td><?= $r['estado_b'] ? status_badge((string) $r['estado_b']) . '<br><small>' . $money($r['dif_b']) . '</small>' : '<span class="text-muted">—</span>' ?></td>
                <td class="small"><?php foreach ($r['motivos'] as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
