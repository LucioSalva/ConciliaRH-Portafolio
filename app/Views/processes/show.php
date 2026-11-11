<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$json = static fn (mixed $v): string => $v === null ? '—' : (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="page-header">
    <div>
        <h1>Proceso #<?= e($job['id']) ?> <small class="text-muted fs-6"><code><?= e($job['tipo']) ?></code></small></h1>
        <p class="page-header__sub">Creado por <?= e($job['creado_por_username'] ?? 'sistema') ?> el <?= e(fmt_datetime($job['created_at'], true)) ?></p>
    </div>
    <div class="page-header__actions">
        <?php if ($job['estado'] === 'PENDIENTE' && $this->can('procesos.cancelar')): ?>
            <form method="post" action="/procesos/<?= e($job['id']) ?>/cancelar" data-confirm="El trabajo pendiente no se ejecutará." data-confirm-title="Cancelar proceso" data-confirm-variant="danger">
                <?= $this->csrf() ?>
                <button class="btn-action btn-delete" type="submit"><span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span></button>
            </form>
        <?php endif; ?>
        <?php if ($this->can('procesos.diagnostico')): ?>
        <a class="btn-action btn-cancel" href="/procesos"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card" data-job-poll="<?= e($job['id']) ?>" data-job-state="<?= e($job['estado']) ?>">
            <div class="card-header card-header--accent">Estado</div>
            <div class="card-body">
                <p class="mb-2"><?= status_badge((string) $job['estado']) ?>
                    <span class="ms-2 small text-muted" data-job-state-text aria-live="polite"><?= e($job['etapa'] ?? '') ?></span>
                </p>
                <div class="progress mb-3" role="progressbar" aria-label="Progreso del proceso" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($job['progreso']) ?>">
                    <div class="progress-bar js-progress-bar" data-progress="<?= e($job['progreso']) ?>"></div>
                </div>
                <dl class="dl-grid">
                    <dt>Mensaje</dt><dd><?= e($job['mensaje'] ?? '—') ?></dd>
                    <dt>Intentos</dt><dd><?= e($job['intentos']) ?> de <?= e($job['max_intentos']) ?></dd>
                    <dt>Prioridad</dt><dd><?= e($job['prioridad']) ?></dd>
                    <dt>Disponible desde</dt><dd><?= e(fmt_datetime($job['disponible_desde'], true)) ?></dd>
                    <dt>Iniciado</dt><dd><?= e(fmt_datetime($job['iniciado_at'], true)) ?></dd>
                    <dt>Último latido</dt><dd><?= e(fmt_datetime($job['heartbeat_at'], true)) ?></dd>
                    <dt>Finalizado</dt><dd><?= e(fmt_datetime($job['finalizado_at'], true)) ?></dd>
                    <?php if (array_key_exists('bloqueado_por', $job)): ?><dt>Worker</dt><dd class="small"><?= e($job['bloqueado_por'] ?? '—') ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
        <?php if ($job['estado'] === 'ERROR'): ?>
            <div class="notice notice--error mt-3" role="alert">
                <i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i>
                <div class="notice__body">
                    <p class="notice__title"><?= e($job['error_codigo'] ?? 'ERROR') ?></p>
                    <?= e($job['error_mensaje'] ?? 'El proceso terminó con error.') ?>
                    <?php if (array_key_exists('error_detalle', $job) && $job['error_detalle']): ?>
                        <details class="mt-2"><summary>Detalle técnico</summary><pre class="json-block mt-2"><?= e($job['error_detalle']) ?></pre></details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-6 d-grid gap-3">
        <div class="card"><div class="card-header card-header--accent">Parámetros</div><div class="card-body"><pre class="json-block"><?= e($json($job['parametros'])) ?></pre></div></div>
        <div class="card"><div class="card-header card-header--accent">Resultado</div><div class="card-body"><pre class="json-block"><?= e($json($job['resultado'])) ?></pre></div></div>
        <div class="card">
            <div class="card-header card-header--accent">Eventos</div>
            <?php if ($events === []): ?>
                <div class="empty-state empty-state--sm"><p class="empty-state__title">Sin eventos</p></div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($events as $ev): ?>
                        <li class="list-group-item d-flex flex-wrap gap-2 align-items-baseline"><?= status_badge(['ERROR' => 'SEV_ERROR', 'WARNING' => 'SEV_ADVERTENCIA', 'INFO' => 'SEV_INFO'][$ev['nivel']] ?? 'SEV_BAJA', (string) $ev['nivel']) ?><span class="flex-grow-1 min-w-0 text-break-any"><?= e($ev['mensaje']) ?></span><span class="text-muted nowrap"><?= e(fmt_datetime($ev['creado_at'], true)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
