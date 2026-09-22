<article class="page-card campaign-approved-card" id="campaignApprovedCard">
  <header class="page-card-hero page-card-hero--modal">
    <div class="campaign-panel-icon" id="campaignApprovedHeroIcon" aria-hidden="true"></div>
    <div class="page-card-hero-text">
      <span class="campaign-panel-kicker">Campanha aprovada</span>
      <h2 id="campaignApprovedTitle">Campanha</h2>
      <p class="section-lead" id="campaignApprovedMeta"></p>
    </div>
  </header>

  <div id="campaignApprovedAlert" class="alert alert-error hidden campaign-panel-alert" role="alert"></div>
  <p id="campaignApprovedReadOnlyBanner" class="campaign-approved-readonly-banner hidden" role="status"></p>
  <p id="campaignDraftStatus" class="campaign-draft-status hidden" role="status"></p>

  <div class="campaign-analyze-toolbar">
    <button type="button" class="btn btn-secondary btn-sm" id="campaignApprovedOfflineListBtn">
      <span class="btn-icon" aria-hidden="true"></span>
      Telas offline para suporte
    </button>
  </div>

  <nav class="campaign-tabs campaign-approved-tabs" id="campaignApprovedTabs" aria-label="Configuração da campanha">
    <button type="button" class="campaign-tab campaign-tab--active" data-tab="deals">Deals</button>
    <button type="button" class="campaign-tab" data-tab="creatives">Criativos</button>
    <button type="button" class="campaign-tab" data-tab="playbook">Roteiro CMS</button>
    <button type="button" class="campaign-tab" data-tab="control">Controle</button>
  </nav>

  <section class="campaign-approved-section" id="campaignApprovedDealsSection">
    <div class="campaign-approved-toolbar">
      <label class="campaign-slots-field">Slots da campanha (Planning)
        <input type="number" id="campaignSlotsInput" min="1" max="99" value="1">
      </label>
      <div class="icon-btn-group campaign-approved-actions" role="group" aria-label="Ações de deal">
        <button type="button" class="icon-btn icon-btn--brand" id="campaignSlotsSaveBtn" title="Salvar slots" aria-label="Salvar slots"></button>
        <a id="campaignPlanningLink" class="icon-btn icon-btn--brand" href="#" target="_blank" rel="noopener" title="Abrir Planning" aria-label="Abrir Planning"></a>
        <button type="button" class="icon-btn icon-btn--brand" id="campaignAddDealBtn" title="Novo Deal" aria-label="Novo Deal"></button>
      </div>
    </div>
    <div id="campaignDealsWrap" class="campaign-deals-wrap"></div>
    <div id="campaignDealEditor" class="campaign-deal-editor hidden"></div>
  </section>

  <section class="campaign-approved-section hidden" id="campaignApprovedCreativesSection">
    <div id="campaignCreativesDropZone" class="campaign-creatives-dropzone" tabindex="0" role="button" aria-label="Enviar criativos">
      <input type="file" id="campaignCreativeInput" accept="image/*,video/mp4,video/webm" multiple hidden>
      <div id="campaignCreativesGallery" class="campaign-creatives-gallery"></div>
    </div>
  </section>

  <section class="campaign-approved-section hidden" id="campaignApprovedPlaybookSection">
    <div id="campaignPlaybookWrap" class="campaign-playbook-wrap"></div>
  </section>

  <section class="campaign-approved-section hidden" id="campaignApprovedControlSection">
    <div id="campaignControlWrap" class="campaign-control-wrap"></div>
    <input type="file" id="campaignDealReportInput" accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
  </section>

  <div id="campaignCreativePreviewModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignCreativePreviewTitle">
    <div class="account-modal-panel campaign-creative-preview-panel">
      <button type="button" class="account-modal-close" id="campaignCreativePreviewClose" aria-label="Fechar">&times;</button>
      <header class="campaign-creative-preview-head">
        <h3 id="campaignCreativePreviewTitle">Criativo</h3>
        <p id="campaignCreativePreviewMeta" class="campaign-creative-preview-meta"></p>
      </header>
      <div class="campaign-creative-preview-stage">
        <div class="campaign-creative-preview-tv">
          <div class="campaign-creative-preview-bezel">
            <div id="campaignCreativePreviewScreen" class="campaign-creative-preview-screen">
              <div id="campaignCreativePreviewContent" class="campaign-creative-preview-content"></div>
              <div id="campaignCreativePreviewFooter" class="campaign-creative-preview-footer hidden">
                <span id="campaignCreativePreviewFooterLabel" class="campaign-creative-preview-footer-label"></span>
              </div>
            </div>
          </div>
          <div class="campaign-creative-preview-stand" aria-hidden="true"></div>
        </div>
      </div>
    </div>
  </div>

  <div id="campaignAdmoohUnmatchedModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignAdmoohUnmatchedTitle">
    <div class="account-modal-panel campaign-admooh-unmatched-panel">
      <button type="button" class="account-modal-close" id="campaignAdmoohUnmatchedClose" aria-label="Fechar">&times;</button>
      <header class="campaign-admooh-unmatched-head">
        <h3 id="campaignAdmoohUnmatchedTitle">Telas não identificadas</h3>
        <p class="campaign-admooh-unmatched-lead">
          O relatório Admooh contém telas que o sistema não conseguiu vincular automaticamente.
          Escolha o que fazer com cada uma antes de concluir a importação.
        </p>
      </header>
      <div id="campaignAdmoohUnmatchedList" class="campaign-admooh-unmatched-list"></div>
      <div class="confirm-modal-actions">
        <button type="button" class="btn btn-secondary" id="campaignAdmoohUnmatchedCancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="campaignAdmoohUnmatchedConfirm">Importar com resoluções</button>
      </div>
    </div>
  </div>

  <div id="campaignReportColumnsModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignReportColumnsTitle">
    <div class="confirm-modal-panel campaign-report-columns-panel">
      <h3 id="campaignReportColumnsTitle">Escolha as colunas para exibir</h3>
      <p class="campaign-report-columns-lead">Colunas de agrupamento alteram como os valores são somados na grade.</p>
      <div id="campaignReportColumnsList" class="campaign-report-columns-list"></div>
      <div class="confirm-modal-actions">
        <button type="button" class="btn btn-secondary" id="campaignReportColumnsCancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="campaignReportColumnsAccept">Aceitar</button>
      </div>
    </div>
  </div>
</article>
