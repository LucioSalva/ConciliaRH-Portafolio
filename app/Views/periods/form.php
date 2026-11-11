<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
$err = static fn (string $f): ?string => $errors[$f] ?? null;
$oldAnio = $this->old('anio', (string) $currentYear);
?>
<div class="page-header">
    <div>
        <h1>Nueva quincena</h1>
        <p class="page-header__sub">Si deja las fechas en blanco se usan las de la quincena (1–15 o 16–fin de mes).</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-action btn-cancel" href="/periodos"><span class="btn-action__label">Volver</span><span class="btn-action__icon"><i class="bi bi-arrow-left" aria-hidden="true"></i></span></a>
    </div>
</div>
<?php if ($err('periodo')): ?>
    <div class="notice notice--error" role="alert"><i class="bi bi-x-octagon-fill notice__icon" aria-hidden="true"></i><div class="notice__body"><?= e($err('periodo')) ?></div></div>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="post" action="/periodos" class="row g-3" novalidate data-busy>
            <?= $this->csrf() ?>
            <div class="col-6 col-md-3">
                <label class="form-label" for="anio">Año</label>
                <input class="form-control<?= $err('anio') ? ' is-invalid' : '' ?>" id="anio" name="anio" type="number" min="2000" max="2100" required value="<?= e($oldAnio) ?>"<?= field_invalid('anio', $err('anio')) ?>>
                <?= field_error('anio', $err('anio')) ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="mes">Mes</label>
                <select class="form-select<?= $err('mes') ? ' is-invalid' : '' ?>" id="mes" name="mes" required<?= field_invalid('mes', $err('mes')) ?>>
                    <?php foreach ($meses as $n => $m): ?><option value="<?= e($n) ?>"<?= $this->old('mes', date('n')) === (string) $n ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('mes', $err('mes')) ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="quincena">Quincena</label>
                <select class="form-select" id="quincena" name="quincena" required>
                    <option value="1"<?= $this->old('quincena', '1') === '1' ? ' selected' : '' ?>>1ra quincena</option>
                    <option value="2"<?= $this->old('quincena') === '2' ? ' selected' : '' ?>>2da quincena</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="tipo">Tipo de nómina</label>
                <select class="form-select" id="tipo" name="tipo">
                    <option value="ORDINARIA">Ordinaria</option>
                    <option value="EXTRAORDINARIA"<?= $this->old('tipo') === 'EXTRAORDINARIA' ? ' selected' : '' ?>>Extraordinaria</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="descripcion">Descripción <small class="text-muted">(opcional; en blanco se usa la del ejemplo)</small></label>
                <input class="form-control<?= $err('descripcion') ? ' is-invalid' : '' ?>" id="descripcion" name="descripcion" maxlength="150" value="<?= e($this->old('descripcion')) ?>" placeholder="<?= e(\ConciliaRH\Domain\Periods\PeriodCalendar::label((int) $oldAnio, (int) $this->old('mes', date('n')), (int) $this->old('quincena', '1'), (string) $this->old('tipo', 'ORDINARIA'))) ?>" autocomplete="off"<?= field_invalid('descripcion', $err('descripcion')) ?>>
                <?= field_error('descripcion', $err('descripcion')) ?>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="fecha_inicio">Fecha inicio</label>
                <input class="form-control<?= $err('fechas') ? ' is-invalid' : '' ?>" id="fecha_inicio" name="fecha_inicio" type="date" value="<?= e($this->old('fecha_inicio')) ?>"<?= field_invalid('fechas', $err('fechas')) ?>>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="fecha_fin">Fecha fin</label>
                <input class="form-control<?= $err('fechas') ? ' is-invalid' : '' ?>" id="fecha_fin" name="fecha_fin" type="date" value="<?= e($this->old('fecha_fin')) ?>"<?= field_invalid('fechas', $err('fechas')) ?>>
            </div>
            <?php if ($err('fechas')): ?><div class="col-12"><?= field_error('fechas', $err('fechas')) ?></div><?php endif; ?>
            <div class="col-12">
                <button class="btn-action btn-add" type="submit"><span class="btn-action__label">Crear quincena</span><span class="btn-action__icon"><i class="bi bi-calendar-plus" aria-hidden="true"></i></span></button>
            </div>
        </form>
    </div>
</div>
