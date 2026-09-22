<div class="confirm-modal-panel campaign-reject-panel">
  <h2 id="campaignUnitRejectTitle">Reprovar unidade</h2>
  <p class="section-lead" id="campaignUnitRejectLead"></p>
  <form id="campaignUnitRejectForm" class="stack-form">
    <label>Motivo da reprovação <span class="req">*</span>
      <textarea id="campaignUnitRejectReason" rows="3" required maxlength="2000" placeholder="Descreva o motivo"></textarea>
    </label>
    <label>Código substituto sugerido <span class="req">*</span>
      <input type="text" id="campaignUnitRejectReplacement" required maxlength="64" placeholder="Ex.: 0873-NAC-CURITIBA">
    </label>
    <div class="confirm-modal-actions">
      <button type="button" class="btn btn-secondary" id="campaignUnitRejectCancel">Cancelar</button>
      <button type="submit" class="btn btn-danger" id="campaignUnitRejectConfirm">
        <span class="btn-icon" aria-hidden="true"></span>
        Confirmar reprovação
      </button>
    </div>
  </form>
</div>
