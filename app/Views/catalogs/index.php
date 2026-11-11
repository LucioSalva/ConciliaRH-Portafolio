<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$sections = [
    'tipos' => ['Tipos documentales', 'Qué es el documento. Independiente del origen y del perfil de lectura.', $tipos],
    'origenes' => ['Orígenes administrativos', 'Quién emite el documento. Configurable; por omisión SIN_DEFINIR.', $origenes],
];
?>
<div class="page-header">
    <div>
        <h1>Catálogos documentales</h1>
        <p class="page-header__sub">Tipo documental, origen administrativo y perfil de importación son conceptos independientes.</p>
    </div>
</div>
<div class="row g-3">
    <?php foreach ($sections as $key => [$label, $help, $items]): ?>
        <div class="col-12">
            <div class="table-card h-100">
                <div class="table-card__toolbar"><div><h2 class="table-card__title"><?= e($label) ?></h2><span class="table-card__hint"><?= e($help) ?></span></div></div>
                <div class="table-responsive">
                    <table class="table align-middle table-stack">
                        <caption class="visually-hidden"><?= e($label) ?></caption>
                        <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col" class="num">Usos</th><th scope="col">Estado</th><?php if ($canAdmin): ?><th scope="col" class="cell-actions"><span class="visually-hidden">Acciones</span></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><code><?= e($it['codigo']) ?></code></td>
                                <td><?= e($it['nombre']) ?><?php if ($it['descripcion']): ?><br><small class="text-muted"><?= e($it['descripcion']) ?></small><?php endif; ?></td>
                                <td class="num"><?= e($it['usos']) ?></td>
                                <td><?= status_badge($it['activo'] ? 'ACTIVO' : 'INACTIVO') ?></td>
                                <?php if ($canAdmin): ?>
                                    <td class="cell-actions">
                                        <form method="post" action="/catalogos/<?= e($it['id']) ?>/estado" data-confirm="<?= $it['activo'] ? 'Dejará de ofrecerse para nuevas cargas (los archivos existentes conservan su clasificación).' : 'Volverá a ofrecerse en las cargas.' ?>" data-confirm-title="<?= $it['activo'] ? 'Desactivar' : 'Activar' ?>">
                                            <?= $this->csrf() ?>
                                            <input type="hidden" name="catalogo" value="<?= e($key) ?>">
                                            <input type="hidden" name="activo" value="<?= $it['activo'] ? '0' : '1' ?>">
                                            <button class="btn-action <?= $it['activo'] ? 'btn-cancel' : 'btn-confirm' ?> btn-action--sm" type="submit"><span class="btn-action__label"><?= $it['activo'] ? 'Desactivar' : 'Activar' ?></span><span class="btn-action__icon"><i class="bi <?= $it['activo'] ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i></span></button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canAdmin): ?>
                    <form method="post" action="/catalogos" class="row g-2 p-3 border-top" data-busy>
                        <?= $this->csrf() ?>
                        <input type="hidden" name="catalogo" value="<?= e($key) ?>">
                        <div class="col-sm-4"><label class="form-label small mb-1" for="c-<?= e($key) ?>">Código</label><input class="form-control form-control-sm" id="c-<?= e($key) ?>" name="codigo" maxlength="60" required autocomplete="off" spellcheck="false"></div>
                        <div class="col-sm-5"><label class="form-label small mb-1" for="n-<?= e($key) ?>">Nombre</label><input class="form-control form-control-sm" id="n-<?= e($key) ?>" name="nombre" maxlength="120" required autocomplete="off"></div>
                        <div class="col-sm-3 d-flex align-items-end"><button class="btn-action btn-add btn-action--sm w-100" type="submit"><span class="btn-action__label">Agregar</span><span class="btn-action__icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span></button></div>
                        <div class="col-12"><label class="visually-hidden" for="d-<?= e($key) ?>">Descripción</label><input class="form-control form-control-sm" id="d-<?= e($key) ?>" name="descripcion" maxlength="500" placeholder="Descripción (opcional)…" autocomplete="off"></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-12">
        <div class="table-card">
            <div class="table-card__toolbar"><div><h2 class="table-card__title">Perfiles de importación</h2><span class="table-card__hint">Cómo se lee cada layout. Se detectan por encabezados y contenido, nunca por nombre de archivo.</span></div></div>
            <div class="table-responsive">
                <table class="table align-middle table-stack">
                    <caption class="visually-hidden">Perfiles de importación</caption>
                    <thead><tr><th scope="col">Perfil</th><th scope="col">Tipo documental</th><th scope="col">Descripción</th><th scope="col">Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($perfiles as $p): ?>
                        <tr>
                            <td><code><?= e($p['codigo']) ?></code><br><small><?= e($p['nombre']) ?></small></td>
                            <td><code><?= e($p['tipo_codigo']) ?></code></td>
                            <td class="small text-muted"><?= e($p['descripcion'] ?? '') ?></td>
                            <td><?= $p['implementado'] ? status_badge('ACTIVO', 'Implementado') : status_badge('PENDIENTE', 'No implementado') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
