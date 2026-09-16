<article class="page-card calculator-panel-card" id="calculatorPanelCard">

  <header class="page-card-hero page-card-hero--calculator">

    <button type="button" class="calculator-hero-close" id="calculatorPanelClose" aria-label="Fechar calculadora">&times;</button>

    <div class="calculator-panel-icon" aria-hidden="true"></div>

    <div class="page-card-hero-text">

      <span class="calculator-panel-kicker">Financeiro</span>

      <h1 id="calculatorModalTitle">Calculadora financeira</h1>

      <p class="section-lead">Selecione um documento para calcular repasses, revenue e prazos.</p>

    </div>

  </header>

  <div class="calculator-panel-body" id="calculatorPanelBody">

  <div id="calculatorAlert" class="alert alert-error hidden calculator-panel-alert" role="alert"></div>

  <div id="calculatorResultsToolbar" class="calculator-results-toolbar hidden" aria-live="polite">
    <span id="calculatorResultsSummary" class="calculator-results-summary"></span>
    <button type="button" class="btn btn-secondary btn-sm" id="calculatorEditParamsBtn">Alterar documento / parâmetros</button>
  </div>

  <section class="calculator-section calculator-section--input" id="calculatorDocumentSection">

    <div class="calculator-section-head">

      <h2>Documento</h2>

      <p class="section-lead">Escolha um documento do histórico recente.</p>

    </div>

    <label class="calculator-doc-label" for="calculatorDocumentSelect">

      <span>Documento / campanha</span>

      <select id="calculatorDocumentSelect">

        <option value="">Selecione…</option>

      </select>

    </label>

  </section>



  <section class="calculator-section calculator-section--params calculator-section--input hidden" id="calculatorParamsSection">

    <div class="calculator-section-head">

      <h2>Parâmetros da campanha</h2>

      <p class="section-lead">Estes valores serão aplicados a todas as redes e grupos do documento.</p>

    </div>

    <div class="calculator-params-grid">

      <label class="calculator-param-label" for="calculatorTipoProduto">

        <span>Tipo de produto</span>

        <input type="text" id="calculatorTipoProduto" value="PADRÃO" autocomplete="off">

      </label>

      <label class="calculator-param-label" for="calculatorTipoCompra">

        <span>Tipo de compra</span>

        <select id="calculatorTipoCompra">

          <option value="PROGRAMÁTICA">PROGRAMÁTICA</option>

          <option value="PI">PI</option>

        </select>

      </label>

    </div>

    <label class="calculator-resync-option" for="calculatorResyncFromSheet">

      <input type="checkbox" id="calculatorResyncFromSheet" value="1">

      <span>Re-sincronizar inventário da planilha original antes de calcular</span>

    </label>

    <div class="calculator-params-actions">

      <button type="button" class="btn btn-primary" id="calculatorRunBtn">Calcular</button>

    </div>

  </section>



  <section class="calculator-section calculator-section--output hidden" id="calculatorOutputSection">

    <div class="calculator-section-head">

      <h2>Resultado</h2>

      <p class="section-lead" id="calculatorOutputLead"></p>

    </div>

    <div id="calculatorGlobalSummary" class="calculator-global-summary hidden"></div>

    <p id="calculatorScrollHint" class="calculator-scroll-hint hidden"></p>

    <div class="calculator-output-scroll">

      <div id="calculatorOutput" class="calculator-output"></div>

    </div>

  </section>

  </div>

  <footer class="calculator-panel-footer">

    <button type="button" class="btn btn-secondary" id="calculatorFooterClose">Fechar</button>

  </footer>

</article>
