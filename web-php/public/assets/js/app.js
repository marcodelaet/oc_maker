const API = {
  parse: '/maker/api/parse.php',
  campaign: '/maker/api/campaign.php',
  fees: '/maker/api/fees.php',
  generate: '/maker/api/generate.php',
  history: '/maker/api/history.php',
  document: '/maker/api/document.php',
};

const { withLoading } = AppLoading;
const { buildSummaryPayload } = OcFinancials;

async function fetchJson(url, options) {
  const res = await fetch(url, options);
  const contentType = res.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    const text = await res.text();
    const snippet = text.replace(/\s+/g, ' ').slice(0, 180);
    throw new Error(
      `Resposta inválida do servidor (esperado JSON, recebido HTML). ` +
      `Verifique se o Apache aponta para a pasta public/ ou use: ` +
      `php -S localhost:8080 -t public public/router.php — ${snippet}`
    );
  }
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Erro na requisição');
  return data;
}

const PLANEJADORES = ['Admooh', 'Adsmovil', 'Magnite', 'Hivestack', 'Outcon'];
const TIPOS_VENDA = ['Agência', 'SSP', 'Direta'];
const TIPOS_DEAL = ['', 'PG', 'PD', 'PMP'];
const PRAZO_OPCOES = [15, 30, 60, 70, 90];

const state = {
  file: null,
  campaigns: [],
  spreadsheetKey: null,
  campaignCache: {},
  campaignSnapshot: null,
  fees: {},
  sourceDocumentId: null,
  editingFromHistory: false,
};
const el = (id) => document.getElementById(id);

function setStep(step) {
  document.querySelectorAll('.step').forEach((s) => {
    const n = Number(s.dataset.step);
    s.classList.toggle('active', n === step);
    s.classList.toggle('done', n < step);
  });
}

function showError(msg) {
  el('alert').textContent = msg;
  el('alert').classList.remove('hidden');
}

function hideError() {
  el('alert').classList.add('hidden');
}

function formatBRL(v) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
}

function generateDocumentId() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let suffix = '';
  const arr = crypto.getRandomValues(new Uint8Array(4));
  for (let i = 0; i < 4; i++) suffix += chars[arr[i] % chars.length];
  const d = new Date();
  return `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}-${suffix}`;
}

function pdfTimestamp(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const h = String(date.getHours()).padStart(2, '0');
  const min = String(date.getMinutes()).padStart(2, '0');
  const s = String(date.getSeconds()).padStart(2, '0');
  return `${y}${m}${d}_${h}${min}${s}`;
}

function removeAccents(text) {
  return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

function sanitizeFilenamePart(text, fallback) {
  return removeAccents((text || fallback).trim()).replace(/[\\/:*?"<>|]/g, '_');
}

function buildPdfFilename(campanha, anunciante, date = new Date()) {
  const camp = sanitizeFilenamePart(campanha, 'campanha');
  const adv = sanitizeFilenamePart(anunciante, 'anunciante');
  return `${camp} - ${adv} - ${pdfTimestamp(date)}.pdf`;
}

function getFormData() {
  const fd = new FormData();
  if (state.spreadsheetKey) {
    fd.append('spreadsheetKey', state.spreadsheetKey);
  } else if (state.file) {
    fd.append('file', state.file);
  }
  if (state.sourceDocumentId) {
    fd.append('sourceDocumentId', String(state.sourceDocumentId));
  }
  fd.append('adsId', el('campaign').value);
  fd.append('documentId', el('documentId').value || generateDocumentId());
  fd.append('documentTitle', el('documentTitle').value);
  fd.append('tipoVenda', el('tipoVenda').value);
  fd.append('tipoDeal', el('tipoDeal').value);
  fd.append('planejadorSsp', el('planejador').value);
  fd.append('dealId', el('dealId').value.trim());
  fd.append('ocInformeSsp', el('ocInforme').value.trim());
  fd.append('checkingFotografico', el('checking').checked ? '1' : '0');
  fd.append('relatoriosAdicionais', el('relatorios').checked ? '1' : '0');
  fd.append('prazoPagamento', el('prazoPagamento').value);
  fd.append('prazoUnidade', el('prazoUnidade').value);
  return fd;
}

function toggleConditionalFields() {
  el('sspGroup').classList.toggle('hidden', el('tipoVenda').value !== 'SSP');
  el('dealIdGroup').classList.toggle('hidden', !el('dealId').value.trim());
}

function formatPrazoPagamento(terminoIso, prazoDias, unidade) {
  if (!terminoIso) return '—';
  const [y, m, d] = terminoIso.split('-').map(Number);
  const termino = new Date(y, m - 1, d, 12, 0, 0);
  let payment;
  if (unidade === 'DFM') {
    payment = new Date(y, m, 0, 12, 0, 0);
    payment.setDate(payment.getDate() + Number(prazoDias));
  } else {
    payment = new Date(termino);
    payment.setDate(payment.getDate() + Number(prazoDias));
  }
  const ds = String(payment.getDate()).padStart(2, '0');
  const ms = String(payment.getMonth() + 1).padStart(2, '0');
  return `Prazo para Pagamento: ${prazoDias} dias (${ds}/${ms}/${payment.getFullYear()})`;
}

function updatePrazoPreview(termino) {
  if (!el('prazoPreview')) return;
  el('prazoPreview').textContent = termino
    ? formatPrazoPagamento(termino, el('prazoPagamento').value, el('prazoUnidade').value)
    : '';
}

function populateCampaigns() {
  const select = el('campaign');
  select.innerHTML = '';
  for (const c of state.campaigns) {
    const opt = document.createElement('option');
    opt.value = c.ads_id;
    opt.textContent = `${c.campanha} — ${c.anunciante || 'Sem anunciante'} (${c.ads_id})`;
    select.appendChild(opt);
  }
}

function updateSummary(data) {
  const panel = el('summary');
  if (!data) {
    panel.innerHTML = "<p class='file-meta'>Carregue uma planilha para começar.</p>";
    return;
  }
  const { campaign, financials: fin } = data;
  const fmt = (iso) => {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  };
  const banner = panel.querySelector('.history-banner');
  const bannerHtml = banner ? banner.outerHTML : '';
  panel.innerHTML = `${bannerHtml}
    <dl>
      <dt>Campanha</dt><dd>${campaign.campanha}</dd>
      <dt>Anunciante</dt><dd>${campaign.anunciante}</dd>
      <dt>Período</dt><dd>${fmt(campaign.inicio)} — ${fmt(campaign.termino)}</dd>
      <dt>Lojas</dt><dd>${campaign.inventoryCount}</dd>
      <dt>Total Inserções</dt><dd>${fin.totals.insercoes.toLocaleString('pt-BR')}</dd>
      <dt>Total Impactos</dt><dd>${fin.totals.impactos.toLocaleString('pt-BR')}</dd>
      <dt>Budget Bruto</dt><dd>${formatBRL(fin.totals.bruto)}</dd>
      <dt>Budget Líquido</dt><dd>${formatBRL(fin.totals.liquido)}</dd>
    </dl>
    <div class="highlight">
      <dl>
        <dt>TECH FEE</dt><dd>${fin.feePercent}% (${formatBRL(fin.feeValue)})</dd>
        <dt>Valor Publisher</dt><dd>${formatBRL(fin.valorPublisher)}</dd>
        <dt>CPM Médio</dt><dd>${formatBRL(fin.cpm)}</dd>
      </dl>
    </div>`;
  updatePrazoPreview(campaign.termino);
}

async function loadHistory() {
  const box = el('history');
  return withLoading('Carregando histórico…', async () => {
  try {
    const data = await fetchJson(API.history);
    if (!data.documents?.length) {
      box.innerHTML = '<p class="file-meta">Nenhum documento gerado ainda.</p>';
      return;
    }
    box.innerHTML = `<table class="history-table"><thead><tr>
      <th>ID</th><th>Campanha</th><th>Anunciante</th><th>Ações</th>
    </tr></thead><tbody>${data.documents.map((d) => `
      <tr>
        <td>${d.document_id}</td>
        <td>${d.campanha || '—'}</td>
        <td>${d.anunciante || '—'}</td>
        <td class="history-actions">
          <button type="button" class="btn-link" data-action="summary" data-id="${d.id}">Resumo</button>
          <button type="button" class="btn-link" data-action="edit" data-id="${d.id}">Editar</button>
        </td>
      </tr>`).join('')}</tbody></table>`;

    box.querySelectorAll('[data-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.id);
        if (btn.dataset.action === 'summary') {
          showDocumentSummary(id).catch((e) => showError(e.message));
        } else {
          loadDocumentForEdit(id).catch((e) => showError(e.message));
        }
      });
    });
  } catch (err) {
    box.innerHTML = `<p class="file-meta">Histórico indisponível: ${err.message}</p>`;
  }
  });
}

function applyDocumentToForm(doc) {
  el('documentId').value = doc.document_id || '';
  el('documentTitle').value = doc.document_title || 'Informe de Campanha';
  el('tipoVenda').value = doc.tipo_venda || 'SSP';
  el('planejador').value = doc.planejador_ssp || PLANEJADORES[0];
  el('tipoDeal').value = doc.tipo_deal || '';
  el('dealId').value = doc.deal_id || '';
  el('ocInforme').value = doc.oc_informe_ssp || '';
  el('checking').checked = doc.checking_fotografico !== false;
  el('relatorios').checked = !!doc.relatorios_adicionais;
  el('prazoPagamento').value = String(doc.prazo_pagamento || 15);
  el('prazoUnidade').value = doc.prazo_unidade || 'DFM';
  toggleConditionalFields();
}

async function showDocumentSummary(id) {
  return withLoading('Carregando resumo do documento…', async () => {
  hideError();
  const data = await fetchJson(`${API.document}?id=${id}`);
  el('summary').innerHTML = `<p class="history-banner">Resumo do documento <strong>${data.document.document_id}</strong></p>`;
  updateSummary(data.summary);
  setStep(3);
  document.querySelector('.summary')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
}

async function loadDocumentForEdit(id) {
  return withLoading('Carregando documento para edição…', async () => {
  hideError();
  const data = await fetchJson(`${API.document}?id=${id}`);
  const { document: doc, campaigns, summary } = data;

  if (!campaigns.length) {
    throw new Error('Planilha original indisponível. Envie o arquivo .xlsx novamente.');
  }

  state.file = null;
  state.sourceDocumentId = doc.id;
  state.editingFromHistory = true;
  state.campaigns = campaigns;

  populateCampaigns();
  if (doc.ads_id) {
    el('campaign').value = doc.ads_id;
  }
  applyDocumentToForm(doc);
  el('fileMeta').textContent = `Planilha: ${doc.source_file || 'arquivo salvo'} (do histórico)`;
  el('formSection').classList.remove('hidden');
  if (summary?.financials?.totals) {
    state.campaignCache[doc.ads_id] = { ...summary.campaign, totals: summary.financials.totals };
    state.campaignSnapshot = state.campaignCache[doc.ads_id];
    refreshSummary();
  } else {
    await loadCampaignSnapshot();
  }
  setStep(3);
  el('generateBtn').disabled = false;
  el('formSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

async function loadFees() {
  state.fees = await fetchJson(API.fees);
}

function refreshSummary() {
  if (!state.campaignSnapshot || !Object.keys(state.fees).length) return;
  const data = buildSummaryPayload(
    state.campaignSnapshot,
    el('tipoVenda').value,
    el('planejador').value,
    state.fees,
  );
  updateSummary(data);
}

async function fetchCampaignSnapshot(adsId) {
  if (state.campaignCache[adsId]) {
    return state.campaignCache[adsId];
  }
  const fd = new FormData();
  if (state.spreadsheetKey) fd.append('spreadsheetKey', state.spreadsheetKey);
  if (state.sourceDocumentId) fd.append('sourceDocumentId', String(state.sourceDocumentId));
  fd.append('adsId', adsId);
  const data = await fetchJson(API.campaign, { method: 'POST', body: fd });
  state.campaignCache[adsId] = data.campaign;
  return data.campaign;
}

async function loadCampaignSnapshot() {
  const adsId = el('campaign').value;
  if (!adsId) return;

  await withLoading('Carregando dados da campanha…', async () => {
    state.campaignSnapshot = await fetchCampaignSnapshot(adsId);
    refreshSummary();
  });
}

async function handleFile(file) {
  hideError();
  if (!file.name.match(/\.xlsx$/i)) {
    showError('Selecione um arquivo .xlsx válido.');
    return;
  }
  try {
    await withLoading('Lendo planilha e carregando campanhas…', async () => {
      state.file = file;
      state.sourceDocumentId = null;
      state.editingFromHistory = false;
      state.spreadsheetKey = null;
      state.campaignCache = {};
      state.campaignSnapshot = null;
      el('summary').innerHTML = '';
      const fd = new FormData();
      fd.append('file', file);
      const data = await fetchJson(API.parse, { method: 'POST', body: fd });
      state.campaigns = data.campaigns;
      state.spreadsheetKey = data.spreadsheetKey;
      el('fileMeta').textContent = `Arquivo: ${file.name}`;
      populateCampaigns();
      el('formSection').classList.remove('hidden');
      setStep(2);
      state.campaignSnapshot = await fetchCampaignSnapshot(el('campaign').value);
      refreshSummary();
      setStep(3);
      el('generateBtn').disabled = false;
    });
  } catch (err) {
    showError(err.message);
  }
}

async function onGenerate() {
  if (!state.spreadsheetKey && !state.sourceDocumentId) return;
  hideError();
  return withLoading('Gerando PDF…', async () => {
  try {
    const fd = getFormData();
    if (!el('documentId').value) {
      const id = generateDocumentId();
      el('documentId').value = id;
      fd.set('documentId', id);
    }
    const res = await fetch(API.generate, { method: 'POST', body: fd });
    if (!res.ok) {
      const err = await res.json();
      throw new Error(err.error);
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const c = state.campaigns.find((item) => item.ads_id === el('campaign').value);
    a.download = res.headers.get('Content-Disposition')?.match(/filename="(.+)"/)?.[1]
      || buildPdfFilename(c?.campanha, c?.anunciante);
    a.click();
    URL.revokeObjectURL(url);
    state.editingFromHistory = false;
    setStep(4);
    await loadHistory();
  } catch (err) {
    showError(err.message);
  }
  });
}

function initSelects() {
  TIPOS_VENDA.forEach((t) => {
    const o = document.createElement('option');
    o.value = t; o.textContent = t;
    el('tipoVenda').appendChild(o);
  });
  el('tipoVenda').value = 'SSP';
  PLANEJADORES.forEach((p) => {
    const o = document.createElement('option');
    o.value = p; o.textContent = p;
    el('planejador').appendChild(o);
  });
  TIPOS_DEAL.forEach((t) => {
    const o = document.createElement('option');
    o.value = t; o.textContent = t || '(nenhum)';
    el('tipoDeal').appendChild(o);
  });
}

function initPrazoSelects() {
  PRAZO_OPCOES.forEach((d) => {
    const o = document.createElement('option');
    o.value = String(d);
    o.textContent = `${d} dias`;
    el('prazoPagamento').appendChild(o);
  });
  el('prazoPagamento').value = '15';
}

function initDropzone() {
  const zone = el('dropzone');
  const input = el('fileInput');
  zone.addEventListener('click', () => input.click());
  zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('dragover');
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
  });
  input.addEventListener('change', () => { if (input.files[0]) handleFile(input.files[0]); });
}

function init() {
  initSelects();
  initPrazoSelects();
  initDropzone();
  loadFees().catch(() => {});
  loadHistory();
  el('campaign').addEventListener('change', () => loadCampaignSnapshot().catch((e) => showError(e.message)));
  el('tipoVenda').addEventListener('change', () => { toggleConditionalFields(); refreshSummary(); });
  el('planejador').addEventListener('change', refreshSummary);
  el('tipoDeal').addEventListener('change', toggleConditionalFields);
  el('dealId').addEventListener('input', toggleConditionalFields);
  el('prazoPagamento').addEventListener('change', refreshSummary);
  el('prazoUnidade').addEventListener('change', refreshSummary);
  ['documentTitle', 'ocInforme', 'checking', 'relatorios'].forEach((id) => {
    el(id).addEventListener('change', refreshSummary);
  });
  el('generateBtn').addEventListener('click', onGenerate);
  el('newIdBtn').addEventListener('click', () => { el('documentId').value = generateDocumentId(); });
  toggleConditionalFields();
  setStep(1);
}

init();
