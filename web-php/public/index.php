<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OC Maker — PHP</title>
  <link rel="icon" href="/maker/favicon.png" type="image/png">
  <link rel="stylesheet" href="/maker/assets/css/app.css">
</head>
<body>
  <header class="app-header">
    <div class="container" style="margin:0 auto;padding:0 1rem;max-width:1100px">
      <!--<span class="badge">Versão PHP 8 + MySQL</span>-->
      <div class="row">
        <div class="col-sm">
          <img src="/maker/assets/logo_converta.svg" alt="Converta Ads" style="height:90px;width:auto">
        </div>
        <div class="col right">
          <h1>OC Maker</h1>
        </div>
      </div>
      
      <p>Gerador de Informe / OdC em PDF e histórico no banco de dados.</p>
    </div>
  </header>

  <main class="container">
    <div id="alert" class="alert alert-error hidden" role="alert"></div>
    <div class="steps">
      <span class="step active" data-step="1">1. Planilha</span>
      <span class="step" data-step="2">2. Campanha</span>
      <span class="step" data-step="3">3. Configurar</span>
      <span class="step" data-step="4">4. PDF</span>
    </div>

    <div class="grid grid-main">
      <section>
        <article class="card">
          <h2>1. Enviar planilha Excel</h2>
          <div id="dropzone" class="dropzone" tabindex="0">
            <input type="file" id="fileInput" accept=".xlsx">
            <strong>Arraste o arquivo .xlsx ou clique para selecionar</strong>
            <span>Processado no servidor Apache com PHP 8.</span>
          </div>
          <p id="fileMeta" class="file-meta"></p>
        </article>

        <article id="formSection" class="card hidden" style="margin-top:1.25rem">
          <h2>2. Configuração do documento</h2>
          <div class="form-row">
            <div>
              <label for="campaign">Campanha</label>
              <select id="campaign"></select>
            </div>
            <div>
              <label for="documentTitle">Tipo de documento</label>
              <select id="documentTitle">
                <option value="Informe de Campanha">Informe de Campanha</option>
                <option value="Ordem de Compra">Ordem de Compra</option>
              </select>
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="documentId">ID do documento</label>
              <div style="display:flex;gap:0.5rem">
                <input type="text" id="documentId" placeholder="AAAAMM-XXXX">
                <button type="button" class="btn btn-secondary" id="newIdBtn">↻</button>
              </div>
            </div>
            <div>
              <label for="tipoVenda">Tipo de Venda</label>
              <select id="tipoVenda"></select>
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="planejador">Planejador / SSP</label>
              <select id="planejador"></select>
            </div>
            <div>
              <label for="tipoDeal">Tipo de DEAL (opcional)</label>
              <select id="tipoDeal"></select>
            </div>
          </div>
          <div class="form-row two">
            <div id="dealIdGroup" class="hidden">
              <label for="dealId">Deal ID (opcional)</label>
              <input type="text" id="dealId">
            </div>
            <div id="sspGroup" class="hidden">
              <label for="ocInforme">OC/Informe (SSP)</label>
              <input type="text" id="ocInforme">
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="prazoPagamento">Prazo para Pagamento</label>
              <select id="prazoPagamento"></select>
            </div>
            <div>
              <label for="prazoUnidade">Calcular em</label>
              <select id="prazoUnidade">
                <option value="Dias">Dias</option>
                <option value="DFM" selected>DFM (dias após fim do mês)</option>
              </select>
            </div>
          </div>
          <p id="prazoPreview" class="file-meta"></p>
          <div class="checkbox-row">
            <input type="checkbox" id="checking" checked>
            <label for="checking">Checking Fotográfico</label>
          </div>
          <div class="checkbox-row">
            <input type="checkbox" id="relatorios">
            <label for="relatorios">Relatórios Adicionais</label>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-primary" id="generateBtn" disabled>Gerar PDF</button>
          </div>
        </article>
      </section>

      <aside>
        <article class="card summary">
          <h2>Resumo</h2>
          <div id="summary"><p class="file-meta">Carregue uma planilha para começar.</p></div>
        </article>
        <article class="card" style="margin-top:1.25rem">
          <h2>Histórico recente</h2>
          <div id="history"><p class="file-meta">Carregando…</p></div>
        </article>
      </aside>
    </div>
  </main>

  <footer class="app-footer">OC Maker — versão PHP · Apache + MariaDB/MySQL</footer>

  <div id="appLoading" class="app-loading hidden" aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="loadingTitle">
    <div class="app-loading-panel">
      <div class="loading-spinner" aria-hidden="true"></div>
      <p id="loadingTitle" class="loading-title">Aguarde</p>
      <p class="loading-message">Processando…</p>
      <p class="loading-hint">Estamos lendo dados ou gerando o documento. A tela ficará bloqueada até concluir — não feche esta página.</p>
    </div>
  </div>

  <script src="/maker/assets/js/loading.js"></script>
  <script src="/maker/assets/js/financials.js"></script>
  <script src="/maker/assets/js/app.js"></script>
</body>
</html>
