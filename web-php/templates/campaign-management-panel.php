<article class="page-card campaign-panel-card">
  <header class="page-card-hero page-card-hero--modal">
    <div class="campaign-panel-icon" aria-hidden="true"></div>
    <div class="page-card-hero-text">
      <span class="campaign-panel-kicker">Programática</span>
      <h1 id="campaignModalTitle">Gerenciamento de campanhas</h1>
      <p class="section-lead">Acompanhe aprovações, inventário de telas e status das campanhas.</p>
    </div>
  </header>

  <div id="campaignAlert" class="alert alert-error hidden campaign-panel-alert" role="alert"></div>

  <nav class="campaign-tabs" id="campaignTabs" aria-label="Status das campanhas"></nav>

  <div class="campaign-toolbar">
    <label class="campaign-search">
      <span class="campaign-search-icon" aria-hidden="true"></span>
      <input type="search" id="campaignSearchInput" placeholder="Buscar por nome ou id" autocomplete="off">
    </label>
  </div>

  <div id="campaignListWrap" class="campaign-list-wrap">
    <div class="campaign-empty"><p>Carregando campanhas…</p></div>
  </div>

  <div class="campaign-pagination hidden" id="campaignPagination">
    <button type="button" class="btn btn-secondary btn-sm" id="campaignPrevPage">Anterior</button>
    <span id="campaignPageInfo"></span>
    <button type="button" class="btn btn-secondary btn-sm" id="campaignNextPage">Próxima</button>
  </div>
</article>
