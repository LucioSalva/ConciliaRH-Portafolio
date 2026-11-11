<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$edit = $this->can('conceptos.editar');
?>
<div class="page-header">
    <div><h1>Conceptos de nómina</h1>
        <p class="page-header__sub">Catálogo por CÓDIGO. Las pensiones alimenticias se identifican por código, nunca por el texto. Los códigos nuevos se agregan como INFERIDA (tipo por cuadre del tabulado o por el bloque de la póliza GLOBAL).</p></div>
</div>
<div class="table-card">
    <div class="table-responsive"><table class="table table-hover align-middle table-stack"><caption class="visually-hidden">Catálogo de conceptos de nómina</caption>
        <thead><tr><th scope="col">Código</th><th scope="col">Descripción</th><th scope="col">Tipo</th><th scope="col">Pensión</th><th scope="col">Clasificación</th><th scope="col" class="num col-hide-sm">Usos</th><?php if ($edit): ?><th scope="col" class="cell-actions">Configurar</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($rows as $k): ?>
            <tr><td><code><?= e($k['codigo']) ?></code></td><td class="small"><?= e($k['descripcion'] ?? '—') ?></td><td class="small"><?= e($k['tipo']) ?></td>
                <td><?= $k['es_pension'] ? status_badge('PENSION') : '<span class="diff--none">—</span>' ?></td>
                <td class="small"><?= e($k['clasificacion']) ?></td><td class="num small col-hide-sm"><?= e(number_format((int) $k['usos'])) ?></td>
                <?php if ($edit): ?><td class="cell-actions">
                    <form method="post" action="/conceptos/<?= e($k['id']) ?>" class="d-inline-flex flex-wrap gap-1 align-items-center justify-content-end" data-confirm="La clasificación queda CONFIGURADA y el motor ya no la cambiará." data-confirm-title="Configurar concepto <?= e($k['codigo']) ?>" data-confirm-reason="motivo">
                        <?= $this->csrf() ?>
                        <label class="visually-hidden" for="t<?= e($k['id']) ?>">Tipo</label>
                        <select class="form-select form-select-sm w-auto" id="t<?= e($k['id']) ?>" name="tipo"><?php foreach (['PERCEPCION', 'DEDUCCION', 'DESCONOCIDO'] as $t): ?><option<?= $k['tipo'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                        <div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="es_pension" value="1" id="p<?= e($k['id']) ?>"<?= $k['es_pension'] ? ' checked' : '' ?>><label class="form-check-label" for="p<?= e($k['id']) ?>">Pensión</label></div>
                        <button class="btn-action btn-edit btn-action--sm" type="submit"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button>
                    </form></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody></table></div>
</div>
