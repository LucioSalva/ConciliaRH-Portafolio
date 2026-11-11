<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$verLotes = $this->can('lotes.declarar_oficial');
?>
<div class="page-header">
    <div>
        <h1>Quincenas</h1>
        <p class="page-header__sub">Cada quincena reúne la nómina de RH y los pagos de Tesorería de Policías y Bomberos.</p>
    </div>
    <?php if ($this->can('periodos.crear')): ?>
        <div class="page-header__actions">
            <a class="btn-action btn-add" href="/periodos/nuevo"><span class="btn-action__label">Nueva quincena</span><span class="btn-action__icon"><i class="bi bi-calendar-plus" aria-hidden="true"></i></span></a>
        </div>
    <?php endif; ?>
</div>

<div class="table-card">
    <form class="table-card__toolbar" method="get" action="/periodos" aria-label="Filtrar periodos">
        <div class="filter-bar">
            <div>
                <label class="form-label small mb-1" for="anio">Año</label>
                <select class="form-select form-select-sm" id="anio" name="anio" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($years as $y): ?><option value="<?= e($y) ?>"<?= $filters['anio'] === $y ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="mes">Mes</label>
                <select class="form-select form-select-sm" id="mes" name="mes" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach ($meses as $n => $m): ?><option value="<?= e($n) ?>"<?= $filters['mes'] === $n ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="quincena">Quincena</label>
                <select class="form-select form-select-sm" id="quincena" name="quincena" data-autosubmit>
                    <option value="">Ambas</option>
                    <option value="1"<?= $filters['quincena'] === 1 ? ' selected' : '' ?>>1ra</option>
                    <option value="2"<?= $filters['quincena'] === 2 ? ' selected' : '' ?>>2da</option>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1" for="estado">Estado</label>
                <select class="form-select form-select-sm" id="estado" name="estado" data-autosubmit>
                    <option value="">Todos</option>
                    <?php foreach (['ABIERTO' => 'Abierto', 'CERRADO' => 'Cerrado', 'ANULADO' => 'Anulado'] as $v => $l): ?>
                        <option value="<?= e($v) ?>"<?= $filters['estado'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><i class="bi bi-calendar3" aria-hidden="true"></i></div>
            <p class="empty-state__title">No hay periodos que coincidan</p>
            <?php if ($this->can('periodos.crear')): ?><p class="mb-0"><a href="/periodos/nuevo">Cree el primer periodo</a>.</p><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sticky-first align-middle">
                <caption class="visually-hidden">Quincenas (cada una se abre con «Ver»)</caption>
                <thead><tr><th scope="col">Quincena</th><th scope="col" class="col-hide-sm">Fechas</th><th scope="col">Estado</th><?php if ($verLotes): ?><th scope="col" class="num">Lotes</th><?php endif; ?><th scope="col" class="num col-hide-sm">Archivos</th><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td class="fw-600"><?= e($p['descripcion']) ?><?php if ($p['tipo'] !== 'ORDINARIA'): ?> <?= status_badge('EXTRAORDINARIA') ?><?php endif; ?></td>
                        <td class="col-hide-sm nowrap"><?= e(date('d/m/Y', strtotime((string) $p['fecha_inicio']))) ?> – <?= e(date('d/m/Y', strtotime((string) $p['fecha_fin']))) ?></td>
                        <td><?= status_badge((string) $p['estado']) ?></td>
                        <?php if ($verLotes): ?><td class="num"><?= e($p['lotes']) ?></td><?php endif; ?>
                        <td class="num col-hide-sm"><?= e($p['archivos']) ?></td>
                        <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/periodos/<?= e($p['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination') ?>
</div>
