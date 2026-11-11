<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$err = static fn (string $k): string => field_error($k, $errors[$k] ?? null);
$inv = static fn (string $k): string => field_invalid($k, $errors[$k] ?? null);
$val = static fn (string $k): string => (string) ($v[$k] ?? '');
$sel = static fn (string $k, string $x): string => $val($k) === $x ? ' selected' : '';
?>
<div class="page-header"><div><h1><?= e($title) ?></h1>
    <p class="page-header__sub">El reporte se generará con los permisos que usted tenga en ese momento. Los archivos se conservan el tiempo de retención y después sólo quedan sus datos de control.</p></div></div>
<?php if ($errors !== []): ?><div class="notice notice--error" role="alert"><i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i><div class="notice__body">Revise los campos marcados: cada uno indica qué corregir.</div></div><?php endif; ?>
<form method="post" action="/reportes/programados<?= $id !== null ? '/' . e($id) : '' ?>" class="card" novalidate>
    <div class="card-body row g-3">
        <?= $this->csrf() ?>
        <?php if ($id !== null): ?><input type="hidden" name="version" value="<?= e($val('version')) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label" for="nombre">Nombre</label>
            <input class="form-control" id="nombre" name="nombre"<?= $inv('nombre') ?> autocomplete="off" value="<?= e($val('nombre')) ?>" maxlength="120" required><?= $err('nombre') ?></div>
        <div class="col-12 col-md-6"><label class="form-label" for="tipo">Reporte</label>
            <select class="form-select" id="tipo" name="tipo"<?= $inv('tipo') ?>><?php foreach ($tipos as $k => [$t]): ?><option value="<?= e($k) ?>"<?= $sel('tipo', $k) ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('tipo') ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="formato">Formato</label>
            <select class="form-select" id="formato" name="formato"<?= $inv('formato') ?>><?php foreach (['XLSX', 'CSV', 'PDF'] as $f): ?><option value="<?= e($f) ?>"<?= $sel('formato', $f) ?>><?= e($f) ?></option><?php endforeach; ?></select><?= $err('formato') ?>
            <div class="form-text">PDF: resumen, pensiones, bancos, grupos e incidencias.</div></div>
        <div class="col-6 col-md-3"><label class="form-label" for="frecuencia">Frecuencia</label>
            <select class="form-select" id="frecuencia" name="frecuencia"<?= $inv('frecuencia') ?>><?php foreach (['MANUAL' => 'Manual', 'DIARIO' => 'Diaria', 'SEMANAL' => 'Semanal', 'QUINCENAL' => 'Quincenal (días 1 y 16)', 'MENSUAL' => 'Mensual'] as $f => $t): ?><option value="<?= e($f) ?>"<?= $sel('frecuencia', $f) ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('frecuencia') ?></div>
        <div class="col-4 col-md-2"><label class="form-label" for="hora">Hora</label>
            <input class="form-control" id="hora" name="hora"<?= $inv('hora') ?> type="number" min="0" max="23" value="<?= e($val('hora')) ?>"><?= $err('hora') ?></div>
        <div class="col-4 col-md-2"><label class="form-label" for="dia_semana">Día (semanal)</label>
            <select class="form-select" id="dia_semana" name="dia_semana"<?= $inv('dia_semana') ?>><option value="">—</option><?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $n => $t): ?><option value="<?= e($n) ?>"<?= $sel('dia_semana', (string) $n) ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('dia_semana') ?></div>
        <div class="col-4 col-md-2"><label class="form-label" for="dia_mes">Día (mensual)</label>
            <input class="form-control" id="dia_mes" name="dia_mes"<?= $inv('dia_mes') ?> type="number" min="1" max="28" value="<?= e($val('dia_mes')) ?>"><?= $err('dia_mes') ?></div>
        <div class="col-12 col-md-6"><label class="form-label" for="alcance">Alcance</label>
            <select class="form-select" id="alcance" name="alcance"><option value="RECIENTE"<?= $sel('alcance', 'RECIENTE') ?>>Cotejo terminado más reciente (primero el del lote oficial)</option><option value="LOTE"<?= $sel('alcance', 'LOTE') ?>>Un lote específico</option></select></div>
        <div class="col-12 col-md-6"><label class="form-label" for="lote_id">Lote (si el alcance es un lote)</label>
            <select class="form-select" id="lote_id" name="lote_id"<?= $inv('lote_id') ?>><option value="">—</option><?php foreach ($lotes as $l): ?><option value="<?= e($l['id']) ?>"<?= $sel('lote_id', (string) $l['id']) ?>><?= e($l['periodo'] . ' · lote ' . sprintf('%03d', $l['numero'])) ?></option><?php endforeach; ?></select><?= $err('lote_id') ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="retencion_dias">Retención</label>
            <select class="form-select" id="retencion_dias" name="retencion_dias"<?= $inv('retencion_dias') ?>><?php foreach ([30, 60, 90] as $d): ?><option value="<?= e($d) ?>"<?= $sel('retencion_dias', (string) $d) ?>><?= e($d) ?> días</option><?php endforeach; ?></select><?= $err('retencion_dias') ?></div>
        <?php if ($puedeSensible): ?>
        <div class="col-12 col-md-9 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="sensible" name="sensible"<?= $inv('sensible') ?> value="1"<?= !empty($v['sensible']) && $v['sensible'] !== 'f' ? ' checked' : '' ?>>
            <label class="form-check-label" for="sensible">Incluir RFC/CURP (sólo reportes de personas; la descarga exigirá el mismo permiso)</label></div><?= $err('sensible') ?></div>
        <?php endif; ?>
        <div class="col-12 d-flex gap-2">
            <button class="btn-action btn-confirm" type="submit"><span class="btn-action__label">Guardar</span><span class="btn-action__icon"><i class="bi bi-check2" aria-hidden="true"></i></span></button>
            <a class="btn-action btn-cancel" href="/reportes/programados"><span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span></a>
        </div>
    </div>
</form>
