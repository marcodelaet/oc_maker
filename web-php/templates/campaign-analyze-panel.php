<article class="page-card campaign-analyze-card" id="campaignAnalyzeCard">
  <header class="page-card-hero page-card-hero--modal">
    <div class="campaign-panel-icon" id="campaignAnalyzeHeroIcon" aria-hidden="true"></div>
    <div class="page-card-hero-text">
      <span class="campaign-panel-kicker">Análise de inventário</span>
      <h2 id="campaignAnalyzeTitle">Campanha</h2>
      <p class="section-lead" id="campaignAnalyzeMeta"></p>
    </div>
  </header>

  <div id="campaignAnalyzeAlert" class="alert alert-error hidden campaign-panel-alert" role="alert"></div>

  <div class="campaign-analyze-toolbar">
    <button type="button" class="btn btn-secondary btn-sm" id="campaignOfflineListBtn">
      <span class="btn-icon" aria-hidden="true"></span>
      Telas offline para suporte
    </button>
  </div>

  <div id="campaignUnitsWrap" class="campaign-units-wrap"></div>

  <footer class="campaign-analyze-footer">
    <button type="button" class="btn btn-secondary" id="campaignAnalyzeSaveBtn">
      <span class="btn-icon" aria-hidden="true"></span>
      Salvar progresso
    </button>
    <div class="campaign-analyze-footer-actions">
      <button type="button" class="btn btn-danger" id="campaignRejectBtn">
        <span class="btn-icon" aria-hidden="true"></span>
        Reprovar campanha
      </button>
      <button type="button" class="btn btn-primary" id="campaignApproveBtn">
        <span class="btn-icon" aria-hidden="true"></span>
        Aprovar campanha
      </button>
    </div>
  </footer>
</article>
