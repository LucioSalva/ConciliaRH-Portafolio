<?php
/** @var \ConciliaRH\Core\Template $this */
/** @var \ConciliaRH\Domain\Auth\CurrentUser $currentUser */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div>
        <h1>Tablero</h1>
        <p class="page-header__sub">Hola, <?= e(explode(' ', $currentUser->nombre)[0]) ?>. Aquí está el cuadre del último cotejo terminado y lo que requiere atención.</p>
    </div>
</div>

<section class="section" aria-labelledby="atencionTitle">
    <div class="section__head"><h2 class="section__title" id="atencionTitle">Pendiente de mi atención</h2>
        <?php if (!empty($atencion)): ?><span class="section__meta"><?= e(count($atencion)) ?> tipo(s) de tarea según sus permisos</span><?php endif; ?></div>
    <?php if (empty($atencion)): ?>
        <p class="attention-ok"><i class="bi bi-check2-circle" aria-hidden="true"></i>Nada espera su decisión en este momento.</p>
    <?php else: ?>
        <ul class="attention">
            <?php foreach ($atencion as $t): ?>
                <li><a class="attention__item<?= $t['prioridad'] === 'ALTA' ? ' attention__item--alta' : '' ?>" href="<?= e($t['url']) ?>">
                    <span class="attention__count"><?= e(number_format($t['cantidad'])) ?></span>
                    <span class="attention__text"><?= $t['prioridad'] === 'ALTA' ? '<span class="visually-hidden">Prioridad alta: </span>' : '' ?><?= e($t['texto']) ?></span>
                    <span class="attention__action"><i class="bi <?= e($t['icono']) ?>" aria-hidden="true"></i> <?= e($t['accion']) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if (!empty($alertas)): ?>
    <section class="mb-3" aria-label="Alertas operativas">
        <?php foreach ($alertas as $al): ?>
            <div class="notice notice--<?= e($al['nivel']) ?> notice--compact" role="status"><i class="bi <?= $al['nivel'] === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill' ?> notice__icon" aria-hidden="true"></i>
                <div class="notice__body"><?= e($al['texto']) ?> <a href="<?= e($al['url']) ?>">Revisar</a></div></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php if (array_key_exists('conciliation', get_defined_vars())): ?>
    <section class="section" aria-labelledby="concTitle">
        <?php if ($conciliation === null): ?>
            <div class="card"><div class="card-body"><div class="empty-state">
                <div class="empty-state__icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></div>
                <h2 class="empty-state__title" id="concTitle">Aún no hay cotejos terminados</h2>
                <p class="mb-3">Cargue los archivos de la quincena y cotéjela para ver aquí su cuadre.</p>
                <a class="btn-action btn-add" href="/periodos"><span class="btn-action__label">Ir a Quincenas</span><span class="btn-action__icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></span></a>
            </div></div></div>
        <?php else: $cc = $conciliation; ?>
            <div class="section__head">
                <h2 class="section__title d-flex flex-wrap align-items-center gap-2" id="concTitle">
                    <span><?= e($cc['periodo']) ?></span>
                    <?= status_badge($cc['lote_oficial'] ? 'OFICIAL' : 'NO_OFICIAL') ?>
                    <?= status_badge((string) $cc['estado']) ?></h2>
                <a class="btn-action btn-view" href="/conciliaciones/<?= e($cc['id']) ?>"><span class="btn-action__label">Ver cotejo</span><span class="btn-action__icon"><i class="bi bi-arrow-right" aria-hidden="true"></i></span></a>
            </div>
            <?= $this->insert('conciliations/_summary', ['c' => $cc]) ?>
            <?= $this->insert('conciliations/_charts', ['c' => $cc]) ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (isset($userStats) || isset($queue)): ?>
<section class="sysline" aria-label="Estado de la operación">
    <?php if (isset($queue)): ?>
        <span class="sysline__item"><?= $queue['workers_activos'] > 0 ? status_badge('ACTIVO', 'Procesador activo (' . $queue['workers_activos'] . ')') : status_badge('ERROR', 'Procesador detenido') ?>
            <span class="text-muted small">último latido <?= e(fmt_datetime($queue['ultimo_latido'], true)) ?></span></span>
        <span class="sysline__item"><i class="bi bi-list-task text-gold" aria-hidden="true"></i>Cola: <strong><?= e($queue['pendientes']) ?></strong> pendiente(s), <strong><?= e($queue['en_ejecucion']) ?></strong> en ejecución</span>
    <?php endif; ?>
    <?php if (isset($userStats)): ?>
        <span class="sysline__item"><i class="bi bi-people text-gold" aria-hidden="true"></i>Usuarios: <strong><?= e($userStats['activos']) ?></strong> activos, <strong><?= e($userStats['en_linea']) ?></strong> en línea, <?= e($userStats['inactivos']) ?> inactivos</span>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header card-header--accent d-flex justify-content-between align-items-center">
                <span>Quincenas recientes</span>
                <?php if (isset($recentPeriods)): ?><a class="small" href="/periodos">Ver quincenas</a><?php endif; ?>
            </div>
            <?php if (empty($recentPeriods)): ?>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state__icon"><i class="bi bi-inboxes" aria-hidden="true"></i></div>
                        <p class="empty-state__title">Aún no hay quincenas</p>
                        <p class="mb-0">Cree la quincena y cargue en ella la nómina de RH y los pagos de Tesorería.</p>
                    </div>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentPeriods as $rp): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span><a class="fw-600" href="/periodos/<?= e($rp['id']) ?>"><?= e($rp['descripcion']) ?></a><br><small class="text-muted"><?= e($rp['archivos']) ?> archivo(s)</small></span>
                            <?= status_badge((string) $rp['estado']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($fileStates)): ?>
                <div class="card-body border-top d-flex flex-wrap gap-2">
                    <?php foreach ($fileStates as $fs): ?><a href="/importaciones?estado=<?= e($fs['estado']) ?>" class="text-decoration-none"><?= status_badge((string) $fs['estado'], status_label((string) $fs['estado']) . ': ' . $fs['n']) ?></a><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header card-header--accent d-flex justify-content-between align-items-center">
                <span>Actividad reciente</span>
                <?php if (isset($recentAudit)): ?><a class="small" href="/auditoria">Ver auditoría</a><?php endif; ?>
            </div>
            <?php if (!isset($recentAudit)): ?>
                <div class="empty-state empty-state--sm"><p class="empty-state__title">Su rol no incluye la consulta de auditoría</p></div>
            <?php elseif ($recentAudit === []): ?>
                <div class="empty-state empty-state--sm"><p class="empty-state__title">Sin actividad registrada</p></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentAudit as $a): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-break-any min-w-0"><code><?= e($a['accion']) ?></code><br><small class="text-muted"><?= e($a['username'] ?? 'sistema') ?></small></span>
                            <small class="text-muted text-end flex-shrink-0"><time datetime="<?= e($a['creado_at']) ?>"><?= e(fmt_datetime($a['creado_at'])) ?></time></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
