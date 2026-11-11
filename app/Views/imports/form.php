<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$err = static fn (string $f): ?string => $errors[$f] ?? null;
$selLot = (int) ($this->old('lote_id') !== '' ? $this->old('lote_id') : $selectedLot);
$selType = $this->old('tipo_documento_id');
$selOrigin = $this->old('origen_administrativo_id', (string) $defaultOrigin);
$grouped = [];
foreach ($lots as $l) {
    $grouped[$l['periodo_descripcion']][] = $l;
}
$maxMb = number_format($maxBytes / 1048576, 0);
$isDupConfirm = is_array($uploadError) && $uploadError['code'] === 'DUPLICADO_EXACTO_REQUIERE_CONFIRMACION';
?>
<div class="page-header">
    <div>
        <h1>Cargar archivo</h1>
        <p class="page-header__sub">Formatos: .xls, .xlsx, .xlsm (máx. <?= e($maxMb) ?> MB). Las macros de los .xlsm nunca se ejecutan: el archivo se trata sólo como datos.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/importaciones"><span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
    </div>
</div>

<?php if (is_array($uploadError)): ?>
    <div class="notice <?= $isDupConfirm ? 'notice--warning' : 'notice--error' ?> upload-error" role="alert">
        <i class="bi <?= $isDupConfirm ? 'bi-files' : 'bi-file-earmark-x-fill' ?> notice__icon" aria-hidden="true"></i>
        <div class="notice__body">
            <p class="notice__title"><?= $isDupConfirm ? 'Archivo duplicado' : 'Carga rechazada' ?> <code class="upload-error__code"><?= e($uploadError['code']) ?></code></p>
            <?= e($uploadError['message']) ?>
            <?php if (!empty($uploadError['donde'])): ?><br><small>Ya existe en: <strong><?= e($uploadError['donde']) ?></strong>.</small><?php endif; ?>
            <?php if ($isDupConfirm): ?><br><small>Seleccione de nuevo el archivo y marque la confirmación para cargarlo en este lote.</small><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($lots === []): ?>
    <div class="empty-state card">
        <div class="empty-state__icon"><i class="bi bi-collection" aria-hidden="true"></i></div>
        <p class="empty-state__title">No hay lotes abiertos</p>
        <p class="mb-0">Cree un periodo y un lote antes de cargar archivos. <a href="/periodos">Ir a periodos</a>.</p>
    </div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <form method="post" action="/importaciones" enctype="multipart/form-data" class="row g-3" novalidate data-busy data-upload-form>
            <?= $this->csrf() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= e($maxBytes) ?>">
            <div class="col-md-6">
                <label class="form-label" for="lote_id">Quincena · lote</label>
                <select class="form-select" id="lote_id" name="lote_id" required>
                    <?php foreach ($grouped as $periodo => $items): ?>
                        <optgroup label="<?= e($periodo) ?>">
                            <?php foreach ($items as $l): ?>
                                <option value="<?= e($l['id']) ?>"<?= $selLot === (int) $l['id'] ? ' selected' : '' ?>>Lote <?= e(sprintf('%03d', $l['numero'])) ?> · <?= e($l['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Sólo se muestran lotes abiertos de quincenas abiertas.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="tipo_documento_id">Tipo documental</label>
                <select class="form-select" id="tipo_documento_id" name="tipo_documento_id" required>
                    <option value="">Seleccione…</option>
                    <?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>"<?= $selType === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="origen_administrativo_id">Origen administrativo</label>
                <select class="form-select" id="origen_administrativo_id" name="origen_administrativo_id" required>
                    <?php foreach ($origins as $o): ?><option value="<?= e($o['id']) ?>"<?= $selOrigin === (string) $o['id'] ? ' selected' : '' ?>><?= e($o['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="archivo">Archivo</label>
                <div class="dropzone" data-dropzone data-max-bytes="<?= e($maxBytes) ?>">
                    <i class="bi bi-cloud-arrow-up dropzone__icon" aria-hidden="true"></i>
                    <p class="dropzone__text mb-2">Arrastre aquí el archivo o selecciónelo:</p>
                    <input class="form-control dropzone__input" type="file" id="archivo" name="archivo" required
                           accept=".xls,.xlsx,.xlsm,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroEnabled.12"
                           aria-describedby="archivo-ayuda archivo-estado">
                    <p class="form-text mb-0" id="archivo-ayuda">.xls, .xlsx o .xlsm · máximo <?= e($maxMb) ?> MB.</p>
                    <p class="dropzone__status mb-0" id="archivo-estado" aria-live="polite"></p>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label" for="observaciones">Observaciones</label>
                <textarea class="form-control<?= $err('observaciones') ? ' is-invalid' : '' ?>" id="observaciones" name="observaciones" rows="2" maxlength="2000"><?= e($this->old('observaciones')) ?></textarea>
            </div>
            <?php if ($isDupConfirm): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmar_duplicado" name="confirmar_duplicado" value="1">
                        <label class="form-check-label" for="confirmar_duplicado">Confirmo que deseo cargar este archivo idéntico también en este lote (quedará marcado como <strong>duplicado exacto</strong> y enlazado al existente).</label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <button class="btn-action btn-import" type="submit"><span class="btn-action__label">Cargar y verificar</span><span class="btn-action__icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></span></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
