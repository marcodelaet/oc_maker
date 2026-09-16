const API = window.OC_MAKER?.api ?? {
  parse: 'api/parse.php',
  campaign: 'api/campaign.php',
  fees: 'api/fees.php',
  generate: 'api/generate.php',
  history: 'api/history.php',
  document: 'api/document.php',
};

const { withLoading } = AppLoading;
const { buildSummaryPayload } = OcFinancials;

async function fetchJson(url, options = {}) {
  let res;
  try {
    res = await fetch(url, options);
  } catch (err) {
    throw new Error(
      `Falha de rede ao chamar ${url}. Verifique se o Apache/PHP está ativo e se o arquivo não excede o limite de upload.`,
    );
  }
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
  auth: { user: null, canDelete: false, isAdmin: false },
  csrfToken: window.OC_MAKER?.csrf_token || '',
  pendingDeleteId: null,
  historyDocuments: null,
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
  if (state.csrfToken) fd.append('csrf_token', state.csrfToken);
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

function syncAuthFromUser(user, flags = {}) {
  if (!user) {
    state.auth.user = null;
    state.auth.isAdmin = false;
    state.auth.canDelete = false;
    return;
  }
  state.auth.user = user;
  state.auth.isAdmin = flags.isAdmin ?? user.role === 'administrador';
  state.auth.canDelete = flags.canDelete ?? state.auth.isAdmin;
}

function syncAuthFromServerPayload(data) {
  syncAuthFromUser(data.user || null, {
    isAdmin: !!data.is_admin,
    canDelete: !!data.can_delete,
  });
}

async function loadAuth() {
  if (!API.authMe) return;
  try {
    const data = await fetchJson(API.authMe);
    if (data.csrf_token) state.csrfToken = data.csrf_token;
    syncAuthFromServerPayload(data);
    updateAuthNav();
    if (data.user && window.OC_MAKER) window.OC_MAKER.accountUser = data.user;
    if (data.user && window.OcAccount) window.OcAccount.syncUser(data.user);
    if (data.user?.must_change_password) {
      window.OcForcePassword?.maybeOpen(data.user);
    }
    if (state.historyDocuments) {
      renderHistoryTable(state.historyDocuments);
    }
  } catch {
    if (window.OC_MAKER?.accountUser) {
      syncAuthFromUser(window.OC_MAKER.accountUser);
      updateAuthNav();
    }
  }
}

function renderAvatarHtml(user, sizeClass = 'avatar-sm', withRing = false) {
  if (!user?.avatar_url) {
    return window.OcIcons?.defaultAvatar(sizeClass, withRing)
      || `<span class="user-avatar user-avatar--default ${sizeClass}"></span>`;
  }
  const ring = withRing ? ' avatar-ring' : '';
  const safeUrl = String(user.avatar_url).replace(/"/g, '&quot;');
  const safeName = String(user?.name || 'Usuário').replace(/"/g, '&quot;');
  return `<span class="user-avatar-img ${sizeClass}${ring}"><img src="${safeUrl}" alt="${safeName}"></span>`;
}

window.ocUpdateAuthUser = (user) => {
  syncAuthFromUser(user);
  if (window.OC_MAKER) window.OC_MAKER.accountUser = user;
  updateAuthNav();
};

window.ocIsLoggedIn = () => !!state.auth.user;

function closeUserMenu() {
  const panel = el('userMenuPanel');
  const toggle = el('userMenuToggle');
  if (!panel || !toggle) return;
  panel.classList.add('hidden');
  toggle.setAttribute('aria-expanded', 'false');
}

function initUserMenuIcons() {
  if (!window.OcIcons) return;
  const accountIcon = document.querySelector('#userMenuAccount .user-menu-item-icon');
  const adminIcon = document.querySelector('#userMenuAdmin .user-menu-item-icon');
  const logIcon = document.querySelector('#userMenuActivityLog .user-menu-item-icon');
  const logoutIcon = document.querySelector('#logoutBtn .user-menu-item-icon');
  if (accountIcon) accountIcon.outerHTML = OcIcons.menuItemIcon('user');
  if (adminIcon) adminIcon.outerHTML = OcIcons.menuItemIcon('users');
  if (logIcon) logIcon.outerHTML = OcIcons.menuItemIcon('clipboard-list');
  if (logoutIcon) logoutIcon.outerHTML = OcIcons.menuItemIcon('logout');
}

function updateAuthNav() {
  const loggedIn = !!state.auth.user;
  el('loginLink')?.classList.toggle('hidden', loggedIn);
  el('userNav')?.classList.toggle('hidden', !loggedIn);
  el('calculatorLink')?.classList.toggle(
    'hidden',
    !(state.auth.isAdmin || state.auth.user?.role === 'financeiro'),
  );
  el('userMenuAdmin')?.classList.toggle('hidden', !state.auth.isAdmin);
  el('userMenuActivityLog')?.classList.toggle('hidden', !state.auth.isAdmin);

  if (loggedIn && state.auth.user) {
    const u = state.auth.user;
    const trigger = el('userAvatarTrigger');
    const menuAvatar = el('userMenuAvatar');
    if (trigger) trigger.innerHTML = renderAvatarHtml(u, 'avatar-sm', true);
    if (menuAvatar) menuAvatar.innerHTML = renderAvatarHtml(u, 'avatar-lg', true);
    const nameEl = el('userMenuName');
    const emailEl = el('userMenuEmail');
    const roleEl = el('userMenuRole');
    if (nameEl) nameEl.textContent = u.display_name || u.name || '';
    if (emailEl) emailEl.textContent = u.email || '';
    if (roleEl) roleEl.textContent = u.role || '';
  } else {
    closeUserMenu();
  }
}

function openDeleteModal(id, documentLabel) {
  state.pendingDeleteId = id;
  el('deleteModalMessage').textContent =
    `Deseja mesmo remover o ID ${documentLabel} da lista? Esta ação não pode ser desfeita.`;
  el('deleteModal').classList.remove('hidden');
  el('deleteModal').setAttribute('aria-hidden', 'false');
  document.body.classList.add('app-busy');
}

function closeDeleteModal() {
  state.pendingDeleteId = null;
  el('deleteModal').classList.add('hidden');
  el('deleteModal').setAttribute('aria-hidden', 'true');
  document.body.classList.remove('app-busy');
}

async function confirmDeleteDocument() {
  const id = state.pendingDeleteId;
  if (!id) return;
  closeDeleteModal();
  await withLoading('Removendo documento…', async () => {
    const fd = new FormData();
    fd.append('id', String(id));
    fd.append('csrf_token', state.csrfToken);
    await fetchJson(API.deleteDocument, { method: 'POST', body: fd });
    await loadHistory();
  });
}

function renderHistoryTable(documents) {
  const box = el('history');
  if (!window.OcIcons) {
    box.innerHTML = '<p class="file-meta">Biblioteca de ícones não carregada. Recarregue a página (Ctrl+F5).</p>';
    return;
  }
  if (!documents?.length) {
    state.historyDocuments = null;
    box.innerHTML = '<p class="file-meta">Nenhum documento gerado ainda.</p>';
    return;
  }
  state.historyDocuments = documents;
  box.innerHTML = `<table class="history-table"><thead><tr>
    <th>ID</th><th>Campanha</th><th>Anunciante</th><th class="history-actions">Ações</th>
  </tr></thead><tbody>${documents.map((d) => `
    <tr>
      <td>${d.document_id}</td>
      <td>${d.campanha || '—'}</td>
      <td>${d.anunciante || '—'}</td>
      <td class="history-actions">
        <div class="icon-btn-group">${OcIcons.button('eye', 'Resumo', 'icon-btn--table', `data-action="summary" data-id="${d.id}"`)}
        ${OcIcons.button('edit', 'Editar', 'icon-btn--table', `data-action="edit" data-id="${d.id}"`)}
        ${state.auth.canDelete ? OcIcons.button('trash', 'Remover', 'icon-btn--table icon-btn--danger', `data-action="delete" data-id="${d.id}" data-label="${d.document_id}"`) : ''}</div>
      </td>
    </tr>`).join('')}</tbody></table>`;

  box.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = Number(btn.dataset.id);
      if (btn.dataset.action === 'summary') {
        showDocumentSummary(id).catch((e) => showError(e.message));
      } else if (btn.dataset.action === 'delete') {
        openDeleteModal(id, btn.dataset.label || String(id));
      } else {
        loadDocumentForEdit(id).catch((e) => showError(e.message));
      }
    });
  });
}

async function loadHistory() {
  const box = el('history');
  box.innerHTML = '<p class="file-meta">Carregando histórico…</p>';
  try {
    const data = await fetchJson(API.history);
    renderHistoryTable(data.documents || []);
  } catch (err) {
    state.historyDocuments = null;
    box.innerHTML = `<p class="file-meta">Histórico indisponível: ${err.message}</p>`;
  }
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
  hideError();
  try {
    await loadDocumentForEdit(id);
    document.querySelector('.summary')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } catch (e) {
    showError(e.message);
  }
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
  el('summary').innerHTML = `<p class="history-banner">Documento <strong>${doc.document_id}</strong></p>`;
  if (summary?.financials?.totals) {
    state.campaignCache[doc.ads_id] = { ...summary.campaign, totals: summary.financials.totals };
    state.campaignSnapshot = state.campaignCache[doc.ads_id];
    await ensureFeesLoaded();
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

async function ensureFeesLoaded() {
  if (Object.keys(state.fees).length) return;
  await loadFees().catch(() => {});
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

  const panel = el('summary');
  const banner = panel.querySelector('.history-banner');
  const bannerHtml = banner ? banner.outerHTML : '';
  panel.innerHTML = `${bannerHtml}<p class="file-meta">Carregando dados da campanha…</p>`;
  try {
    state.campaignSnapshot = await fetchCampaignSnapshot(adsId);
    await ensureFeesLoaded();
    refreshSummary();
  } catch (err) {
    panel.innerHTML = `${bannerHtml}<p class="file-meta">Erro ao carregar campanha: ${err.message}</p>`;
    throw err;
  }
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
      const firstAdsId = el('campaign').value;
      if (data.campaign && data.campaign.ads_id === firstAdsId) {
        state.campaignCache[firstAdsId] = data.campaign;
        state.campaignSnapshot = data.campaign;
      } else {
        state.campaignSnapshot = await fetchCampaignSnapshot(firstAdsId);
      }
      await ensureFeesLoaded();
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
  if (!el('campaign').value) {
    showError('Selecione uma campanha antes de gerar o PDF.');
    return;
  }
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
    const contentType = res.headers.get('content-type') || '';
    if (!res.ok) {
      const err = contentType.includes('application/json') ? await res.json() : { error: await res.text() };
      throw new Error(err.error || 'Falha ao gerar PDF.');
    }
    if (!contentType.includes('application/pdf')) {
      throw new Error('Resposta inválida ao gerar PDF. Recarregue a página e tente novamente.');
    }
    const blob = await res.blob();
    if (blob.size < 100) {
      throw new Error('PDF vazio ou corrompido. Verifique a planilha e tente novamente.');
    }
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

function initIconButtons() {
  const newIdBtn = el('newIdBtn');
  if (newIdBtn && window.OcIcons) {
    newIdBtn.innerHTML = OcIcons.svg('refresh', 18);
  }
  const pdfIcon = document.querySelector('#generateBtn .btn-icon');
  if (pdfIcon && window.OcIcons) {
    pdfIcon.innerHTML = OcIcons.svg('pdf', 18);
  }
  const dropzone = el('dropzone');
  if (dropzone && window.OcIcons && !dropzone.querySelector('.dropzone-icon')) {
    const iconWrap = document.createElement('div');
    iconWrap.className = 'dropzone-icon';
    iconWrap.innerHTML = OcIcons.svg('upload', 40);
    dropzone.insertBefore(iconWrap, dropzone.firstChild);
  }
}

function initUserMenu() {
  initUserMenuIcons();
  const toggle = el('userMenuToggle');
  const panel = el('userMenuPanel');
  if (!toggle || !panel) return;

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = panel.classList.toggle('hidden');
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
  });

  document.addEventListener('click', (e) => {
    if (!el('userMenu')?.contains(e.target)) {
      closeUserMenu();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeUserMenu();
  });
}

function init() {
  initSelects();
  initPrazoSelects();
  initDropzone();
  initIconButtons();
  initUserMenu();
  if (window.OC_MAKER?.accountUser) {
    syncAuthFromUser(window.OC_MAKER.accountUser);
    updateAuthNav();
    if (window.OcAccount) {
      window.OcAccount.init(window.OC_MAKER.accountUser);
      if (window.OC_MAKER.accountUser.must_change_password) {
        window.OcForcePassword?.maybeOpen(window.OC_MAKER.accountUser);
      }
    }
  }
  if (window.OcUsers) window.OcUsers.init();
  if (window.OcActivityLog) window.OcActivityLog.init();
  if (window.OcCalculator) window.OcCalculator.init();
  if (window.OcLogin) window.OcLogin.init();
  Promise.all([
    loadFees().catch(() => {}),
    loadAuth().catch(() => {}),
    loadHistory().catch(() => {}),
  ]);
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
  el('deleteCancelBtn')?.addEventListener('click', closeDeleteModal);
  el('deleteConfirmBtn')?.addEventListener('click', () => confirmDeleteDocument().catch((e) => showError(e.message)));
  el('userMenuAccount')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeUserMenu();
    window.OcAccount?.open();
  });
  el('userMenuAdmin')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeUserMenu();
    if (!window.OcUsers) {
      showError('Gestão de usuários não carregada. Recarregue a página (Ctrl+F5).');
      return;
    }
    window.OcUsers.open();
  });
  el('userMenuActivityLog')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeUserMenu();
    if (!window.OcActivityLog) {
      showError('Log de eventos não carregado. Recarregue a página (Ctrl+F5).');
      return;
    }
    window.OcActivityLog.open();
  });
  el('calculatorLink')?.addEventListener('click', () => {
    window.OcCalculator?.open();
  });
  el('loginLink')?.addEventListener('click', () => {
    window.OcLogin?.open();
  });
  el('logoutBtn')?.addEventListener('click', async () => {
    closeUserMenu();
    const fd = new FormData();
    fd.append('csrf_token', state.csrfToken);
    await fetchJson(API.authLogout, { method: 'POST', body: fd });
    state.auth = { user: null, canDelete: false, isAdmin: false };
    if (window.OC_MAKER) window.OC_MAKER.accountUser = null;
    updateAuthNav();
    await loadHistory();
  });
  toggleConditionalFields();
  setStep(1);
}

init();
