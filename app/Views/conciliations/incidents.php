<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$base = '/conciliaciones/' . (int) $c['id'];
$editable = !in_array($c['estado'], ['CERRADA', 'INVALIDADA'], true) && $this->can('incidencias.resolver');
$money = static fn (mixed $v): string => $v === null ? '' : money($v);
?>
<div class="page-header">
    <div>
        <h1>Incidencias del cotejo</h1>
        <p class="page-header__sub"><?= e($c['periodo']) ?> · cotejo n.º <?= e($c['numero']) ?> · <?= status_badge((string) $c['estado']) ?></p>
    </div>
    <div class="page-header__actions"><a class="btn-action btn-view" href="<?= e($base) ?>"><span class="btn-action__label">Tablero</span><span class="btn-action__icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span></a></div>
</div>
<div class="notice notice--info" role="note"><i class="bi bi-info-circle notice__icon" aria-hidden="true"></i><div class="notice__body">Resolver o justificar una incidencia registra la decisión, quién la tomó y la evidencia. <strong>No modifica importes</strong>: para corregir un dato se carga un archivo nuevo y se vuelve a cotejar.</div></div>

<form method="get" action="<?= e($base) ?>/incidencias" class="card mb-3" aria-label="Filtrar incidencias"><div class="card-body filter-bar">
    <div><label class="form-label" for="estado">Estado</label>
        <select class="form-select form-select-sm" id="estado" name="estado">
            <option value="PENDIENTES"<?= $filters['estado'] === 'PENDIENTES' ? ' selected' : '' ?>>Pendientes</option><option value="TODAS"<?= $filters['estado'] === 'TODAS' ? ' selected' : '' ?>>Todas</option>
            <?php foreach (\ConciliaRH\Domain\Conciliation\ConciliationService::ESTADOS_INCIDENCIA as $s): ?><option value="<?= e($s) ?>"<?= $filters['estado'] === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="severidad">Severidad</label>
        <select class="form-select form-select-sm" id="severidad" name="severidad"><option value="">Todas</option>
            <?php foreach (['CRITICA' => 'Crítica', 'ERROR' => 'Error', 'ADVERTENCIA' => 'Advertencia', 'INFO' => 'Informativa'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['severidad'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="tipo">Tipo</label>
        <select class="form-select form-select-sm" id="tipo" name="tipo"><option value="">Todos</option>
            <?php foreach ($types as $t): ?><option value="<?= e($t['tipo']) ?>"<?= $filters['tipo'] === $t['tipo'] ? ' selected' : '' ?>><?= e(status_label($t['tipo'])) ?> (<?= e($t['n']) ?>)</option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="banco">Banco</label>
        <select class="form-select form-select-sm" id="banco" name="banco"><option value="">Todos</option>
            <?php foreach (['SANTANDER', 'BBVA', 'INTERBANCARIA'] as $v): ?><option value="<?= e($v) ?>"<?= $filters['banco'] === $v ? ' selected' : '' ?>><?= e(bank_label($v)) ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label" for="empleado">Núm.</label>
        <input class="form-control form-control-sm" id="empleado" name="empleado" value="<?= e($filters['empleado']) ?>" maxlength="20" inputmode="numeric" autocomplete="off"></div>
    <div class="filter-bar__actions"><button class="btn-action btn-view btn-action--sm" type="submit"><span class="btn-action__label">Filtrar</span><span class="btn-action__icon"><i class="bi bi-funnel" aria-hidden="true"></i></span></button></div>
</div></form>

<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div><p class="empty-state__title">Sin incidencias con estos filtros</p></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table align-middle table-stack">
        <caption class="visually-hidden">Incidencias de la conciliación</caption>
        <thead><tr><th scope="col">Severidad</th><th scope="col">Incidencia</th><th scope="col">Persona / ámbito</th><th scope="col" class="num">Importe</th><th scope="col">Estado</th><?php if ($editable): ?><th scope="col" class="cell-actions"><span class="visually-hidden">Gestión</span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $i): ?>
            <tr>
                <td><?= status_badge('SEV_' . $i['severidad']) ?><br><small class="text-muted">Nivel <?= e($i['nivel']) ?></small></td>
                <td><strong class="small"><?= e(status_label((string) $i['tipo'])) ?></strong><br><span class="small"><?= e($i['mensaje']) ?></span>
                    <?php if ($i['comentario']): ?><br><small class="text-muted"><i class="bi bi-chat-left-text" aria-hidden="true"></i> <?= e($i['comentario']) ?> — <?= e($i['actualizado_por_usuario'] ?? '') ?>, <?= e(fmt_datetime($i['actualizado_at'])) ?></small><?php endif; ?>
                    <?php if ($i['evidencia']): ?><br><small class="text-muted"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= e($i['evidencia']) ?></small><?php endif; ?></td>
                <td class="small"><?php if ($i['empleado_id']): ?><a href="<?= e($base) ?>/empleados/<?= e($i['empleado_id']) ?>"><?= e($i['numero_empleado']) ?></a> <?= e($i['nombre_completo']) ?><?php else: ?><?= e(['POLICIA' => 'Policías', 'BOMBEROS' => 'Bomberos', 'TOTAL' => 'Toda la quincena'][$i['grupo'] ?? ''] ?? ($i['grupo'] ?? 'Toda la quincena')) ?><?= $i['banco'] ? ' · ' . e(bank_label($i['banco'])) : '' ?><?php endif; ?></td>
                <td class="num"><?= $money($i['importe']) ?></td>
                <td><?= status_badge((string) $i['estado']) ?></td>
                <?php if ($editable): ?>
                <td class="cell-actions">
                    <form method="post" action="<?= e($base) ?>/incidencias/<?= e($i['id']) ?>" class="d-flex flex-wrap gap-1 justify-content-end" data-confirm="Se registrará el cambio de estado con su comentario. Los importes no cambian." data-confirm-title="Gestionar incidencia" data-confirm-reason="motivo">
                        <?= $this->csrf() ?>
                        <label class="visually-hidden" for="est<?= e($i['id']) ?>">Nuevo estado</label>
                        <select class="form-select form-select-sm w-auto" id="est<?= e($i['id']) ?>" name="estado">
                            <?php foreach (\ConciliaRH\Domain\Conciliation\ConciliationService::ESTADOS_INCIDENCIA as $s): if ($s === $i['estado']) continue; ?><option value="<?= e($s) ?>"><?= e(status_label($s)) ?></option><?php endforeach; ?>
                        </select>
                        <label class="visually-hidden" for="ev<?= e($i['id']) ?>">Evidencia</label>
                        <input class="form-control form-control-sm w-auto" id="ev<?= e($i['id']) ?>" name="evidencia" maxlength="1000" placeholder="Evidencia (opcional)">
                        <button class="btn-action btn-confirm btn-action--sm" type="submit"><span class="btn-action__label">Aplicar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
</div>
