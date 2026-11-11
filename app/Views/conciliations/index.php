<?php
/** @var \ConciliaRH\Core\Template $this */
$this->layout('layouts/app', ['title' => $title]);
?>
<div class="page-header">
    <div>
        <h1>Cotejos</h1>
        <p class="page-header__sub">Todos los cotejos de cada quincena. Cada uno queda en el historial: un cotejo nuevo nunca reescribe el anterior.</p>
    </div>
</div>
<?php if ($lots !== []): ?>
    <div class="card mb-3"><div class="card-header card-header--accent">Cotejar un lote</div><div class="card-body">
        <form method="post" action="/conciliaciones" class="row g-2 align-items-end" data-busy>
            <?= $this->csrf() ?>
            <div class="col-md-6"><label class="form-label" for="lote_id">Quincena · lote</label>
                <select class="form-select" id="lote_id" name="lote_id" required>
                    <?php foreach ($lots as $l): ?>
                        <option value="<?= e($l['id']) ?>"<?= $l['estado_procesamiento'] === 'PROCESANDO' ? ' disabled' : '' ?>><?= e($l['periodo']) ?> · Lote <?= e(sprintf('%03d', $l['numero'])) ?> <?= $l['es_oficial'] ? '(OFICIAL)' : '(no oficial)' ?> · <?= e($l['archivos']) ?> archivo(s)<?= (int) $l['ejecuciones'] > 0 ? ' · ' . e($l['ejecuciones']) . ' cotejo(s)' : '' ?><?= $l['estado_procesamiento'] === 'PROCESANDO' ? ' · en proceso' : '' ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label" for="motivo">Motivo (obligatorio si el lote ya se cotejó)</label>
                <input class="form-control" id="motivo" name="motivo" maxlength="500" autocomplete="off"></div>
            <div class="col-md-2"><button class="btn-action btn-reconcile w-100" type="submit"><span class="btn-action__label">Cotejar</span><span class="btn-action__icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></span></button></div>
        </form>
        <p class="small text-muted mb-0 mt-2">El lote no necesita ser el oficial. La tolerancia vigente queda registrada en el cotejo.</p>
    </div></div>
<?php endif; ?>
<div class="table-card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><div class="empty-state__icon"><i class="bi bi-clipboard2-check" aria-hidden="true"></i></div><p class="empty-state__title">Aún no hay conciliaciones</p></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle"><caption class="visually-hidden">Historial de ejecuciones de conciliación</caption>
            <thead><tr><th scope="col">Quincena · lote</th><th scope="col">Cotejo</th><th scope="col">Estado</th><th scope="col" class="num col-hide-sm">% conciliado</th><th scope="col" class="col-hide-md">Solicitada</th><th scope="col" class="cell-actions"><span class="visually-hidden">Ver</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['periodo']) ?><br><a class="small" href="/lotes/<?= e($r['lote_id']) ?>">Lote <?= e(sprintf('%03d', $r['lote_numero'])) ?></a>
                        <?= status_badge($r['lote_oficial'] ? 'OFICIAL' : 'NO_OFICIAL') ?></td>
                    <td>n.º <?= e($r['numero']) ?><?= $r['igual_a_id'] ? '<br><small class="text-muted">mismos archivos que el cotejo #' . e($r['igual_a_id']) . '</small>' : '' ?></td>
                    <td><?= status_badge((string) $r['estado']) ?></td>
                    <td class="num col-hide-sm"><?= isset($r['resumen']['pct_conciliado']) ? e($r['resumen']['pct_conciliado']) . ' %' : '—' ?></td>
                    <td class="small col-hide-md"><?= e(fmt_datetime($r['created_at'])) ?> · <?= e($r['solicitado_por_usuario']) ?></td>
                    <td class="cell-actions"><a class="btn-action btn-view btn-action--sm" href="/conciliaciones/<?= e($r['id']) ?>"><span class="btn-action__label">Ver</span><span class="btn-action__icon"><i class="bi bi-eye" aria-hidden="true"></i></span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</div>
