<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$pretty = static function (?string $json): string {
    if ($json === null) {
        return '—';
    }
    $d = json_decode($json, true);
    return (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
// Vista legible: claves anidadas como «a › b», listas unidas por coma; los valores ya vienen enmascarados/redactados por AuditLogger
$flat = static function (?string $json) use (&$flat): array {
    $d = $json === null ? [] : json_decode($json, true);
    $out = [];
    $walk = static function ($v, string $k) use (&$walk, &$out): void {
        if (is_array($v) && $v !== [] && !array_is_list($v)) {
            foreach ($v as $kk => $vv) {
                $walk($vv, $k === '' ? (string) $kk : $k . ' › ' . $kk);
            }
            return;
        }
        $out[$k === '' ? 'valor' : $k] = match (true) {
            is_bool($v) => $v ? 'sí' : 'no',
            $v === null => '—',
            is_array($v) => $v === [] ? '—' : implode(', ', array_map(static fn ($x) => is_scalar($x) ? (string) $x : (string) json_encode($x, JSON_UNESCAPED_UNICODE), $v)),
            default => (string) $v,
        };
    };
    if (is_array($d) && $d !== []) {
        $walk($d, '');
    } elseif (!is_array($d) && $d !== null) {
        $out['valor'] = (string) $d;
    }
    return $out;
};
$antes = $flat($row['valor_anterior']);
$despues = $flat($row['valor_nuevo']);
$contexto = $flat($row['contexto']);
$campos = array_values(array_unique([...array_keys($antes), ...array_keys($despues)]));
?>
<div class="page-header">
    <div>
        <h1>Registro #<?= e($row['secuencia']) ?></h1>
        <p class="page-header__sub"><code><?= e($row['accion']) ?></code><?= \ConciliaRH\Domain\Audit\AuditRepository::failed((string) $row['accion']) ? ' ' . status_badge('ERROR', 'Fallido o rechazado') : '' ?></p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/auditoria"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header card-header--accent">Contexto</div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Fecha</dt><dd><?= e(fmt_datetime($row['creado_at'], true)) ?></dd>
                    <dt>Usuario</dt><dd><?= e($row['username'] ?? '—') ?></dd>
                    <dt>Roles</dt><dd><?= e($row['roles'] ?? '—') ?></dd>
                    <dt>Origen</dt><dd><?= e($row['origen']) ?></dd>
                    <dt>IP</dt><dd><?= e($row['ip_texto'] ?? '—') ?></dd>
                    <dt>Navegador</dt><dd class="small"><?= e($row['user_agent'] ?? '—') ?></dd>
                    <dt>Entidad</dt><dd><?= e($row['entidad'] ?? '—') ?><?= $row['entidad_id'] !== null ? ' #' . e($row['entidad_id']) : '' ?></dd>
                    <dt>Hash</dt><dd><code class="small"><?= e($row['hash']) ?></code></dd>
                    <dt>Hash previo</dt><dd><code class="small"><?= e($row['hash_prev'] ?? '—') ?></code></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-7 d-grid gap-3">
        <?php if ($campos !== []): ?>
        <div class="table-card"><div class="table-card__toolbar"><h2 class="table-card__title">Cambio registrado</h2></div>
            <div class="table-responsive"><table class="table table-sm change-table mb-0">
                <caption class="visually-hidden">Valores antes y después de la acción</caption>
                <thead><tr><th scope="col">Campo</th><th scope="col">Antes</th><th scope="col">Después</th></tr></thead>
                <tbody><?php foreach ($campos as $c): $a = $antes[$c] ?? null; $b = $despues[$c] ?? null; $cambio = $a !== $b; ?>
                    <tr><th scope="row" class="small"><?= e($c) ?></th>
                        <td class="text-break-any<?= $cambio && $a !== null ? ' change--del' : '' ?>"><?= e($a ?? '—') ?></td>
                        <td class="text-break-any<?= $cambio && $b !== null ? ' change--add' : '' ?>"><?= e($b ?? '—') ?><?= !$cambio ? ' <span class="visually-hidden">(sin cambio)</span>' : '' ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div></div>
        <?php else: ?>
        <div class="card"><div class="card-body"><p class="small text-muted mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Esta acción no modifica datos: sólo deja constancia (sin valores antes/después).</p></div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header card-header--accent">Contexto adicional</div><div class="card-body">
            <?php if ($contexto === []): ?><div class="empty-state empty-state--sm"><p class="mb-0">Sin contexto adicional.</p></div>
            <?php else: ?><dl class="dl-grid"><?php foreach ($contexto as $k => $v): ?><dt><?= e($k) ?></dt><dd class="text-break-any"><?= e($v) ?></dd><?php endforeach; ?></dl><?php endif; ?>
        </div></div>
        <details class="card"><summary class="card-header">Datos técnicos (JSON)</summary><div class="card-body d-grid gap-2">
            <p class="small text-muted mb-0">Tal como quedaron en la bitácora (los datos sensibles ya están enmascarados).</p>
            <h3 class="h6 mb-0">Valor anterior</h3><pre class="json-block"><?= e($pretty($row['valor_anterior'])) ?></pre>
            <h3 class="h6 mb-0">Valor nuevo</h3><pre class="json-block"><?= e($pretty($row['valor_nuevo'])) ?></pre>
            <h3 class="h6 mb-0">Contexto</h3><pre class="json-block"><?= e($pretty($row['contexto'])) ?></pre>
        </div></details>
    </div>
</div>
