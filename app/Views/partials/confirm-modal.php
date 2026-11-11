<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-describedby="confirmModalBody" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header--wine">
                <h2 class="modal-title fs-5" id="confirmModalTitle"><i class="bi bi-check2-circle modal-title__icon" data-confirm-icon aria-hidden="true"></i><span data-confirm-title-slot>Confirmar operación</span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="confirmModalBody" data-confirm-body-slot></p>
                <p class="modal-note" data-confirm-note hidden></p>
                <div data-confirm-reason-wrap hidden>
                    <label for="confirmReason" class="form-label">Motivo (obligatorio)</label>
                    <textarea id="confirmReason" class="form-control" rows="3" maxlength="500" aria-describedby="confirmReasonHelp"></textarea>
                    <div class="form-text" id="confirmReasonHelp">Queda registrado en la auditoría junto con la operación.</div>
                    <div class="invalid-feedback" id="confirmReasonError" hidden>Escriba un motivo de al menos 5 caracteres.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action btn-cancel" data-bs-dismiss="modal">
                    <span class="btn-action__label">Cancelar</span><span class="btn-action__icon"><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                </button>
                <button type="button" class="btn-action btn-confirm" data-confirm-accept>
                    <span class="btn-action__label" data-confirm-accept-label>Confirmar</span><span class="btn-action__icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                </button>
            </div>
        </div>
    </div>
</div>
