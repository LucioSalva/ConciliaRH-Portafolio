<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div>
        <h1>Configuración</h1>
        <p class="page-header__sub">Parámetros funcionales. La configuración de infraestructura (base de datos, rutas, llaves) vive en el archivo <code>.env</code> del servidor.</p>
    </div>
</div>
<div class="table-card">
    <div class="table-responsive">
        <table class="table align-middle table-stack">
            <caption class="visually-hidden">Parámetros de configuración</caption>
            <thead><tr><th scope="col">Parámetro</th><th scope="col">Valor</th><th scope="col" class="col-hide-md">Rango</th><th scope="col" class="col-hide-sm">Actualizado</th></tr></thead>
            <tbody>
            <?php foreach ($settings as $key => $s): ?>
                <tr>
                    <td>
                        <code><?= e($key) ?></code>
                        <?php if ($s['solo_god']): ?> <?= status_badge('SOLO_GOD') ?><?php endif; ?>
                        <div class="small text-muted"><?= e($s['descripcion']) ?></div>
                    </td>
                    <td>
                        <?php if ($canEdit): ?>
                            <form method="post" action="/configuracion" class="d-flex gap-2 align-items-center"
                                  data-confirm="Se modificará el parámetro <?= e($key) ?>. El cambio queda auditado." data-confirm-title="Modificar configuración">
                                <?= $this->csrf() ?>
                                <input type="hidden" name="clave" value="<?= e($key) ?>">
                                <label class="visually-hidden" for="cfg-<?= e(md5($key)) ?>">Valor de <?= e($key) ?></label>
                                <input id="cfg-<?= e(md5($key)) ?>" class="form-control form-control-sm" name="valor" value="<?= e($s['valor']) ?>" maxlength="500" autocomplete="off" inputmode="<?= in_array($s['tipo'], ['entero', 'decimal'], true) ? 'decimal' : 'text' ?>">
                                <button class="btn-action btn-edit btn-action--sm" type="submit" aria-label="Guardar <?= e($key) ?>"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span></button>
                            </form>
                        <?php else: ?>
                            <span class="fw-600 font-mono"><?= e($s['valor']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="col-hide-md small text-muted"><?= e($s['tipo']) ?><?= $s['minimo'] !== null ? ' · ' . e($s['minimo']) . '–' . e($s['maximo']) : '' ?></td>
                    <td class="col-hide-sm small text-muted nowrap"><?= e(fmt_datetime($s['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
