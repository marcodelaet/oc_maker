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

function resolveApiUrl(url) {
  try {
    return new URL(url, window.location.href).href;
  } catch {
    return url;
  }
}

function getFileFromFormData(formData) {
  if (!(formData instanceof FormData)) return null;
  for (const value of formData.values()) {
    if (value instanceof File) return value;
  }
  return null;
}

/** Falha cedo se o SO/navegador não conseguir ler o arquivo (ex.: Excel com o .xlsx aberto). */
async function assertFileReadable(file) {
  if (!file || !(file instanceof File)) return;
  try {
    await file.slice(0, 1).arrayBuffer();
  } catch {
    throw new Error(
      `Não foi possível ler "${file.name}". O arquivo provavelmente está aberto no Excel ` +
      'ou em uso por outro programa. Feche-o, aguarde alguns segundos e tente novamente.',
    );
  }
}

function isLikelyFileAccessError(err) {
  const msg = String(err?.message || err || '').toLowerCase();
  const name = String(err?.name || '').toLowerCase();
  return (
    name === 'notreadableerror'
    || msg.includes('failed to fetch')
    || msg.includes('err_failed')
    || msg.includes('xhr falhou')
    || msg.includes('network')
    || msg.includes('networkerror')
    || msg.includes('não foi possível ler ou enviar')
  );
}

function formatUploadError(file, target, lastErr) {
  const fileName = file?.name || 'o arquivo';
  if (isLikelyFileAccessError(lastErr)) {
    return (
      `Não foi possível enviar "${fileName}". Verifique se ele não está aberto no Excel ` +
      '(ou em uso por outro programa), feche-o e tente de novo. ' +
      'Se o problema continuar, confirme que o Apache/PHP está ativo e recarregue a página (Ctrl+F5).'
    );
  }
  const detail = lastErr?.message ? ` ${lastErr.message}` : '';
  return `Falha ao enviar "${fileName}".${detail} Destino: ${target}`;
}

function parseUploadJsonResponse(status, contentType, bodyText) {
  if (!contentType.includes('application/json')) {
    const snippet = (bodyText || '').replace(/\s+/g, ' ').slice(0, 180);
    throw new Error(
      `Resposta inválida do servidor (esperado JSON, recebido HTML). ` +
      `Verifique se o Apache aponta para a pasta public/ — ${snippet}`,
    );
  }
  let data;
  try {
    data = JSON.parse(bodyText);
  } catch {
    throw new Error('Resposta JSON inválida do servidor.');
  }
  if (status < 200 || status >= 300) {
    throw new Error(data.error || 'Erro na requisição');
  }
  return data;
}

/** Upload via XHR — caminho mais confiável para multipart no Windows/localhost. */
function postFormDataViaXhr(url, formData, { method = 'POST', timeoutMs = 120000 } = {}) {
  const target = resolveApiUrl(url);
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(method, target, true);
    xhr.timeout = timeoutMs;
    // Não definir Content-Type (boundary automático) nem withCredentials (same-origin já envia cookies).
    xhr.onload = () => {
      try {
        const contentType = xhr.getResponseHeader('Content-Type') || '';
        resolve(parseUploadJsonResponse(xhr.status, contentType, xhr.responseText || ''));
      } catch (err) {
        reject(err instanceof Error ? err : new Error(String(err)));
      }
    };
    xhr.onerror = () => {
      reject(new Error('Não foi possível ler ou enviar o arquivo (verifique se não está aberto no Excel).'));
    };
    xhr.ontimeout = () => {
      reject(new Error(
        `Tempo esgotado ao enviar arquivo para ${target}. Planilhas grandes podem levar até 60s.`,
      ));
    };
    xhr.send(formData);
  });
}

/** Upload via fetch nativo (sem patch) — fallback se XHR falhar. */
async function postFormDataViaNativeFetch(url, formData, { method = 'POST', timeoutMs = 120000 } = {}) {
  const target = resolveApiUrl(url);
  const doFetch = window.__ocNativeFetch || window.fetch;
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
  try {
    const res = await doFetch(target, {
      method,
      body: formData,
      credentials: 'same-origin',
      signal: controller?.signal,
    });
    const contentType = res.headers.get('content-type') || '';
    const bodyText = await res.text();
    return parseUploadJsonResponse(res.status, contentType, bodyText);
  } catch (err) {
    if (err?.name === 'AbortError') {
      throw new Error(
        `Tempo esgotado ao enviar arquivo para ${target}. Planilhas grandes podem levar até 60s.`,
      );
    }
    if (isLikelyFileAccessError(err)) {
      throw new Error(
        'Não foi possível ler ou enviar o arquivo (verifique se não está aberto no Excel).',
      );
    }
    throw err;
  } finally {
    if (timer) clearTimeout(timer);
  }
}

/** Fallback: POST via form+iframe (sem XHR/fetch — contorna bloqueios de rede do navegador). */
function postFormDataViaIframe(url, formData, { method = 'POST', timeoutMs = 120000 } = {}) {
  const target = resolveApiUrl(url);
  return new Promise((resolve, reject) => {
    const frameName = `oc_upload_${Date.now()}`;
    const iframe = document.createElement('iframe');
    iframe.name = frameName;
    iframe.setAttribute('aria-hidden', 'true');
    iframe.tabIndex = -1;
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';

    const form = document.createElement('form');
    form.method = method;
    form.action = target;
    form.target = frameName;
    form.enctype = 'multipart/form-data';
    form.style.display = 'none';

    for (const [name, value] of formData.entries()) {
      if (value instanceof File) {
        const input = document.createElement('input');
        input.type = 'file';
        input.name = name;
        const dt = new DataTransfer();
        dt.items.add(value);
        input.files = dt.files;
        form.appendChild(input);
      } else {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        form.appendChild(input);
      }
    }

    let settled = false;
    let timer;
    const cleanup = () => {
      iframe.remove();
      form.remove();
    };
    const finish = (fn) => {
      if (settled) return;
      settled = true;
      if (timer) clearTimeout(timer);
      cleanup();
      fn();
    };

    iframe.addEventListener('load', () => {
      try {
        const doc = iframe.contentDocument || iframe.contentWindow?.document;
        const bodyText = (doc?.body?.innerText || doc?.body?.textContent || '').trim();
        if (!bodyText) {
          finish(() => reject(new Error(`Resposta vazia do servidor em ${target}`)));
          return;
        }
        const contentType = doc?.contentType || 'application/json';
        const data = parseUploadJsonResponse(200, contentType, bodyText);
        finish(() => resolve(data));
      } catch (err) {
        finish(() => reject(err instanceof Error ? err : new Error(String(err))));
      }
    });

    timer = setTimeout(() => {
      finish(() => reject(new Error(
        `Tempo esgotado ao enviar arquivo para ${target}. Planilhas grandes podem levar até 60s.`,
      )));
    }, timeoutMs);

    document.body.append(iframe, form);
    form.submit();
  });
}

async function postFormData(url, formData, options = {}) {
  const target = resolveApiUrl(url);
  const file = getFileFromFormData(formData);
  const attempts = [
    () => postFormDataViaXhr(url, formData, options),
    () => postFormDataViaNativeFetch(url, formData, options),
    () => postFormDataViaIframe(url, formData, options),
  ];
  let lastErr;
  for (const attempt of attempts) {
    try {
      return await attempt();
    } catch (err) {
      lastErr = err;
    }
  }
  throw new Error(formatUploadError(file, target, lastErr));
}

async function fetchJson(url, options = {}) {
  if (options.body instanceof FormData) {
    return postFormData(url, options.body, { method: options.method || 'POST' });
  }

  let res;
  try {
    res = await fetch(url, options);
  } catch (err) {
    const detail = err?.message ? ` (${err.message})` : '';
    throw new Error(
      `Falha de rede ao chamar ${url}${detail}. Verifique se o Apache/PHP está ativo.`,
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
  spreadsheetFileName: null,
  campaignCache: {},
  campaignSnapshot: null,
  fees: {},
  sourceDocumentId: null,
  editingFromHistory: false,
  auth: { user: null, canDelete: false, isAdmin: false, canCampaigns: false },
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
    if (state.spreadsheetFileName) {
      fd.append('spreadsheetFileName', state.spreadsheetFileName);
    }
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

function summaryFinancialMarkup(tipoVenda, fin) {
  const pct = fin.feePercent ?? 0;
  const brutoBase = fin.brutoBase ?? fin.totals.bruto;
  const faturamento = fin.faturamento ?? fin.totals.bruto;

  if (tipoVenda === 'Agência') {
    return `
      <dt>Bruto agência</dt><dd>${formatBRL(brutoBase)}</dd>
      <dt>Líquido agência</dt><dd>${formatBRL(faturamento)}</dd>
    </dl>
    <div class="highlight">
      <dl>
        <dt>Tech fee (${pct}%)</dt><dd>${formatBRL(fin.feeValue)}</dd>
        <dt>Valor Publisher</dt><dd>${formatBRL(fin.valorPublisher)}</dd>
        <dt>CPM Médio</dt><dd>${formatBRL(fin.cpm)}</dd>
      </dl>
    </div>`;
  }

  return `
      <dt>Bruto Publisher</dt><dd>${formatBRL(brutoBase)}</dd>
      <dt>Faturamento</dt><dd>${formatBRL(faturamento)}</dd>
    </dl>
    <div class="highlight">
      <dl>
        <dt>Tech Fee (${pct}%)</dt><dd>${formatBRL(fin.feeValue)}</dd>
        <dt>Valor Publisher</dt><dd>${formatBRL(fin.valorPublisher)}</dd>
        <dt>CPM Médio</dt><dd>${formatBRL(fin.cpm)}</dd>
      </dl>
    </div>`;
}

function updateSummary(data) {
  const panel = el('summary');
  if (!data) {
    panel.innerHTML = "<p class='file-meta'>Carregue uma planilha para começar.</p>";
    return;
  }
  const existingBanner = panel.querySelector('.existing-document-banner');
  const historyBanner = panel.querySelector('.history-banner:not(.existing-document-banner)');
  const { campaign, financials: fin } = data;
  const tipoVenda = data.tipoVenda || el('tipoVenda')?.value || 'SSP';
  const fmt = (iso) => {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  };
  const bannerHtml = [
    existingBanner?.outerHTML,
    historyBanner?.outerHTML,
  ].filter(Boolean).join('');
  panel.innerHTML = `${bannerHtml}
    <dl>
      <dt>Campanha</dt><dd>${campaign.campanha}</dd>
      <dt>Anunciante</dt><dd>${campaign.anunciante}</dd>
      <dt>Período</dt><dd>${fmt(campaign.inicio)} — ${fmt(campaign.termino)}</dd>
      <dt>Lojas</dt><dd>${campaign.inventoryCount}</dd>
      <dt>Total Inserções</dt><dd>${fin.totals.insercoes.toLocaleString('pt-BR')}</dd>
      <dt>Total Impactos</dt><dd>${fin.totals.impactos.toLocaleString('pt-BR')}</dd>
      ${summaryFinancialMarkup(tipoVenda, fin)}`;
  updatePrazoPreview(campaign.termino);
}

function syncAuthFromUser(user, flags = {}) {
  if (!user) {
    state.auth.user = null;
    state.auth.isAdmin = false;
    state.auth.canDelete = false;
    state.auth.canCampaigns = false;
    return;
  }
  state.auth.user = user;
  state.auth.isAdmin = flags.isAdmin ?? user.role === 'administrador';
  state.auth.canDelete = flags.canDelete ?? state.auth.isAdmin;
  state.auth.canCampaigns = flags.canCampaigns ?? ['administrador', 'programatica'].includes(user.role);
}

function syncAuthFromServerPayload(data) {
  syncAuthFromUser(data.user || null, {
    isAdmin: !!data.is_admin,
    canDelete: !!data.can_delete,
    canCampaigns: !!data.can_campaigns,
  });
}

async function loadAuth() {
  if (!API.authMe) return;
  try {
    const data = await fetchJson(API.authMe);
    if (data.csrf_token) {
      state.csrfToken = data.csrf_token;
      if (window.OC_MAKER) window.OC_MAKER.csrf_token = data.csrf_token;
    }
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
window.ocLoadAuth = loadAuth;

window.ocMakerCsrfToken = (token) => {
  if (typeof token === 'string' && token) {
    state.csrfToken = token;
    if (window.OC_MAKER) window.OC_MAKER.csrf_token = token;
    return token;
  }
  return state.csrfToken || window.OC_MAKER?.csrf_token || '';
};

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
  const campaignsIcon = document.querySelector('#userMenuCampaigns .user-menu-item-icon');
  const logoutIcon = document.querySelector('#logoutBtn .user-menu-item-icon');
  if (accountIcon) accountIcon.outerHTML = OcIcons.menuItemIcon('user');
  if (adminIcon) adminIcon.outerHTML = OcIcons.menuItemIcon('users');
  if (logIcon) logIcon.outerHTML = OcIcons.menuItemIcon('clipboard-list');
  if (campaignsIcon) campaignsIcon.outerHTML = OcIcons.menuItemIcon('megaphone');
  if (logoutIcon) logoutIcon.outerHTML = OcIcons.menuItemIcon('logout');
  const campaignsHeader = el('campaignsLink');
  if (campaignsHeader) campaignsHeader.innerHTML = OcIcons.svg('megaphone', 20);
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
  el('userMenuCampaigns')?.classList.toggle('hidden', !state.auth.canCampaigns);
  el('campaignsLink')?.classList.toggle('hidden', !state.auth.canCampaigns);

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
  if (!state.auth.user) {
    state.historyDocuments = null;
    box.innerHTML = '<p class="file-meta">Faça login para ver seu histórico de documentos.</p>';
    return;
  }
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

function resetDocumentFormDefaults() {
  el('documentId').value = '';
  el('documentTitle').value = 'Informe de Campanha';
  el('tipoVenda').value = 'SSP';
  el('planejador').value = PLANEJADORES[0];
  el('tipoDeal').value = '';
  el('dealId').value = '';
  el('ocInforme').value = '';
  el('checking').checked = true;
  el('relatorios').checked = false;
  el('prazoPagamento').value = '15';
  el('prazoUnidade').value = 'DFM';
  toggleConditionalFields();
}

function updateExistingDocumentBanner(doc) {
  const panel = el('summary');
  if (!panel) return;
  panel.querySelector('.existing-document-banner')?.remove();
  panel.querySelector('.history-banner:not(.existing-document-banner)')?.remove();
  if (!doc) return;
  const banner = document.createElement('p');
  banner.className = 'history-banner existing-document-banner';
  banner.innerHTML = `Documento existente <strong>${String(doc.document_id || '').replace(/[<>&"]/g, (c) => ({
    '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;',
  }[c]))}</strong> — os dados serão atualizados ao gerar o PDF. Inventário será re-sincronizado com a planilha enviada.`;
  panel.insertBefore(banner, panel.firstChild);
}

function applyExistingDocument(doc) {
  if (!doc) {
    state.sourceDocumentId = null;
    return;
  }
  state.sourceDocumentId = doc.id;
  applyDocumentToForm(doc);
}

function syncExistingDocumentFromApi(existingDocument) {
  if (state.editingFromHistory) return;
  if (existingDocument) {
    applyExistingDocument(existingDocument);
    updateExistingDocumentBanner(existingDocument);
    return;
  }
  state.sourceDocumentId = null;
  resetDocumentFormDefaults();
  el('summary')?.querySelector('.existing-document-banner')?.remove();
}

async function showDocumentSummary(id) {
  hideError();
  try {
    await withLoading('Carregando resumo…', async () => {
      const data = await fetchJson(`${API.document}?id=${id}&mode=summary`);
      const { document: doc, summary } = data;
      if (!summary?.campaign || !summary?.financials) {
        throw new Error('Resumo indisponível para este documento.');
      }
      const panel = el('summary');
      if (panel) {
        panel.innerHTML = `<p class="history-banner">Documento <strong>${doc.document_id}</strong></p>`;
      }
      updateSummary({ ...summary, tipoVenda: summary.tipoVenda || doc.tipo_venda || 'SSP' });
      document.querySelector('.summary')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
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
  const tipoVenda = el('tipoVenda').value;
  const data = buildSummaryPayload(
    state.campaignSnapshot,
    tipoVenda,
    el('planejador').value,
    state.fees,
  );
  updateSummary({ ...data, tipoVenda });
}

async function fetchCampaignSnapshot(adsId) {
  if (state.campaignCache[adsId]) {
    return state.campaignCache[adsId];
  }
  const fd = new FormData();
  if (state.spreadsheetKey) {
    fd.append('spreadsheetKey', state.spreadsheetKey);
    if (state.spreadsheetFileName) fd.append('spreadsheetFileName', state.spreadsheetFileName);
  }
  if (state.sourceDocumentId && !state.spreadsheetKey) {
    fd.append('sourceDocumentId', String(state.sourceDocumentId));
  }
  fd.append('adsId', adsId);
  const data = await fetchJson(API.campaign, { method: 'POST', body: fd });
  state.campaignCache[adsId] = data.campaign;
  syncExistingDocumentFromApi(data.existingDocument || null);
  return data.campaign;
}

async function loadCampaignSnapshot() {
  const adsId = el('campaign').value;
  if (!adsId) return;

  const panel = el('summary');
  const bannerHtml = [
    panel.querySelector('.existing-document-banner')?.outerHTML,
    panel.querySelector('.history-banner:not(.existing-document-banner)')?.outerHTML,
  ].filter(Boolean).join('');
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
  if (!state.auth.user) {
    window.OcLogin?.open({
      onSuccess: () => handleFile(file),
    });
    return;
  }
  try {
    await assertFileReadable(file);
    await withLoading('Lendo planilha e carregando campanhas…', async () => {
      state.file = file;
      state.sourceDocumentId = null;
      state.editingFromHistory = false;
      state.spreadsheetKey = null;
      state.spreadsheetFileName = null;
      state.campaignCache = {};
      state.campaignSnapshot = null;
      el('summary').innerHTML = '';
      const fd = new FormData();
      fd.append('file', file);
      const data = await fetchJson(API.parse, { method: 'POST', body: fd });
      state.campaigns = data.campaigns;
      state.spreadsheetKey = data.spreadsheetKey;
      state.spreadsheetFileName = data.fileName || file.name;
      el('fileMeta').textContent = `Arquivo: ${file.name}`;
      populateCampaigns();
      el('formSection').classList.remove('hidden');
      setStep(2);
      syncExistingDocumentFromApi(data.existingDocument || null);
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
    state.file = null;
    state.campaigns = [];
    state.spreadsheetKey = null;
    state.spreadsheetFileName = null;
    state.campaignSnapshot = null;
    state.campaignCache = {};
    el('fileMeta').textContent = '';
    el('summary').innerHTML = '';
    el('formSection').classList.add('hidden');
    el('generateBtn').disabled = true;
    setStep(1);
    showError(err.message);
  }
}

async function onGenerate() {
  if (!state.spreadsheetKey && !state.sourceDocumentId) return;
  if (!el('campaign').value) {
    showError('Selecione uma campanha antes de gerar o PDF.');
    return;
  }
  if (!state.auth.user) {
    window.OcLogin?.open({ onSuccess: () => onGenerate() });
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
  if (window.OcCampaigns) window.OcCampaigns.init();
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
  el('campaignsLink')?.addEventListener('click', () => {
    window.OcCampaigns?.open();
  });
  el('userMenuCampaigns')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeUserMenu();
    if (!window.OcCampaigns) {
      showError('Gerenciamento de campanhas não carregado. Recarregue a página (Ctrl+F5).');
      return;
    }
    window.OcCampaigns.open();
  });
  el('loginLink')?.addEventListener('click', () => {
    window.OcLogin?.open();
  });
  el('logoutBtn')?.addEventListener('click', async () => {
    closeUserMenu();
    const fd = new FormData();
    fd.append('csrf_token', state.csrfToken);
    await fetchJson(API.authLogout, { method: 'POST', body: fd });
    state.auth = { user: null, canDelete: false, isAdmin: false, canCampaigns: false };
    if (window.OC_MAKER) window.OC_MAKER.accountUser = null;
    updateAuthNav();
    await loadHistory();
  });
  toggleConditionalFields();
  setStep(1);
}

init();
