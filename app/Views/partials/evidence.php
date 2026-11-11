<?php
/**
 * Evidencia de identidad (docs/DESIGN_SYSTEM.md, «Evidencia»): nivel con TRES marcas llenas/vacías + palabra + razón.
 * No depende del color: FUERTE ●●●, MEDIA ●●○, BAJA ●○○, SIN ○○○.
 * @var string $nivel FUERTE|MEDIA|BAJA|SIN_EVIDENCIA  @var string $razon  @var int $senales  @var bool $compact
 */
$n = match ($nivel) { 'FUERTE', 'ALTA' => 3, 'MEDIA' => 2, 'BAJA' => 1, default => 0 };
$txt = match ($n) { 3 => 'Fuerte', 2 => 'Media', 1 => 'Baja', default => 'Sin evidencia' };
?>
<span class="evidence evidence--<?= e(strtolower($txt === 'Sin evidencia' ? 'sin' : $txt)) ?>">
    <span class="evidence__meter" aria-hidden="true"><?php for ($i = 1; $i <= 3; $i++): ?><span class="evidence__dot<?= $i <= $n ? ' is-on' : '' ?>"></span><?php endfor; ?></span>
    <span class="evidence__level">Evidencia <?= e(mb_strtolower($txt)) ?><?= !empty($senales) ? ' · ' . (int) $senales . ' señal(es)' : '' ?></span>
    <?php if (empty($compact) && ($razon ?? '') !== ''): ?><span class="evidence__reason"><?= e($razon) ?></span><?php endif; ?>
</span>
