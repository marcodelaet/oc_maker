<article class="page-card activity-log-panel-card">
  <header class="page-card-hero page-card-hero--activity-log">
    <div class="activity-log-icon" aria-hidden="true"></div>
    <div class="page-card-hero-text">
      <span class="activity-log-kicker">Administração</span>
      <h1 id="activityLogModalTitle">Log de eventos</h1>
      <p class="section-lead">Registro de ações dos usuários e convidados no sistema.</p>
      <p class="activity-log-count" id="activityLogCount"></p>
    </div>
  </header>

  <div id="activityLogAlert" class="alert alert-error hidden activity-log-alert" role="alert"></div>

  <section class="activity-log-section">
    <div class="activity-log-toolbar">
      <label class="activity-log-search">
        <span class="sr-only">Buscar no log</span>
        <input type="search" id="activityLogSearch" placeholder="Buscar usuário, ação, IP…" autocomplete="off">
      </label>
      <label class="activity-log-filter">
        <span>Ação</span>
        <select id="activityLogActionFilter">
          <option value="">Todas</option>
        </select>
      </label>
      <div class="activity-log-toolbar-actions">
        <div class="activity-log-columns-wrap">
          <button type="button" class="btn btn-secondary btn-sm" id="activityLogColumnsBtn" aria-expanded="false" aria-controls="activityLogColumnsMenu">Colunas</button>
          <div id="activityLogColumnsMenu" class="activity-log-columns-menu hidden" role="group" aria-label="Colunas visíveis"></div>
        </div>
        <div class="icon-btn-group activity-log-export-group" role="group" aria-label="Exportar log">
          <button type="button" class="icon-btn icon-btn--table activity-log-toolbar-icon icon-btn--brand" id="activityLogExportCsv" aria-label="Exportar CSV" title="Exportar CSV"></button>
          <button type="button" class="icon-btn icon-btn--table activity-log-toolbar-icon icon-btn--brand" id="activityLogExportXlsx" aria-label="Exportar Excel" title="Exportar Excel"></button>
          <button type="button" class="icon-btn icon-btn--table activity-log-toolbar-icon icon-btn--brand" id="activityLogExportPdf" aria-label="Exportar PDF" title="Exportar PDF"></button>
        </div>
      </div>
    </div>

    <div id="activityLogTableWrap" class="activity-log-table-wrap">
      <p class="file-meta">Carregando…</p>
    </div>

    <div class="activity-log-pagination">
      <button type="button" class="btn btn-secondary btn-sm hidden" id="activityLogLoadMore">Carregar mais</button>
      <span class="file-meta" id="activityLogPageMeta"></span>
    </div>
  </section>

  <section class="activity-log-section activity-log-section--detail hidden" id="activityLogDetailSection">
    <div class="activity-log-section-head activity-log-section-head--row">
      <div>
        <h2>Detalhes do evento</h2>
        <p class="section-lead" id="activityLogDetailLead"></p>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" id="activityLogDetailClose">Fechar</button>
    </div>
    <dl class="activity-log-detail-grid" id="activityLogDetailGrid"></dl>
  </section>
</article>
