(function () {
  let approvedDocId = 0;
  let approvedData = null;
  let editingDealId = 0;
  let dealViewOnly = false;
  let approvedTab = 'deals';
  let dealDirty = false;
  let eventsBound = false;

  function isReadOnlyMode() {
    return !!approvedData?.read_only;
  }
  /** @type {Set<string>} */
  const copiedPlaybookCodes = new Set();
  /** @type {Map<string, string>} */
  const pacingMetrics = new Map();
  /** @type {Map<number, Record<string, boolean>>} */
  const reportColumnPrefs = new Map();
  /** @type {Map<number, string>} */
  const reportNetworkFilters = new Map();
  /** @type {Map<number, { column: string, direction: 'asc'|'desc' }>} */
  const reportTableSort = new Map();
  let reportColumnsModalDealId = 0;
  /** @type {Record<string, boolean>|null} */
  let reportColumnsModalDraft = null;

  const REPORT_COLUMN_DEFS = [
    { id: 'date', label: 'Data', group: true, defaultVisible: true },
    { id: 'screen', label: 'Unidade', group: true, defaultVisible: false, requiresScreens: true },
    { id: 'network', label: 'Rede', group: true, defaultVisible: false, requiresNetworks: true },
    { id: 'requisicoes', label: 'Req.', metric: true, defaultVisible: true },
    { id: 'impressoes', label: 'Impressões', metric: true, defaultVisible: true },
    { id: 'impactos', label: 'Impactos', metric: true, defaultVisible: true },
    { id: 'consumo', label: 'Consumo', metric: true, defaultVisible: true },
  ];

  const el = (id) => document.getElementById(id);

  function csrf() {
    return window.OC_MAKER?.csrf_token || window.ocMakerCsrfToken?.() || '';
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.campaignManagement || '';
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
  }

  function formatInteger(value) {
    return Math.round(Number(value) || 0).toLocaleString('pt-BR', {
      maximumFractionDigits: 0,
      minimumFractionDigits: 0,
    });
  }

  function parseDealInteger(value) {
    if (value == null || value === '') return null;
    const cleaned = String(value).replace(/\./g, '').replace(/[^\d]/g, '');
    if (cleaned === '') return null;
    const n = parseInt(cleaned, 10);
    return Number.isFinite(n) ? Math.max(0, n) : null;
  }

  function parseDealMoney(value) {
    if (value == null || value === '') return null;
    let text = String(value).trim();
    if (text.includes(',')) {
      text = text.replace(/\./g, '').replace(',', '.');
    } else {
      text = text.replace(/[^\d.-]/g, '');
    }
    const n = parseFloat(text);
    return Number.isFinite(n) ? n : null;
  }

  function formatDealIntegerInput(value) {
    const n = parseDealInteger(value);
    if (n == null && value !== 0) return '';
    if (n == null) return '0';
    return formatInteger(n);
  }

  function formatDealMoneyInput(value) {
    const n = parseDealMoney(value);
    if (n == null && value !== 0) return '';
    if (n == null) return '0,00';
    return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function readDealValueInput() {
    return parseDealMoney(el('dealValueInput')?.value ?? '');
  }

  function readDealTargetImpressions() {
    return parseDealInteger(el('dealTargetImpressionsInput')?.value ?? '');
  }

  function readDealTargetImpactos() {
    return parseDealInteger(el('dealTargetImpactosInput')?.value ?? '');
  }

  function showAlert(msg, ok) {
    const alert = el('campaignApprovedAlert');
    if (!alert) return;
    alert.className = ok ? 'alert alert-success campaign-panel-alert' : 'alert alert-error campaign-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
  }

  function showDraftStatus(msg) {
    const node = el('campaignDraftStatus');
    if (!node) return;
    node.textContent = msg;
    node.classList.remove('hidden');
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json();
    if (data?.csrf_token && window.ocMakerCsrfToken) window.ocMakerCsrfToken(data.csrf_token);
    if (!res.ok) throw new Error(data.error || 'Erro na requisição');
    return data;
  }

  async function apiGet(params) {
    return fetchJson(`${apiUrl()}?${new URLSearchParams(params)}`);
  }

  async function apiPost(payload) {
    return fetchJson(apiUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify({ ...payload, csrf_token: csrf() }),
    });
  }

  function selectedInventoryIds() {
    return Array.from(document.querySelectorAll('.campaign-deal-unit-check:checked')).map((n) => Number(n.value));
  }

  function selectedInventoryRows() {
    const ids = new Set(selectedInventoryIds());
    return (approvedData?.inventory || []).filter((u) => ids.has(u.inventory_item_id));
  }

  function applyFeeToBase(base, feePercent) {
    const fee = Number(feePercent) || 0;
    if (base <= 0) return 0;
    if (fee === 0) return base;
    const divisor = 1 - fee / 100;
    if (divisor <= 0) return base;
    return base / divisor;
  }

  function suggestedDealBase(rows, dealValueRaw) {
    const sumBruto = rows.reduce((s, u) => s + Number(u.bruto_negociado || 0), 0);
    if (sumBruto > 0) return sumBruto;
    if (dealValueRaw !== '' && dealValueRaw != null) {
      const manual = Number(dealValueRaw);
      if (manual > 0) return manual;
    }
    return 0;
  }

  function computeDealMetrics(rows, slots, dealValue, feePercent) {
    const sumBruto = rows.reduce((s, u) => s + Number(u.bruto_negociado || 0), 0);
    const sumImpactos = rows.reduce((s, u) => s + Number(u.impactos || 0), 0);
    const sumInsercoes = rows.reduce((s, u) => s + Number(u.insercoes || 0), 0);
    const dias = rows.reduce((m, u) => Math.max(m, Number(u.dias || 0)), 0) || 1;
    const faces = rows.reduce((m, u) => Math.max(m, Math.max(1, Number(u.faces || 1))), 1);
    const base = suggestedDealBase(rows, dealValue);
    const suggested = applyFeeToBase(base, feePercent);
    const value = dealValue != null && dealValue !== '' ? Number(dealValue) : suggested;
    let cpm = null;
    if (suggested > 0 && sumImpactos > 0) {
      cpm = (suggested / sumImpactos) * 1000;
    }
    return { sumBruto, sumImpactos, sumInsercoes, dias, faces, suggested, cpm, value };
  }

  function syncDealTargetFieldsFromInventory(force = false) {
    const rows = selectedInventoryRows();
    const impressionsInput = el('dealTargetImpressionsInput');
    const impactosInput = el('dealTargetImpactosInput');
    if (!impressionsInput || !impactosInput) return;
    const m = computeDealMetrics(rows, Number(el('dealSlotsInput')?.value || 1), readDealValueInput() ?? '', el('dealFeeInput')?.value ?? '');
    if (force || impressionsInput.dataset.userEdited !== '1') {
      impressionsInput.value = formatDealIntegerInput(Math.round(m.sumInsercoes));
    }
    if (force || impactosInput.dataset.userEdited !== '1') {
      impactosInput.value = formatDealIntegerInput(Math.round(m.sumImpactos));
    }
    impressionsInput.placeholder = formatInteger(m.sumInsercoes);
    impactosInput.placeholder = formatInteger(m.sumImpactos);
  }

  function updateDealSummary() {
    const panel = el('dealSummaryPanel');
    if (!panel) return;
    const rows = selectedInventoryRows();
    const slots = Number(el('dealSlotsInput')?.value || 1);
    const fee = el('dealFeeInput')?.value ?? '';
    const dealValueParsed = readDealValueInput();
    const m = computeDealMetrics(rows, slots, dealValueParsed ?? '', fee);
    const targetImpressions = readDealTargetImpressions() ?? 0;
    const targetImpactos = readDealTargetImpactos() ?? 0;
    const dealValue = dealValueParsed != null ? dealValueParsed : m.suggested;
    const cpmFromTargets = targetImpactos > 0 && dealValue > 0 ? (dealValue / targetImpactos) * 1000 : null;
    const badge = el('dealUnitsCountBadge');
    if (badge) badge.textContent = `${rows.length} selecionada${rows.length === 1 ? '' : 's'}`;

    panel.innerHTML = `<dl class="campaign-deal-summary-grid">
      <div><dt>Bruto selecionado</dt><dd>${formatBRL(m.sumBruto)}</dd></div>
      <div><dt>Valor sugerido${fee !== '' && Number(fee) !== 0 ? ` (fee ${fee}%)` : ''}</dt><dd>${formatBRL(m.suggested)}</dd></div>
      <div><dt>Deal (inserções)</dt><dd>${formatInteger(targetImpressions)}</dd></div>
      <div><dt>Deal (impactos)</dt><dd>${formatInteger(targetImpactos)}</dd></div>
      <div class="campaign-deal-summary-highlight"><dt>CPM estimado</dt><dd>${cpmFromTargets != null ? formatBRL(cpmFromTargets) : '—'}</dd></div>
    </dl>`;
  }

  function syncDealUnitRowStyles() {
    document.querySelectorAll('.campaign-deal-unit-row').forEach((row) => {
      const cb = row.querySelector('.campaign-deal-unit-check');
      row.classList.toggle('campaign-deal-unit-row--selected', !!cb?.checked);
    });
  }

  function collectDealForm() {
    return {
      id: editingDealId || undefined,
      deal_id: el('dealIdInput')?.value.trim() || '',
      slots: Number(el('dealSlotsInput')?.value || 1),
      screen_type: el('dealScreenTypeInput')?.value.trim() || null,
      deal_value: readDealValueInput(),
      fee_adjust_percent: el('dealFeeInput')?.value !== '' ? Number(el('dealFeeInput')?.value) : 0,
      target_impressions: readDealTargetImpressions(),
      target_impactos: readDealTargetImpactos(),
      notes: el('dealNotesInput')?.value.trim() || '',
      inventory_item_ids: selectedInventoryIds(),
    };
  }

  function scheduleDealDraftSave() {
    if (isReadOnlyMode() || dealViewOnly) return;
    if (!approvedDocId || !window.OcCampaignDraft) return;
    dealDirty = true;
    window.OcCampaignDraft.scheduleSave('deal', approvedDocId, collectDealForm, editingDealId || 'new', 1500);
    showDraftStatus('Rascunho local salvo automaticamente…');
  }

  function renderDealsList() {
    const wrap = el('campaignDealsWrap');
    if (!wrap || !approvedData) return;
    const readOnly = isReadOnlyMode();
    const deals = approvedData.deals || [];
    if (!deals.length) {
      wrap.innerHTML = `<div class="campaign-deal-empty">
        ${window.OcIcons?.svg('clipboard-list', 40) || ''}
        <p><strong>Nenhum Deal cadastrado</strong></p>
        <p class="campaign-deal-empty-hint">${readOnly ? 'Nenhum Deal foi cadastrado nesta campanha.' : 'Use o ícone <strong>+</strong> acima para criar o primeiro Deal desta campanha.'}</p>
      </div>`;
      return;
    }
    wrap.innerHTML = `<div class="campaign-deals-section-head">
      <h3>Deals cadastrados</h3>
      <span class="campaign-deal-count">${deals.length} deal${deals.length === 1 ? '' : 's'}</span>
    </div>
    <div class="users-table-wrap campaign-deals-table-wrap">
      <table class="history-table users-table campaign-deals-table">
        <thead><tr><th>Deal ID</th><th>Slots</th><th>Valor</th><th>CPM</th><th>Unidades</th><th class="history-actions">Ações</th></tr></thead>
        <tbody>${deals.map((d) => `<tr>
          <td><code class="campaign-deal-id">${escapeHtml(d.deal_id)}</code></td>
          <td>${d.slots}</td>
          <td>${formatBRL(d.deal_value)}</td>
          <td>${d.cpm != null ? formatBRL(d.cpm) : '—'}</td>
          <td><span class="campaign-deal-units-pill">${(d.units || []).length}</span></td>
          <td class="history-actions">
            <div class="icon-btn-group">
              ${readOnly
                ? `<button type="button" class="icon-btn icon-btn--brand campaign-deal-view" data-id="${d.id}" title="Visualizar Deal" aria-label="Visualizar Deal">${window.OcIcons?.svg('eye', 18) || '👁'}</button>`
                : `<button type="button" class="icon-btn icon-btn--brand campaign-deal-edit" data-id="${d.id}" title="Editar Deal" aria-label="Editar Deal">${window.OcIcons?.svg('edit', 18) || '✎'}</button>
              ${Number(d.report_count) > 0 ? '' : `<button type="button" class="icon-btn icon-btn--danger campaign-deal-delete" data-id="${d.id}" title="Remover Deal" aria-label="Remover Deal">${window.OcIcons?.svg('trash', 18) || '✗'}</button>`}`}
            </div>
          </td>
        </tr>`).join('')}</tbody>
      </table>
    </div>`;
  }

  function renderInventoryPicker(deal) {
    const inventory = approvedData?.inventory || [];
    const selected = new Set((deal?.units || []).map((u) => Number(u.inventory_item_id)));
    const rows = inventory.map((u) => {
      const checked = selected.has(u.inventory_item_id);
      return `<tr class="campaign-deal-unit-row${checked ? ' campaign-deal-unit-row--selected' : ''}">
        <td class="campaign-deal-check-cell"><input type="checkbox" class="campaign-deal-unit-check" value="${u.inventory_item_id}"${checked ? ' checked' : ''} aria-label="Selecionar ${escapeHtml(u.codigo)}"></td>
        <td><code>${escapeHtml(u.codigo)}</code></td>
        <td class="campaign-deal-denom">${escapeHtml(u.denominacao)}</td>
        <td>${escapeHtml(u.rede_name || '—')}</td>
        <td class="campaign-deal-num">${Number(u.publico || 0).toLocaleString('pt-BR')}</td>
        <td class="campaign-deal-num">${formatInteger(u.impactos)}</td>
        <td class="campaign-deal-num">${formatInteger(u.insercoes)}</td>
        <td class="campaign-deal-num">${formatBRL(u.bruto_negociado)}</td>
        <td class="campaign-deal-num">${formatBRL(u.liquido)}</td>
        <td class="campaign-deal-num">${u.dias}</td>
        <td class="campaign-deal-num">${u.faces}</td>
      </tr>`;
    }).join('');

    return `<div class="campaign-deal-section campaign-deal-section--inventory">
      <div class="campaign-deal-section-head">
        <div>
          <h4>Inventário do Deal</h4>
          <p class="campaign-deal-section-lead">Selecione as unidades aprovadas que entram neste Deal.</p>
        </div>
        <div class="campaign-deal-section-tools">
          <span class="campaign-deal-units-badge" id="dealUnitsCountBadge">0 selecionadas</span>
          <div class="icon-btn-group">
            <button type="button" class="icon-btn icon-btn--brand" id="dealSelectAllBtn" title="Selecionar todas" aria-label="Selecionar todas">${window.OcIcons?.svg('check-all', 18) || '✓'}</button>
            <button type="button" class="icon-btn icon-btn--brand" id="dealClearAllBtn" title="Limpar seleção" aria-label="Limpar seleção">${window.OcIcons?.svg('x', 18) || '✗'}</button>
          </div>
        </div>
      </div>
      <div class="users-table-wrap campaign-deal-units-scroll">
        <table class="history-table users-table campaign-deal-units-table">
          <thead><tr>
            <th class="campaign-deal-check-cell"></th><th>Código</th><th>Denominação</th><th>Rede</th><th>Público</th>
            <th>Impactos</th><th>Inserções</th><th>Bruto</th><th>Líquido</th><th>Dias</th><th>Faces</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>`;
  }

  function bindDealEditorEvents(editor) {
    const onFormChange = () => {
      syncDealTargetFieldsFromInventory(false);
      updateDealSummary();
      scheduleDealDraftSave();
    };

    editor.querySelector('#dealSelectAllBtn')?.addEventListener('click', () => {
      document.querySelectorAll('.campaign-deal-unit-check').forEach((cb) => { cb.checked = true; });
      syncDealUnitRowStyles();
      onFormChange();
    });
    editor.querySelector('#dealClearAllBtn')?.addEventListener('click', () => {
      document.querySelectorAll('.campaign-deal-unit-check').forEach((cb) => { cb.checked = false; });
      syncDealUnitRowStyles();
      onFormChange();
    });
    editor.querySelector('#dealApplySuggestedBtn')?.addEventListener('click', () => {
      const rows = selectedInventoryRows();
      const fee = el('dealFeeInput')?.value ?? '';
      const m = computeDealMetrics(rows, Number(el('dealSlotsInput')?.value || 1), '', fee);
      if (el('dealValueInput')) el('dealValueInput').value = formatDealMoneyInput(m.suggested);
      onFormChange();
    });
    editor.querySelector('#dealApplyInventoryTargetsBtn')?.addEventListener('click', () => {
      const impressionsInput = el('dealTargetImpressionsInput');
      const impactosInput = el('dealTargetImpactosInput');
      if (impressionsInput) impressionsInput.dataset.userEdited = '0';
      if (impactosInput) impactosInput.dataset.userEdited = '0';
      syncDealTargetFieldsFromInventory(true);
      onFormChange();
    });
    editor.querySelector('#dealTargetImpressionsInput')?.addEventListener('input', (e) => {
      e.target.dataset.userEdited = '1';
    });
    editor.querySelector('#dealTargetImpactosInput')?.addEventListener('input', (e) => {
      e.target.dataset.userEdited = '1';
    });
    editor.querySelector('#dealValueInput')?.addEventListener('blur', (e) => {
      e.target.value = formatDealMoneyInput(e.target.value);
      updateDealSummary();
    });
    editor.querySelector('#dealTargetImpressionsInput')?.addEventListener('blur', (e) => {
      e.target.dataset.userEdited = '1';
      e.target.value = formatDealIntegerInput(e.target.value);
      updateDealSummary();
    });
    editor.querySelector('#dealTargetImpactosInput')?.addEventListener('blur', (e) => {
      e.target.dataset.userEdited = '1';
      e.target.value = formatDealIntegerInput(e.target.value);
      updateDealSummary();
    });
    editor.querySelectorAll('.campaign-deal-unit-check').forEach((cb) => {
      cb.addEventListener('change', () => {
        syncDealUnitRowStyles();
        onFormChange();
      });
    });
    editor.querySelectorAll('input, textarea').forEach((input) => {
      input.addEventListener('input', onFormChange);
      input.addEventListener('change', onFormChange);
    });
    editor.querySelector('#dealCancelBtn')?.addEventListener('click', () => {
      editor.classList.add('hidden');
      editor.classList.remove('campaign-deal-editor--readonly');
      editingDealId = 0;
      dealViewOnly = false;
    });
    editor.querySelector('#dealSaveBtn')?.addEventListener('click', () => saveDeal().catch((e) => showAlert(e.message)));
  }

  function openDealEditor(deal, viewOnly = false) {
    dealViewOnly = viewOnly || isReadOnlyMode();
    editingDealId = deal?.id || 0;
    const editor = el('campaignDealEditor');
    if (!editor) return;
    const sumBruto = (approvedData?.inventory || []).reduce((s, u) => s + Number(u.bruto_negociado || 0), 0);
    editor.classList.remove('hidden');
    editor.classList.toggle('campaign-deal-editor--readonly', dealViewOnly);
    editor.innerHTML = `
      <header class="campaign-deal-editor-head">
        <div>
          <span class="campaign-deal-editor-kicker">${dealViewOnly ? 'Visualização' : (editingDealId ? 'Edição' : 'Novo')}</span>
          <h3>${dealViewOnly ? 'Visualizar Deal' : (editingDealId ? 'Editar Deal' : 'Novo Deal')}</h3>
        </div>
        <button type="button" class="icon-btn icon-btn--muted campaign-deal-editor-close" id="dealCancelBtn" title="Fechar editor" aria-label="Fechar editor">${window.OcIcons?.svg('x', 18) || '×'}</button>
      </header>
      <div class="campaign-deal-editor-body">
        <section class="campaign-deal-section">
          <h4>Identificação</h4>
          <div class="campaign-deal-form-grid">
            <label class="campaign-field">
              <span class="campaign-field-label">ID do Deal <span class="req">*</span></span>
              <input type="text" id="dealIdInput" maxlength="128" value="${escapeHtml(deal?.deal_id || '')}" placeholder="Ex.: GD-00000-15321">
            </label>
            <label class="campaign-field">
              <span class="campaign-field-label">Slots</span>
              <input type="number" id="dealSlotsInput" min="1" max="99" value="${deal?.slots || approvedData?.document?.campaign_slots || 1}">
            </label>
            <label class="campaign-field campaign-field--optional">
              <span class="campaign-field-label">Tipo de tela <span class="campaign-field-optional">(opcional)</span></span>
              <input type="text" id="dealScreenTypeInput" maxlength="32" value="${escapeHtml(deal?.screen_type || '')}" placeholder="Ex.: TT">
              <span class="campaign-field-hint">Deixe vazio se o Deal tiver telas de tipos diferentes (mistas).</span>
            </label>
          </div>
        </section>
        <section class="campaign-deal-section">
          <h4>Valores</h4>
          <div class="campaign-deal-form-grid campaign-deal-form-grid--values">
            <label class="campaign-field">
              <span class="campaign-field-label">Ajuste Fee %</span>
              <input type="number" step="0.01" id="dealFeeInput" value="${deal?.fee_adjust_percent ?? ''}" placeholder="0">
            </label>
            <label class="campaign-field">
              <span class="campaign-field-label">Valor do Deal (consumo)</span>
              <div class="campaign-deal-value-row">
                <input type="text" inputmode="decimal" id="dealValueInput" class="campaign-deal-formatted-number" value="${deal?.deal_value != null ? formatDealMoneyInput(deal.deal_value) : ''}" placeholder="${formatDealMoneyInput(sumBruto)}">
                <button type="button" class="btn btn-secondary btn-sm" id="dealApplySuggestedBtn" title="Base ÷ (1 − fee%)">Usar sugerido</button>
              </div>
            </label>
            <label class="campaign-field">
              <span class="campaign-field-label">Impressões do Deal</span>
              <input type="text" inputmode="numeric" id="dealTargetImpressionsInput" class="campaign-deal-formatted-number" value="${deal?.target_impressions != null ? formatDealIntegerInput(deal.target_impressions) : ''}" placeholder="0">
              <span class="campaign-field-hint">Meta usada no controle da campanha. Padrão: soma do inventário selecionado.</span>
            </label>
            <label class="campaign-field">
              <span class="campaign-field-label">Impactos do Deal</span>
              <div class="campaign-deal-value-row">
                <input type="text" inputmode="numeric" id="dealTargetImpactosInput" class="campaign-deal-formatted-number" value="${deal?.target_impactos != null ? formatDealIntegerInput(deal.target_impactos) : ''}" placeholder="0">
                <button type="button" class="btn btn-secondary btn-sm" id="dealApplyInventoryTargetsBtn" title="Recalcular a partir do inventário selecionado">Usar inventário</button>
              </div>
              <span class="campaign-field-hint">Usado no CPM e no pacing do controle.</span>
            </label>
            <label class="campaign-field campaign-field--full">
              <span class="campaign-field-label">Observações</span>
              <textarea id="dealNotesInput" rows="2" placeholder="Notas internas sobre este Deal…">${escapeHtml(deal?.notes || '')}</textarea>
            </label>
          </div>
          <div class="campaign-deal-summary" id="dealSummaryPanel"></div>
        </section>
        ${renderInventoryPicker(deal)}
      </div>
      <footer class="campaign-deal-editor-actions">
        ${dealViewOnly ? '' : `<button type="button" class="btn btn-primary" id="dealSaveBtn">
          <span class="btn-icon" aria-hidden="true">${window.OcIcons?.svg('save', 18) || ''}</span>
          Salvar Deal
        </button>`}
      </footer>`;

    const draft = window.OcCampaignDraft?.restoreIfConfirmed('deal', approvedDocId, editingDealId || 'new');
    if (draft) {
      if (draft.deal_id) el('dealIdInput').value = draft.deal_id;
      if (draft.slots) el('dealSlotsInput').value = draft.slots;
      if (draft.screen_type) el('dealScreenTypeInput').value = draft.screen_type;
      if (draft.deal_value != null) el('dealValueInput').value = formatDealMoneyInput(draft.deal_value);
      if (draft.fee_adjust_percent != null) el('dealFeeInput').value = draft.fee_adjust_percent;
      if (draft.target_impressions != null) el('dealTargetImpressionsInput').value = formatDealIntegerInput(draft.target_impressions);
      if (draft.target_impactos != null) el('dealTargetImpactosInput').value = formatDealIntegerInput(draft.target_impactos);
      if (draft.notes) el('dealNotesInput').value = draft.notes;
      if (Array.isArray(draft.inventory_item_ids)) {
        document.querySelectorAll('.campaign-deal-unit-check').forEach((cb) => {
          cb.checked = draft.inventory_item_ids.includes(Number(cb.value));
        });
      }
    }

    bindDealEditorEvents(editor);
    if (dealViewOnly) {
      editor.querySelectorAll('input, textarea, select, button').forEach((input) => {
        if (input.id === 'dealCancelBtn') return;
        input.disabled = true;
      });
    }
    syncDealUnitRowStyles();
    if (deal?.target_impressions != null || draft?.target_impressions != null) {
      const impressionsInput = el('dealTargetImpressionsInput');
      if (impressionsInput) impressionsInput.dataset.userEdited = '1';
    }
    if (deal?.target_impactos != null || draft?.target_impactos != null) {
      const impactosInput = el('dealTargetImpactosInput');
      if (impactosInput) impactosInput.dataset.userEdited = '1';
    }
    if (!deal?.target_impressions && !draft?.target_impressions && !deal?.target_impactos && !draft?.target_impactos) {
      syncDealTargetFieldsFromInventory(true);
    } else {
      syncDealTargetFieldsFromInventory(false);
    }
    updateDealSummary();
    editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  async function saveDeal() {
    const payload = collectDealForm();
    if (!payload.deal_id) throw new Error('Informe o ID do Deal.');
    const data = await apiPost({ action: 'save_deal', document_id: approvedDocId, ...payload });
    approvedData = data;
    window.OcCampaignDraft?.clear('deal', approvedDocId, editingDealId || 'new');
    dealDirty = false;
    el('campaignDealEditor')?.classList.add('hidden');
    editingDealId = 0;
    renderDealsList();
    renderPlaybook();
    renderControl();
    showAlert('Deal salvo.', true);
    showDraftStatus('');
  }

  const CREATIVE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];
  const CREATIVE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'];
  const CREATIVE_MAX_BYTES = 32 * 1024 * 1024;

  function isCreativeFile(file) {
    if (CREATIVE_MIME_TYPES.includes(file.type)) return true;
    const ext = String(file.name || '').split('.').pop()?.toLowerCase() || '';
    return CREATIVE_EXTENSIONS.includes(ext);
  }

  function filterCreativeFiles(fileList) {
    return Array.from(fileList || []).filter(isCreativeFile);
  }

  function formatFileSize(bytes) {
    const mb = bytes / (1024 * 1024);
    if (mb >= 1) return `${mb.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  const CREATIVE_PLAY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="8 5 19 12 8 19 8 5"/></svg>';
  const STANDARD_SCREEN_RATIOS = [
    { w: 9, h: 16, label: '9:16' },
    { w: 16, h: 9, label: '16:9' },
    { w: 4, h: 3, label: '4:3' },
    { w: 3, h: 4, label: '3:4' },
    { w: 1, h: 1, label: '1:1' },
  ];
  const DOOH_FOOTER_HEIGHTS = [90, 120, 100, 150, 60];

  function gcd(a, b) {
    let x = Math.abs(Math.round(a));
    let y = Math.abs(Math.round(b));
    while (y) {
      const t = y;
      y = x % y;
      x = t;
    }
    return x || 1;
  }

  function ratioMatches(actual, expected, tolerance = 0.015) {
    if (!expected) return false;
    return Math.abs(actual - expected) / expected <= tolerance;
  }

  function resolveCreativeScreenLayout(width, height) {
    const w = Number(width) || 0;
    const h = Number(height) || 0;
    if (w <= 0 || h <= 0) {
      return {
        aspectW: 9,
        aspectH: 16,
        contentFlex: 1,
        footerFlex: 0,
        hasFooter: false,
        label: '9:16',
        screenWidth: null,
        screenHeight: null,
        footerHeight: 0,
      };
    }

    for (const std of STANDARD_SCREEN_RATIOS) {
      for (const footer of DOOH_FOOTER_HEIGHTS) {
        const totalH = h + footer;
        const actualRatio = w / totalH;
        const stdRatio = std.w / std.h;
        if (ratioMatches(actualRatio, stdRatio)) {
          return {
            aspectW: std.w,
            aspectH: std.h,
            contentFlex: h,
            footerFlex: footer,
            hasFooter: true,
            label: std.label,
            screenWidth: w,
            screenHeight: totalH,
            footerHeight: footer,
          };
        }
      }
    }

    for (const std of STANDARD_SCREEN_RATIOS) {
      const actualRatio = w / h;
      const stdRatio = std.w / std.h;
      if (ratioMatches(actualRatio, stdRatio)) {
        return {
          aspectW: std.w,
          aspectH: std.h,
          contentFlex: h,
          footerFlex: 0,
          hasFooter: false,
          label: std.label,
          screenWidth: w,
          screenHeight: h,
          footerHeight: 0,
        };
      }
    }

    const g = gcd(w, h);
    return {
      aspectW: w / g,
      aspectH: h / g,
      contentFlex: h,
      footerFlex: 0,
      hasFooter: false,
      label: `${w / g}:${h / g}`,
      screenWidth: w,
      screenHeight: h,
      footerHeight: 0,
    };
  }

  function creativePreviewMetaText(c, layout) {
    const isVideo = String(c.mime_type || '').startsWith('video/');
    const parts = [];
    if (c.width && c.height) parts.push(`${c.width}px × ${c.height}px`);
    if (isVideo) {
      const timing = [c.duration_label, c.frame_rate_label].filter(Boolean).join(' · ');
      if (timing) parts.push(timing);
    }
    if (layout.hasFooter && layout.screenWidth && layout.screenHeight) {
      parts.push(`Simulação ${layout.label} (${layout.screenWidth}×${layout.screenHeight})`);
    } else if (layout.label) {
      parts.push(`Proporção ${layout.label}`);
    }
    return parts.join(' · ');
  }

  function closeCreativePreview() {
    const modal = el('campaignCreativePreviewModal');
    const content = el('campaignCreativePreviewContent');
    if (!modal) return;

    content?.querySelector('video')?.pause();
    if (content) content.innerHTML = '';
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
  }

  function openCreativePreview(creativeId) {
    const creative = (approvedData?.creatives || []).find((c) => Number(c.id) === Number(creativeId));
    const modal = el('campaignCreativePreviewModal');
    const panel = modal?.querySelector('.campaign-creative-preview-panel');
    const screen = el('campaignCreativePreviewScreen');
    const content = el('campaignCreativePreviewContent');
    const footer = el('campaignCreativePreviewFooter');
    const footerLabel = el('campaignCreativePreviewFooterLabel');
    if (!creative || !modal || !screen || !content || !footer || !footerLabel) return;

    const layout = resolveCreativeScreenLayout(creative.width, creative.height);
    const isVideo = String(creative.mime_type || '').startsWith('video/');

    el('campaignCreativePreviewTitle').textContent = creative.file_name || 'Criativo';
    el('campaignCreativePreviewMeta').textContent = creativePreviewMetaText(creative, layout);

    screen.style.setProperty('--screen-aspect-w', String(layout.aspectW));
    screen.style.setProperty('--screen-aspect-h', String(layout.aspectH));
    screen.style.setProperty('--content-flex', String(layout.contentFlex));
    screen.style.setProperty('--footer-flex', String(layout.footerFlex));

    panel?.classList.toggle('campaign-creative-preview-panel--landscape', layout.aspectW > layout.aspectH);

    content.innerHTML = isVideo
      ? `<video src="${escapeHtml(creative.url)}" autoplay loop muted playsinline></video>`
      : `<img src="${escapeHtml(creative.url)}" alt="${escapeHtml(creative.file_name || 'Criativo')}">`;

    if (layout.hasFooter) {
      footer.classList.remove('hidden');
      footerLabel.textContent = layout.footerHeight
        ? `Área reservada (${layout.screenWidth}px × ${layout.footerHeight}px)`
        : 'Área reservada';
    } else {
      footer.classList.add('hidden');
      footerLabel.textContent = '';
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');

    const video = content.querySelector('video');
    if (video) {
      video.play().catch(() => {});
    }
  }

  function creativeMetaHtml(c) {
    const isVideo = String(c.mime_type || '').startsWith('video/');
    const widthLabel = c.width_label || (c.width ? `${c.width}px` : '');
    const heightLabel = c.height_label || (c.height ? `${c.height}px` : '');
    const lines = [];

    if (widthLabel && heightLabel) {
      lines.push(`${escapeHtml(widthLabel)} × ${escapeHtml(heightLabel)}`);
    }

    if (isVideo) {
      const timing = [c.duration_label, c.frame_rate_label].filter(Boolean).join(' · ');
      if (timing) lines.push(escapeHtml(timing));
    }

    if (!lines.length) {
      return '<span class="campaign-creative-meta-line">—</span>';
    }

    return lines.map((line) => `<span class="campaign-creative-meta-line">${line}</span>`).join('');
  }

  function validateCreativeFiles(files) {
    const accepted = filterCreativeFiles(files);
    if (!files?.length) return { ok: false, files: [], message: '' };
    if (!accepted.length) {
      return {
        ok: false,
        files: [],
        message: 'Formato não suportado. Use imagem (JPG, PNG, GIF, WebP) ou vídeo (MP4, WebM).',
      };
    }
    const tooLarge = accepted.find((file) => file.size > CREATIVE_MAX_BYTES);
    if (tooLarge) {
      return {
        ok: false,
        files: [],
        message: `"${tooLarge.name}" (${formatFileSize(tooLarge.size)}) excede o limite de 32 MB por arquivo.`,
      };
    }
    return { ok: true, files: accepted, message: '' };
  }

  function renderCreatives() {
    const gallery = el('campaignCreativesGallery');
    const dropZone = el('campaignCreativesDropZone');
    if (!gallery || !approvedData) return;
    const readOnly = isReadOnlyMode();
    dropZone?.classList.toggle('campaign-creatives-dropzone--readonly', readOnly);
    const items = approvedData.creatives || [];
    if (!items.length) {
      const uploadIcon = window.OcIcons?.svg('upload', 32) || '↑';
      gallery.innerHTML = `<div class="campaign-creatives-empty">
        <span class="campaign-creatives-empty-icon" aria-hidden="true">${readOnly ? (window.OcIcons?.svg('image', 32) || '🖼') : uploadIcon}</span>
        <p>${readOnly ? 'Nenhum criativo cadastrado' : 'Arraste e solte os criativos aqui'}</p>
        <p class="campaign-creatives-empty-hint">${readOnly ? 'Visualização apenas — campanha finalizada.' : 'JPG, PNG, GIF, WebP, MP4 ou WebM — até 32 MB por arquivo — ou clique para selecionar'}</p>
      </div>`;
      return;
    }
    gallery.innerHTML = items.map((c) => {
      const isVideo = String(c.mime_type || '').startsWith('video/');
      const badge = isVideo
        ? `<span class="campaign-creative-play-badge" aria-hidden="true">${CREATIVE_PLAY_ICON}</span>`
        : `<span class="campaign-creative-play-badge" aria-hidden="true">${window.OcIcons?.svg('eye', 28) || '👁'}</span>`;
      const preview = isVideo
        ? `<video src="${escapeHtml(c.url)}" muted playsinline preload="metadata" class="campaign-creative-thumb"></video>`
        : `<img src="${escapeHtml(c.url)}" alt="${escapeHtml(c.file_name)}" class="campaign-creative-thumb">`;
      return `<article class="campaign-creative-card">
        <button type="button" class="campaign-creative-preview-btn" data-creative-id="${c.id}" title="Simular na tela" aria-label="Simular ${escapeHtml(c.file_name)} na tela">
          ${preview}
          ${badge}
        </button>
        <div class="campaign-creative-meta">
          <strong>${escapeHtml(c.file_name)}</strong>
          <div class="campaign-creative-meta-details">${creativeMetaHtml(c)}</div>
          ${readOnly ? '' : `<button type="button" class="icon-btn icon-btn--danger campaign-creative-delete" data-id="${c.id}" title="Remover">${window.OcIcons?.svg('trash', 16) || '✗'}</button>`}
        </div>
      </article>`;
    }).join('');
  }

  async function copyText(text) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  function flashCopyButton(button) {
    if (!button) return;
    button.classList.add('campaign-playbook-copy--done');
    window.setTimeout(() => button.classList.remove('campaign-playbook-copy--done'), 1200);
  }

  function copyIcon() {
    return window.OcIcons?.svg('copy', 16) || '⎘';
  }

  function markPlaybookRowCopied(button, code) {
    copiedPlaybookCodes.add(code);
    const row = button?.closest('.campaign-playbook-code-row, .campaign-playbook-name-row');
    if (row) {
      row.classList.add('campaign-playbook-code-row--copied');
      row.setAttribute('aria-label', 'Texto já copiado');
    }
  }

  function renderPlaybookNameRow(name) {
    const copied = copiedPlaybookCodes.has(name);
    return `<li class="campaign-playbook-name-row${copied ? ' campaign-playbook-code-row--copied' : ''}"${copied ? ' aria-label="Texto já copiado"' : ''}>
      <code class="campaign-playbook-name">${escapeHtml(name)}</code>
      <button type="button" class="campaign-playbook-copy campaign-playbook-copy--one" data-copy-code="${encodeURIComponent(name)}" title="${copied ? 'Copiado' : 'Copiar nome'}" aria-label="Copiar ${escapeHtml(name)}">${copyIcon()}</button>
    </li>`;
  }

  function renderScreenCodesBlock(title, codes, { bulkCopy = false, cmsKey = '' } = {}) {
    if (!codes?.length) return '';
    const rows = codes.map((code) => {
      const copied = copiedPlaybookCodes.has(code);
      return `<li class="campaign-playbook-code-row${copied ? ' campaign-playbook-code-row--copied' : ''}"${copied ? ' aria-label="Código já copiado"' : ''}>
      <code class="campaign-playbook-code">${escapeHtml(code)}</code>
      <button type="button" class="campaign-playbook-copy campaign-playbook-copy--one" data-copy-code="${encodeURIComponent(code)}" title="${copied ? 'Copiado' : 'Copiar código'}" aria-label="Copiar ${escapeHtml(code)}">${copyIcon()}</button>
    </li>`;
    }).join('');
    const bulkBtn = bulkCopy
      ? `<button type="button" class="btn btn-secondary btn-sm campaign-playbook-copy-all" data-copy-all-cms="${escapeHtml(cmsKey)}" title="Copiar todos os códigos">${copyIcon()} Copiar todos</button>`
      : '';
    return `<div class="campaign-playbook-codes">
      <div class="campaign-playbook-codes-head">
        <strong>${escapeHtml(title)}</strong>
        <span class="campaign-playbook-codes-count">${codes.length} ${codes.length === 1 ? 'tela' : 'telas'}</span>
        ${bulkBtn}
      </div>
      <ul class="campaign-playbook-codes-list">${rows}</ul>
    </div>`;
  }

  function renderPlaybookStep(text) {
    const lines = String(text).split('\n');
    if (lines.length === 1) {
      const single = lines[0];
      const inlineMatch = single.match(/^(Grupo Deal .+:|Na pasta da campanha, criar Apps? "Anúncio programático":|Nome:|Descrição sugerida:)\s+(.+)$/);
      if (inlineMatch) {
        return `<li class="campaign-playbook-step-multiline">
          <span class="campaign-playbook-step-lead">${escapeHtml(inlineMatch[1])}</span>
          <ul class="campaign-playbook-name-list">${renderPlaybookNameRow(inlineMatch[2])}</ul>
        </li>`;
      }
      return `<li>${escapeHtml(single)}</li>`;
    }
    const head = escapeHtml(lines[0]);
    const names = lines.slice(1).filter(Boolean).map((line) => renderPlaybookNameRow(line)).join('');
    return `<li class="campaign-playbook-step-multiline">
      <span class="campaign-playbook-step-lead">${head}</span>
      <ul class="campaign-playbook-name-list">${names}</ul>
    </li>`;
  }

  function formatDateBR(iso) {
    if (!iso) return '—';
    const parts = String(iso).slice(0, 10).split('-');
    if (parts.length !== 3) return iso;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  }

  function pct(actual, target) {
    if (!target || target <= 0) return null;
    return Math.round((Number(actual || 0) / Number(target)) * 1000) / 10;
  }

  function renderControlMetric(label, actual, target, formatter = formatInteger) {
    const p = pct(actual, target);
    return `<div class="campaign-control-metric">
      <dt>${escapeHtml(label)}</dt>
      <dd>${formatter(actual)} <span class="campaign-control-metric-target">/ ${formatter(target)}</span></dd>
      ${p != null ? `<span class="campaign-control-metric-pct">${p}%</span>` : ''}
    </div>`;
  }

  function pacingMetricFor(scopeId) {
    return pacingMetrics.get(scopeId) || 'impactos';
  }

  function targetForMetric(targets, metric) {
    if (metric === 'impressoes') return Number(targets?.impressions || 0);
    if (metric === 'impactos') return Number(targets?.impactos || 0);
    return Number(targets?.consumo || 0);
  }

  function computePacingInsights(pacing, metric, targetTotal, dateList) {
    if (!pacing?.has_actual || !targetTotal) return null;

    const actualCum = pacing.actual_cumulative?.[metric] || [];
    const plannedCum = pacing.planned_cumulative?.[metric] || [];
    const ma7Series = pacing.moving_avg_7_daily?.[metric] || [];
    const projected = pacing.projected_cumulative?.[metric] || [];

    let lastIdx = -1;
    for (let i = actualCum.length - 1; i >= 0; i -= 1) {
      if (Number(actualCum[i]) > 0) {
        lastIdx = i;
        break;
      }
    }
    if (lastIdx < 0) return null;

    const actual = Number(actualCum[lastIdx]) || 0;
    const planned = Number(plannedCum[lastIdx]) || 0;
    const mm7 = ma7Series[lastIdx] != null ? Number(ma7Series[lastIdx]) : null;
    const gap = targetTotal - actual;

    let projectedFinish = null;
    let projectedStatus = 'unknown';
    if (actual >= targetTotal) {
      projectedStatus = 'done';
    } else {
      for (let i = Math.max(0, lastIdx); i < projected.length; i += 1) {
        const value = projected[i];
        if (value == null) continue;
        if (Number(value) >= targetTotal) {
          projectedFinish = dateList[i] || null;
          projectedStatus = 'on_track';
          break;
        }
      }
      if (projectedStatus === 'unknown') {
        const lastProj = [...projected].reverse().find((v) => v != null);
        if (lastProj != null && Number(lastProj) < targetTotal) {
          projectedStatus = 'shortfall';
        }
      }
    }

    return {
      actual,
      targetTotal,
      pacingPct: pct(actual, targetTotal),
      vsPlannedPct: pct(actual, planned),
      mm7,
      gap,
      projectedFinish,
      projectedStatus,
      isMoney: metric === 'consumo',
    };
  }

  function formatPacingInsightValue(value, isMoney) {
    return isMoney ? formatBRL(value) : formatInteger(value);
  }

  function renderPacingInsights(scopeId, pacing, targets) {
    const host = document.getElementById(`pacing-insights-${scopeId}`);
    if (!host) return;

    const metric = pacingMetricFor(scopeId);
    const insights = computePacingInsights(
      pacing,
      metric,
      targetForMetric(targets, metric),
      buildCampaignDateIsoList(),
    );

    if (!insights) {
      host.innerHTML = '';
      host.classList.add('hidden');
      return;
    }

    host.classList.remove('hidden');
    const metricLabel = metric === 'impressoes' ? 'Impressões' : metric === 'impactos' ? 'Impactos' : 'Consumo';
    const fmt = (v) => formatPacingInsightValue(v, insights.isMoney);

    let projectionText = '—';
    if (insights.projectedStatus === 'done') {
      projectionText = 'Meta já atingida';
    } else if (insights.projectedFinish) {
      projectionText = formatDateBR(insights.projectedFinish);
    } else if (insights.projectedStatus === 'shortfall') {
      projectionText = 'Abaixo da meta no período';
    }

    const gapLabel = insights.gap > 0 ? 'Faltam' : insights.gap < 0 ? 'Excedente' : 'Na meta';

    host.innerHTML = `<p class="campaign-pacing-insights-kicker">${escapeHtml(metricLabel)}</p>
      <dl class="campaign-pacing-insights">
        <div class="campaign-pacing-insight">
          <dt>% da meta</dt>
          <dd>${insights.pacingPct != null ? `${insights.pacingPct}%` : '—'}</dd>
        </div>
        <div class="campaign-pacing-insight">
          <dt>vs planejado (acum.)</dt>
          <dd>${insights.vsPlannedPct != null ? `${insights.vsPlannedPct}%` : '—'}</dd>
        </div>
        <div class="campaign-pacing-insight">
          <dt>MM7 (média 7d)</dt>
          <dd>${insights.mm7 != null ? fmt(insights.mm7) : '—'}</dd>
        </div>
        <div class="campaign-pacing-insight">
          <dt>Projeção de fechamento</dt>
          <dd>${escapeHtml(projectionText)}</dd>
        </div>
        <div class="campaign-pacing-insight campaign-pacing-insight--gap">
          <dt>${escapeHtml(gapLabel)}</dt>
          <dd>${fmt(Math.abs(insights.gap))}</dd>
        </div>
      </dl>`;
  }

  function refreshPacingInsights(scopeId, pacing, targets) {
    renderPacingInsights(scopeId, pacing, targets || {});
  }

  function refreshAllPacingInsights() {
    const control = approvedData?.control;
    if (!control) return;
    refreshPacingInsights('campaign', control.campaign?.pacing, control.campaign?.targets);
    (control.deals || []).forEach((deal) => {
      refreshPacingInsights(`deal-${deal.deal_db_id}`, pacingForDeal(deal), filteredDealTargets(deal));
    });
  }

  function renderPacingChartsBlock(scopeId, pacing, title) {
    if (!pacing?.labels?.length) {
      return `<div class="campaign-pacing-empty">Defina início e término da campanha para exibir a curva planejada.</div>`;
    }
    const metric = pacingMetricFor(scopeId);
    const hasActual = !!pacing.has_actual;
    return `<div class="campaign-pacing-block" data-pacing-scope="${scopeId}">
      <div class="campaign-pacing-block-head">
        <h4>${escapeHtml(title)}</h4>
        <div class="campaign-pacing-metric-tabs" role="tablist" aria-label="Métrica do gráfico">
          ${['impactos', 'impressoes', 'consumo'].map((m) => {
            const label = m === 'impactos' ? 'Impactos' : m === 'impressoes' ? 'Impressões' : 'Consumo';
            return `<button type="button" class="campaign-pacing-metric-tab${metric === m ? ' campaign-pacing-metric-tab--active' : ''}" data-metric="${m}" data-scope="${scopeId}" role="tab">${label}</button>`;
          }).join('')}
        </div>
      </div>
      <div class="campaign-pacing-legend">
        <span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--meta"></i> Meta (planejado)</span>
        ${hasActual
          ? `<span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--actual"></i> Realizado</span>
             <span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--ma7"></i> MM7</span>
             <span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--projection"></i> Projeção</span>`
          : '<span class="campaign-pacing-legend-pending">Realizado, MM7 e projeção aparecem após importar relatórios</span>'}
      </div>
      ${hasActual ? `<div id="pacing-insights-${scopeId}" class="campaign-pacing-insights-wrap"></div>` : ''}
      <div class="campaign-pacing-chart-grid">
        <div class="campaign-pacing-chart-box">
          <span class="campaign-pacing-chart-label">Pacing acumulado</span>
          <canvas id="pacing-${scopeId}-cumulative" class="campaign-pacing-canvas" aria-label="Pacing acumulado"></canvas>
        </div>
        <div class="campaign-pacing-chart-box">
          <span class="campaign-pacing-chart-label">Realizado diário</span>
          <canvas id="pacing-${scopeId}-daily" class="campaign-pacing-canvas" aria-label="Realizado diário"></canvas>
        </div>
      </div>
    </div>`;
  }

  function buildCampaignDateIsoList() {
    const camp = approvedData?.control?.campaign || {};
    const inicio = camp.inicio;
    const termino = camp.termino;
    if (!inicio || !termino) return [];

    const dates = [];
    const start = new Date(`${String(inicio).slice(0, 10)}T12:00:00`);
    const end = new Date(`${String(termino).slice(0, 10)}T12:00:00`);
    for (let cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
      const y = cursor.getFullYear();
      const m = String(cursor.getMonth() + 1).padStart(2, '0');
      const day = String(cursor.getDate()).padStart(2, '0');
      dates.push(`${y}-${m}-${day}`);
    }
    return dates;
  }

  function activeDealFilters(dealDbId) {
    const network = reportNetworkFilters.get(reportPrefsKey(dealDbId)) || '';
    return { network: network || null };
  }

  function dealConfig(dealDbId) {
    return (approvedData?.deals || []).find((d) => Number(d.id) === Number(dealDbId)) || null;
  }

  function dealInventoryRowsForFilters(dealDbId, filters = {}) {
    const config = dealConfig(dealDbId);
    const unitIds = new Set((config?.units || []).map((u) => Number(u.inventory_item_id)));
    let rows = (approvedData?.inventory || []).filter((u) => unitIds.has(u.inventory_item_id));
    if (filters.network) {
      rows = rows.filter((u) => (u.rede_name || '') === filters.network);
    }
    return rows;
  }

  function filteredDealReports(deal) {
    const filters = activeDealFilters(deal.deal_db_id);
    const reports = deal.reports || [];
    if (!filters.network) return reports;
    return reports.filter((row) => (row.network || '') === filters.network);
  }

  function filteredDealTargets(deal) {
    const filters = activeDealFilters(deal.deal_db_id);
    if (!filters.network) return deal.targets;

    const rows = dealInventoryRowsForFilters(deal.deal_db_id, filters);
    const fee = Number(dealConfig(deal.deal_db_id)?.fee_adjust_percent) || 0;
    const sumBruto = rows.reduce((s, u) => s + Number(u.bruto_negociado || 0), 0);
    const consumo = Math.round(applyFeeToBase(sumBruto, fee) * 100) / 100;

    return {
      impressions: rows.reduce((s, u) => s + Number(u.insercoes || 0), 0),
      impactos: rows.reduce((s, u) => s + Number(u.impactos || 0), 0),
      consumo,
    };
  }

  function filteredDealDailyTargets(deal) {
    const filters = activeDealFilters(deal.deal_db_id);
    if (!filters.network) return deal.daily_targets;

    const daysTotal = buildCampaignDateIsoList().length;
    if (!daysTotal) return deal.daily_targets;

    const t = filteredDealTargets(deal);
    return {
      impressions: Math.round(t.impressions / daysTotal),
      impactos: Math.round(t.impactos / daysTotal),
      consumo: Math.round((t.consumo / daysTotal) * 100) / 100,
    };
  }

  function consumoFromDealCpm(impactos, dealDbId) {
    const cpm = Number(dealConfig(dealDbId)?.cpm ?? 0);
    if (!(cpm > 0)) return null;
    return Math.round(Number(impactos) * cpm / 1000 * 100) / 100;
  }

  function filteredDealActual(deal) {
    const filters = activeDealFilters(deal.deal_db_id);
    if (!filters.network) return deal.actual;

    const reports = filteredDealReports(deal);
    const impactos = Math.round(reports.reduce((s, r) => s + (Number(r.impactos) || 0), 0));
    const derivedConsumo = consumoFromDealCpm(impactos, deal.deal_db_id);
    return {
      requisicoes: Math.round(reports.reduce((s, r) => s + (Number(r.requisicoes) || 0), 0)),
      impressoes: Math.round(reports.reduce((s, r) => s + (Number(r.impressoes) || 0), 0)),
      impactos,
      consumo: derivedConsumo != null
        ? derivedConsumo
        : Math.round(reports.reduce((s, r) => s + (Number(r.consumo) || 0), 0) * 100) / 100,
    };
  }

  function pacingForDeal(deal) {
    const filters = activeDealFilters(deal.deal_db_id);
    if (!filters.network || !window.OcPacingCharts?.recomputeActual) return deal.pacing;
    return window.OcPacingCharts.recomputeActual(
      deal.pacing,
      filteredDealReports(deal),
      buildCampaignDateIsoList(),
      filteredDealTargets(deal),
    );
  }

  function collectPacingScopes() {
    const control = approvedData?.control;
    if (!control) return [];
    const scopes = [{ scopeId: 'campaign', pacing: control.campaign?.pacing, metric: pacingMetricFor('campaign') }];
    (control.deals || []).forEach((deal) => {
      const scopeId = `deal-${deal.deal_db_id}`;
      scopes.push({ scopeId, pacing: pacingForDeal(deal), metric: pacingMetricFor(scopeId) });
    });
    return scopes;
  }

  function refreshDealControlMetrics(dealDbId) {
    const deal = (approvedData?.control?.deals || []).find((d) => Number(d.deal_db_id) === Number(dealDbId));
    const article = document.querySelector(`.campaign-control-deal[data-deal-id="${dealDbId}"]`);
    if (!deal || !article) return;

    const targets = filteredDealTargets(deal);
    const actual = filteredDealActual(deal);
    const dailyTargets = filteredDealDailyTargets(deal);
    const networkFilter = activeDealFilters(deal.deal_db_id).network;
    const filterScope = networkFilter ? ` (${networkFilter})` : '';

    const metrics = article.querySelector('.campaign-control-metrics');
    if (metrics) {
      metrics.innerHTML = `
        ${renderControlMetric('Impressões', actual?.impressoes, targets?.impressions)}
        ${renderControlMetric('Impactos', actual?.impactos, targets?.impactos)}
        ${renderControlMetric('Consumo', actual?.consumo, targets?.consumo, formatBRL)}
      `;
    }

    const dailyHint = article.querySelector('.campaign-control-daily-hint');
    if (dailyHint) {
      dailyHint.textContent = `Meta diária${filterScope}: ${formatInteger(dailyTargets?.impressions)} imp · ${formatInteger(dailyTargets?.impactos)} impactos · ${formatBRL(dailyTargets?.consumo)}`;
    }
  }

  function refreshDealPacingCharts(dealDbId) {
    const deal = (approvedData?.control?.deals || []).find((d) => Number(d.deal_db_id) === Number(dealDbId));
    if (!deal || !window.OcPacingCharts) return;
    const scopeId = `deal-${dealDbId}`;
    const pacing = pacingForDeal(deal);
    const networkFilter = activeDealFilters(deal.deal_db_id).network;
    const metaLabel = networkFilter ? `Meta (planejado — ${networkFilter})` : 'Meta (planejado)';
    const legend = document.querySelector(`.campaign-pacing-block[data-pacing-scope="${scopeId}"] .campaign-pacing-legend`);
    if (legend) {
      legend.innerHTML = `<span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--meta"></i> ${escapeHtml(metaLabel)}</span>${
        pacing?.has_actual
          ? `<span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--actual"></i> Realizado</span>
             <span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--ma7"></i> MM7</span>
             <span><i class="campaign-pacing-legend-line campaign-pacing-legend-line--projection"></i> Projeção</span>`
          : '<span class="campaign-pacing-legend-pending">Realizado, MM7 e projeção aparecem após importar relatórios</span>'
      }`;
    }
    window.OcPacingCharts.renderScope(scopeId, pacing, pacingMetricFor(scopeId));
    refreshPacingInsights(scopeId, pacing, filteredDealTargets(deal));
  }

  function renderPacingCharts() {
    if (!window.OcPacingCharts || approvedTab !== 'control') return;
    window.OcPacingCharts.renderAll(collectPacingScopes());
    refreshAllPacingInsights();
  }

  function defaultReportColumnPrefs(hasScreens, hasNetworks, estimated = false, networksFromInventory = false) {
    const prefs = {};
    REPORT_COLUMN_DEFS.forEach((col) => {
      prefs[col.id] = col.defaultVisible;
    });
    if (!hasScreens) prefs.screen = false;
    if (!hasNetworks) prefs.network = false;
    if (estimated || networksFromInventory) {
      if (hasNetworks) prefs.network = true;
      if (hasScreens && estimated) prefs.screen = true;
    }
    return prefs;
  }

  function reportPrefsKey(dealDbId) {
    return Number(dealDbId) || 0;
  }

  function getReportColumnPrefs(dealDbId, hasScreens, hasNetworks, estimated = false, networksFromInventory = false) {
    const stored = reportColumnPrefs.get(reportPrefsKey(dealDbId)) || {};
    const prefs = { ...defaultReportColumnPrefs(hasScreens, hasNetworks, estimated, networksFromInventory), ...stored };
    if (!hasScreens) prefs.screen = false;
    if (!hasNetworks) prefs.network = false;
    return prefs;
  }

  function aggregateReports(reports, prefs, deal) {
    const hasScreens = !!deal.report_has_screens;
    const hasNetworks = !!deal.report_has_networks;
    const showDate = prefs.date !== false;
    const showScreen = hasScreens && prefs.screen === true;
    const showNetwork = hasNetworks && prefs.network === true;
    if (!reports.length) return [];

    /** @type {Map<string, Record<string, unknown>>} */
    const buckets = new Map();

    reports.forEach((row) => {
      const parts = [];
      if (showDate) parts.push(String(row.report_date || ''));
      if (showNetwork) parts.push(String(row.network || '—'));
      if (showScreen) parts.push(String(row.screen_code || '—'));
      const key = parts.length ? parts.join('|') : '__total__';

      if (!buckets.has(key)) {
        buckets.set(key, {
          report_date: showDate ? row.report_date : null,
          network: showNetwork ? (row.network || '') : '',
          screen_code: showScreen ? (row.screen_code || '') : '',
          requisicoes: 0,
          impressoes: 0,
          impactos: 0,
          consumo: 0,
          _hasReq: false,
          _hasImp: false,
          _hasImpacts: false,
          _hasConsumo: false,
        });
      }

      const bucket = buckets.get(key);
      ['requisicoes', 'impressoes', 'impactos', 'consumo'].forEach((metric) => {
        if (row[metric] == null || row[metric] === '') return;
        bucket[metric] += Number(row[metric]) || 0;
        bucket[`_has${metric === 'requisicoes' ? 'Req' : metric === 'impressoes' ? 'Imp' : metric === 'impactos' ? 'Impacts' : 'Consumo'}`] = true;
      });
    });

    return Array.from(buckets.values());
  }

  function applyDefaultReportSort(rows, prefs, deal) {
    const hasScreens = !!deal.report_has_screens;
    const hasNetworks = !!deal.report_has_networks;
    const showDate = prefs.date !== false;
    const showScreen = hasScreens && prefs.screen === true;
    const showNetwork = hasNetworks && prefs.network === true;

    return [...rows].sort((a, b) => {
      if (showDate && a.report_date !== b.report_date) {
        return String(b.report_date).localeCompare(String(a.report_date));
      }
      if (showNetwork && a.network !== b.network) {
        return String(a.network).localeCompare(String(b.network), 'pt-BR');
      }
      if (showScreen) {
        return String(a.screen_code).localeCompare(String(b.screen_code), 'pt-BR');
      }
      return 0;
    });
  }

  function reportRowSortValue(row, columnId) {
    switch (columnId) {
      case 'date':
        return row.report_date || '';
      case 'screen':
        return row.screen_code || '';
      case 'network':
        return row.network || '';
      case 'requisicoes':
        return row._hasReq ? Number(row.requisicoes) : null;
      case 'impressoes':
        return row._hasImp ? Number(row.impressoes) : null;
      case 'impactos':
        return row._hasImpacts ? Number(row.impactos) : null;
      case 'consumo':
        return row._hasConsumo ? Number(row.consumo) : null;
      default:
        return '';
    }
  }

  function compareReportRows(a, b, columnId, direction) {
    const va = reportRowSortValue(a, columnId);
    const vb = reportRowSortValue(b, columnId);

    let cmp = 0;
    if (typeof va === 'number' && typeof vb === 'number') {
      cmp = va - vb;
    } else if (va == null && vb != null) {
      cmp = 1;
    } else if (va != null && vb == null) {
      cmp = -1;
    } else {
      cmp = String(va ?? '').localeCompare(String(vb ?? ''), 'pt-BR', { numeric: true, sensitivity: 'base' });
    }

    return direction === 'desc' ? -cmp : cmp;
  }

  function applyReportTableSort(rows, dealDbId, prefs, deal) {
    const sort = reportTableSort.get(reportPrefsKey(dealDbId));
    if (!sort) {
      return applyDefaultReportSort(rows, prefs, deal);
    }

    return [...rows].sort((a, b) => compareReportRows(a, b, sort.column, sort.direction));
  }

  function cycleReportSort(dealDbId, columnId) {
    const key = reportPrefsKey(dealDbId);
    const current = reportTableSort.get(key);
    if (!current || current.column !== columnId) {
      reportTableSort.set(key, { column: columnId, direction: 'asc' });
      return;
    }
    if (current.direction === 'asc') {
      reportTableSort.set(key, { column: columnId, direction: 'desc' });
      return;
    }
    reportTableSort.delete(key);
  }

  function renderReportSortHeader(col, dealDbId) {
    const sort = reportTableSort.get(reportPrefsKey(dealDbId));
    const active = sort?.column === col.id;
    const dir = active ? sort.direction : null;
    const indicator = dir === 'asc' ? '↑' : dir === 'desc' ? '↓' : '↕';
    const ariaSort = dir ? ` aria-sort="${dir === 'asc' ? 'ascending' : 'descending'}"` : ' aria-sort="none"';

    return `<th class="campaign-control-sort-th" data-sort-col="${col.id}" data-deal-id="${dealDbId}"${ariaSort} role="columnheader" tabindex="0" title="Clique para ordenar">
      ${escapeHtml(col.label)}<span class="campaign-control-sort-indicator${active ? ' campaign-control-sort-indicator--active' : ''}">${indicator}</span>
    </th>`;
  }

  function formatReportMetric(metric, row) {
    const flags = {
      requisicoes: '_hasReq',
      impressoes: '_hasImp',
      impactos: '_hasImpacts',
      consumo: '_hasConsumo',
    };
    if (!row[flags[metric]]) return '—';
    if (metric === 'consumo') return formatBRL(row.consumo);
    return formatInteger(row[metric]);
  }

  function visibleReportColumns(prefs, hasScreens, hasNetworks) {
    return REPORT_COLUMN_DEFS.filter((col) => {
      if (col.requiresScreens && !hasScreens) return false;
      if (col.requiresNetworks && !hasNetworks) return false;
      return prefs[col.id] !== false;
    });
  }

  function estimatedCellSuffix(estimated) {
    return estimated ? ' <span class="campaign-control-estimated-mark" title="Valor estimado">~</span>' : '';
  }

  function renderReportTableHtml(deal) {
    const dealDbId = deal.deal_db_id;
    const hasScreens = !!deal.report_has_screens;
    const hasNetworks = !!deal.report_has_networks;
    const estimated = !!deal.report_breakdown_estimated;
    const networksFromInventory = !!deal.report_networks_from_inventory;
    const prefs = getReportColumnPrefs(
      dealDbId,
      hasScreens,
      hasNetworks,
      estimated,
      !!deal.report_networks_from_inventory,
    );
    const columns = visibleReportColumns(prefs, hasScreens, hasNetworks);
    const rows = applyReportTableSort(
      aggregateReports(filteredDealReports(deal), prefs, deal),
      dealDbId,
      prefs,
      deal,
    );
    const selectedNetwork = reportNetworkFilters.get(reportPrefsKey(dealDbId)) || '';
    const colSpan = Math.max(columns.length, 1);

    const head = columns.map((col) => renderReportSortHeader(col, dealDbId)).join('');
    const body = rows.length
      ? rows.map((row) => `<tr>${columns.map((col) => {
          if (col.id === 'date') {
            return `<td>${escapeHtml(formatDateBR(row.report_date))}</td>`;
          }
          if (col.id === 'screen') {
            const screenLabel = formatReportScreenLabel(row.screen_code);
            return `<td class="${estimated ? 'campaign-control-estimated-cell' : ''}">${screenLabel}${estimated ? estimatedCellSuffix(true) : ''}</td>`;
          }
          if (col.id === 'network') {
            const networkLabel = row.network ? escapeHtml(row.network) : '—';
            return `<td class="${estimated ? 'campaign-control-estimated-cell' : ''}">${networkLabel}${estimated ? estimatedCellSuffix(true) : ''}</td>`;
          }
          return `<td>${formatReportMetric(col.id, row)}</td>`;
        }).join('')}</tr>`).join('')
      : `<tr><td colspan="${colSpan}" class="campaign-control-empty-row">Nenhum dia importado ainda.</td></tr>`;

    const hideIcon = window.OcIcons?.svg('eye-off', 16) || '◫';

    const estimatedHint = estimated
      ? `<p class="campaign-control-estimated-banner" role="note">
          Rede e unidade são <strong>estimadas</strong> com base nas lojas deste Deal (proporcional aos impactos planejados).
          O relatório da plataforma não traz esse detalhamento.
        </p>`
      : (networksFromInventory
        ? `<p class="campaign-control-estimated-banner campaign-control-estimated-banner--info" role="note">
            A rede vem do <strong>inventário da campanha</strong> (código da tela → rede da unidade).
            Relatórios Admooh e similares não trazem rede no arquivo importado.
          </p>`
        : '');

    const networkFilter = hasNetworks
      ? `<label class="campaign-control-network-filter">
          Rede${estimated ? ' (estimada)' : ''}
          <select class="campaign-control-network-select" data-deal-id="${dealDbId}">
            <option value="">Todas</option>
            ${(deal.report_networks || []).map((network) => {
              const selected = selectedNetwork === network ? ' selected' : '';
              return `<option value="${escapeHtml(network)}"${selected}>${escapeHtml(network)}</option>`;
            }).join('')}
          </select>
        </label>`
      : '';

    return `<div class="campaign-control-reports" data-deal-id="${dealDbId}">
      ${estimatedHint}
      <div class="campaign-control-reports-toolbar">
        ${networkFilter}
        <button type="button" class="btn btn-secondary campaign-control-columns-btn" data-deal-id="${dealDbId}">
          ${hideIcon} Ocultar
        </button>
      </div>
      <div class="users-table-wrap campaign-control-table-wrap">
        <table class="history-table users-table campaign-control-table">
          <thead><tr>${head}</tr></thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    </div>`;
  }

  function refreshDealReportTable(dealDbId) {
    const deal = (approvedData?.control?.deals || []).find((d) => Number(d.deal_db_id) === Number(dealDbId));
    const host = document.querySelector(`.campaign-control-reports[data-deal-id="${dealDbId}"]`);
    if (!deal || !host) return;
    host.outerHTML = renderReportTableHtml(deal);
  }

  function openReportColumnsModal(dealDbId) {
    const deal = (approvedData?.control?.deals || []).find((d) => Number(d.deal_db_id) === Number(dealDbId));
    if (!deal) return;

    reportColumnsModalDealId = dealDbId;
    reportColumnsModalDraft = {
      ...getReportColumnPrefs(
        dealDbId,
        deal.report_has_screens,
        deal.report_has_networks,
        deal.report_breakdown_estimated,
        !!deal.report_networks_from_inventory,
      ),
    };

    const list = el('campaignReportColumnsList');
    if (!list) return;

    list.innerHTML = REPORT_COLUMN_DEFS.filter((col) => {
      if (col.requiresScreens && !deal.report_has_screens) return false;
      if (col.requiresNetworks && !deal.report_has_networks) return false;
      return true;
    })
      .map((col) => {
        const checked = reportColumnsModalDraft[col.id] !== false ? 'checked' : '';
        const hint = col.group ? ' <span class="campaign-report-columns-hint">(agrupamento)</span>' : '';
        return `<label class="campaign-report-columns-item">
          <input type="checkbox" value="${col.id}" ${checked}>
          <span>${escapeHtml(col.label)}${hint}</span>
        </label>`;
      }).join('');

    const modal = el('campaignReportColumnsModal');
    modal?.classList.remove('hidden');
    modal?.setAttribute('aria-hidden', 'false');
  }

  function closeReportColumnsModal() {
    reportColumnsModalDealId = 0;
    reportColumnsModalDraft = null;
    const modal = el('campaignReportColumnsModal');
    modal?.classList.add('hidden');
    modal?.setAttribute('aria-hidden', 'true');
  }

  function acceptReportColumnsModal() {
    if (!reportColumnsModalDealId || !reportColumnsModalDraft) return;

    const dealDbId = reportPrefsKey(reportColumnsModalDealId);
    const draft = { ...reportColumnsModalDraft };

    el('campaignReportColumnsList')?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      draft[input.value] = input.checked;
    });

    reportColumnPrefs.set(dealDbId, draft);
    closeReportColumnsModal();
    refreshDealReportTable(dealDbId);
  }

  function renderControlDeal(deal) {
    const uploadIcon = window.OcIcons?.svg('upload', 18) || '↑';
    const targets = filteredDealTargets(deal);
    const actual = filteredDealActual(deal);
    const dailyTargets = filteredDealDailyTargets(deal);
    const networkFilter = activeDealFilters(deal.deal_db_id).network;
    const filterScope = networkFilter ? ` (${escapeHtml(networkFilter)})` : '';

    return `<article class="campaign-control-deal" data-deal-id="${deal.deal_db_id}">
      <header class="campaign-control-deal-head">
        <div>
          <h3><code>${escapeHtml(deal.deal_id)}</code></h3>
          <p>${deal.report_count || 0} dia(s) com relatório</p>
        </div>
        <button type="button" class="btn btn-secondary campaign-control-upload-btn" data-deal-id="${deal.deal_db_id}">
          ${uploadIcon} Importar relatório
        </button>
      </header>
      <dl class="campaign-control-metrics">
        ${renderControlMetric('Impressões', actual?.impressoes, targets?.impressions)}
        ${renderControlMetric('Impactos', actual?.impactos, targets?.impactos)}
        ${renderControlMetric('Consumo', actual?.consumo, targets?.consumo, formatBRL)}
      </dl>
      <p class="campaign-control-daily-hint">Meta diária${filterScope}: ${formatInteger(dailyTargets?.impressions)} imp · ${formatInteger(dailyTargets?.impactos)} impactos · ${formatBRL(dailyTargets?.consumo)}</p>
      ${renderPacingChartsBlock(`deal-${deal.deal_db_id}`, pacingForDeal(deal), 'Pacing do Deal')}
      ${renderReportTableHtml(deal)}
    </article>`;
  }

  function renderControl() {
    const wrap = el('campaignControlWrap');
    const control = approvedData?.control;
    if (!wrap || !control) return;

    const camp = control.campaign || {};
    const deals = control.deals || [];
    if (!deals.length) {
      wrap.innerHTML = `<div class="campaign-deal-empty">
        ${window.OcIcons?.svg('chart-bar', 40) || ''}
        <p><strong>Cadastre um Deal primeiro</strong></p>
        <p class="campaign-deal-empty-hint">O controle de consumo é feito por Deal. Crie ao menos um Deal na aba Deals.</p>
      </div>`;
      return;
    }

    wrap.innerHTML = `<section class="campaign-control-summary">
      <h3>Campanha</h3>
      <div class="campaign-control-progress">
        <div class="campaign-control-progress-bar" style="width:${Math.min(100, camp.progress_percent || 0)}%"></div>
      </div>
      <p class="campaign-control-progress-label">${camp.days_elapsed || 0} de ${camp.days_total || 0} dias (${camp.progress_percent || 0}%)</p>
      <dl class="campaign-control-metrics campaign-control-metrics--campaign">
        ${renderControlMetric('Impressões', camp.actual?.impressoes, camp.targets?.impressions)}
        ${renderControlMetric('Impactos', camp.actual?.impactos, camp.targets?.impactos)}
        ${renderControlMetric('Consumo', camp.actual?.consumo, camp.targets?.consumo, formatBRL)}
      </dl>
      <p class="campaign-control-import-hint">Importe relatórios Magnite, Hivestack, Outcon, Adsmovil, Admooh ou CSV genérico por Deal. Hivestack traz rede no arquivo; Admooh traz tela — a rede é obtida do inventário da campanha para agrupamento e filtros.</p>
      ${renderPacingChartsBlock('campaign', camp.pacing, 'Pacing da campanha')}
    </section>
    ${deals.map(renderControlDeal).join('')}`;

    requestAnimationFrame(() => renderPacingCharts());
  }

  function renderPlaybook() {
    const wrap = el('campaignPlaybookWrap');
    const pb = approvedData?.playbook;
    if (!wrap || !pb) return;
    const section = (title, steps, extra) => `<section class="campaign-playbook-block">
      <h3>${escapeHtml(title)}</h3>
      ${extra || ''}
      <ol>${(steps || []).map(renderPlaybookStep).join('')}</ol>
    </section>`;

    wrap.innerHTML = [
      section(
        'OnSign',
        pb.onsign?.steps,
        renderScreenCodesBlock('Telas OnSign', pb.onsign?.player_codes, { bulkCopy: true, cmsKey: 'onsign' }),
      ),
      section(
        'Invian',
        pb.invian?.steps,
        renderScreenCodesBlock('Faces Invian', pb.invian?.face_codes, { bulkCopy: false, cmsKey: 'invian' }),
      ),
      section(pb.xibo?.title || 'Xibo', pb.xibo?.steps, `<p class="campaign-playbook-summary">${escapeHtml(pb.xibo?.summary || '')}</p>`),
    ].join('');
  }

  function playbookCodesForCms(cmsKey) {
    const pb = approvedData?.playbook;
    if (cmsKey === 'onsign') return pb?.onsign?.player_codes || [];
    if (cmsKey === 'invian') return pb?.invian?.face_codes || [];
    return [];
  }

  function switchTab(tab) {
    if (isReadOnlyMode() && tab === 'playbook') tab = 'control';
    approvedTab = tab;
    document.querySelectorAll('#campaignApprovedTabs .campaign-tab').forEach((btn) => {
      btn.classList.toggle('campaign-tab--active', btn.dataset.tab === tab);
    });
    el('campaignApprovedDealsSection')?.classList.toggle('hidden', tab !== 'deals');
    el('campaignApprovedCreativesSection')?.classList.toggle('hidden', tab !== 'creatives');
    el('campaignApprovedPlaybookSection')?.classList.toggle('hidden', tab !== 'playbook');
    el('campaignApprovedControlSection')?.classList.toggle('hidden', tab !== 'control');
    if (tab === 'control') requestAnimationFrame(() => renderPacingCharts());
    else window.OcPacingCharts?.destroyAll();
  }

  function applyReadOnlyChrome() {
    const readOnly = isReadOnlyMode();
    const kicker = document.querySelector('#campaignApprovedCard .campaign-panel-kicker');
    if (kicker) {
      kicker.textContent = readOnly ? 'Campanha pausada / finalizada' : 'Campanha aprovada';
    }
    const banner = el('campaignApprovedReadOnlyBanner');
    if (banner) {
      if (readOnly) {
        banner.textContent = 'Campanha encerrada. Deals e criativos são somente leitura. Relatórios ainda podem ser importados na aba Controle.';
        banner.classList.remove('hidden');
      } else {
        banner.textContent = '';
        banner.classList.add('hidden');
      }
    }
    document.querySelector('#campaignApprovedTabs .campaign-tab[data-tab="playbook"]')
      ?.classList.toggle('hidden', readOnly);
    el('campaignAddDealBtn')?.classList.toggle('hidden', readOnly);
    el('campaignSlotsSaveBtn')?.classList.toggle('hidden', readOnly);
    el('campaignSlotsInput')?.toggleAttribute('disabled', readOnly);
    document.querySelector('.campaign-approved-toolbar')?.classList.toggle('campaign-approved-toolbar--readonly', readOnly);
  }

  function renderApproved() {
    const doc = approvedData?.document;
    if (!doc) return;
    if (isReadOnlyMode() && approvedTab === 'playbook') approvedTab = 'control';
    el('campaignApprovedTitle').textContent = doc.campanha || 'Campanha';
    el('campaignApprovedMeta').textContent = `${doc.anunciante || ''} · ID ${doc.ads_id || ''}`;
    el('campaignSlotsInput').value = doc.campaign_slots || 1;
    const planning = el('campaignPlanningLink');
    if (planning) planning.href = approvedData.planning_url || '#';
    applyReadOnlyChrome();
    renderDealsList();
    renderCreatives();
    if (!isReadOnlyMode()) renderPlaybook();
    renderControl();
    switchTab(approvedTab);
  }

  async function loadApproved(documentId) {
    approvedDocId = documentId;
    approvedData = await apiGet({ action: 'approved_context', document_id: String(documentId) });
    if (approvedData?.read_only) approvedTab = 'control';
    renderApproved();
  }

  async function parseUploadResponse(res) {
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      if (/POST Content-Length.*exceeds the limit/i.test(text)) {
        throw new Error(
          'Arquivo grande demais para o limite atual do PHP (post_max_size / upload_max_filesize). O servidor deve aceitar até 32 MB — recarregue após ajustar o PHP ou envie um arquivo menor.',
        );
      }
      const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
      throw new Error(
        snippet
          ? `Resposta inválida do servidor: ${snippet}`
          : 'Resposta inválida do servidor. Verifique limites de upload (post_max_size / upload_max_filesize) ou logs do PHP.',
      );
    }
  }

  let pendingReportDealId = 0;
  /** @type {File|null} */
  let pendingReportFile = null;
  /** @type {Array<{device_name: string, row_count: number, cleaned_name: string}>} */
  let pendingUnmatchedDevices = [];
  /** @type {string[]} */
  let pendingCampaignScreenCodes = [];

  const UNIDENTIFIED_SCREEN_PREFIX = '?unidentified:';

  function formatReportScreenLabel(screenCode) {
    if (!screenCode) return '—';
    if (String(screenCode).startsWith(UNIDENTIFIED_SCREEN_PREFIX)) {
      const slug = String(screenCode).slice(UNIDENTIFIED_SCREEN_PREFIX.length);
      return `<span class="campaign-screen-unidentified" title="${escapeHtml(slug)}">Tela não identificada</span>`;
    }
    return `<code>${escapeHtml(screenCode)}</code>`;
  }

  function importResultMessage(ir) {
    const parts = [];
    if (ir.inserted) parts.push(`${ir.inserted} inserido(s)`);
    if (ir.updated) parts.push(`${ir.updated} atualizado(s)`);
    if (ir.skipped) parts.push(`${ir.skipped} ignorado(s)`);
    if (ir.ignored) parts.push(`${ir.ignored} de outro deal`);
    if (ir.unmatched) parts.push(`${ir.unmatched} linha(s) pendente(s)`);
    const platform = ir.platform ? ` (${ir.platform})` : '';
    return parts.length ? `Relatório importado${platform}: ${parts.join(', ')}.` : `Relatório importado${platform}.`;
  }

  function closeAdmoohUnmatchedModal() {
    el('campaignAdmoohUnmatchedModal')?.classList.add('hidden');
    el('campaignAdmoohUnmatchedModal')?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    pendingReportFile = null;
    pendingUnmatchedDevices = [];
    pendingCampaignScreenCodes = [];
  }

  function renderAdmoohUnmatchedModal() {
    const host = el('campaignAdmoohUnmatchedList');
    if (!host) return;
    const options = pendingCampaignScreenCodes.length
      ? pendingCampaignScreenCodes
      : (approvedData?.campaign_screen_codes || approvedData?.deal_screen_codes || []);
    host.innerHTML = pendingUnmatchedDevices.map((device, index) => {
      const deviceName = device.device_name || '';
      const cleaned = device.cleaned_name || deviceName;
      const rowCount = Number(device.row_count) || 0;
      const mapOptions = options.map((code) => `<option value="${escapeHtml(code)}">${escapeHtml(code)}</option>`).join('');
      return `<article class="campaign-admooh-unmatched-item" data-index="${index}">
        <header class="campaign-admooh-unmatched-item-head">
          <strong>${escapeHtml(deviceName)}</strong>
          <span class="campaign-admooh-unmatched-meta">${rowCount} linha(s) · sugerido: ${escapeHtml(cleaned)}</span>
        </header>
        <div class="campaign-admooh-unmatched-options">
          <label class="campaign-admooh-unmatched-option">
            <input type="radio" name="admooh-res-${index}" value="add" checked>
            Adicionar à campanha e ao Deal (buscar no inventário)
          </label>
          <label class="campaign-admooh-unmatched-option">
            <input type="radio" name="admooh-res-${index}" value="map">
            Vincular a uma tela existente da campanha
            <select class="campaign-admooh-unmatched-select" data-map-select="${index}" disabled>
              <option value="">Selecione a tela…</option>
              ${mapOptions}
            </select>
          </label>
          <label class="campaign-admooh-unmatched-option">
            <input type="radio" name="admooh-res-${index}" value="ignore">
            Ignorar identificação (importar como tela não identificada)
          </label>
        </div>
      </article>`;
    }).join('');

    host.querySelectorAll('input[type="radio"]').forEach((input) => {
      input.addEventListener('change', () => {
        const article = input.closest('.campaign-admooh-unmatched-item');
        const select = article?.querySelector('[data-map-select]');
        if (select) select.disabled = input.value !== 'map';
      });
    });
  }

  function openAdmoohUnmatchedModal(devices, screenCodes) {
    pendingUnmatchedDevices = devices;
    pendingCampaignScreenCodes = screenCodes || [];
    renderAdmoohUnmatchedModal();
    el('campaignAdmoohUnmatchedModal')?.classList.remove('hidden');
    el('campaignAdmoohUnmatchedModal')?.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
  }

  function collectAdmoohResolutions() {
    return pendingUnmatchedDevices.map((device, index) => {
      const selected = document.querySelector(`input[name="admooh-res-${index}"]:checked`);
      const resolution = selected?.value || 'ignore';
      const item = {
        device_name: device.device_name,
        resolution,
      };
      if (resolution === 'map') {
        const select = document.querySelector(`[data-map-select="${index}"]`);
        item.screen_code = select?.value || '';
        if (!item.screen_code) {
          throw new Error(`Selecione a tela da campanha para: ${device.device_name}`);
        }
      }
      return item;
    });
  }

  async function uploadDealReport(dealDbId, file, deviceResolutions = null) {
    const fd = new FormData();
    fd.append('action', 'upload_deal_report');
    fd.append('document_id', String(approvedDocId));
    fd.append('deal_db_id', String(dealDbId));
    fd.append('csrf_token', csrf());
    fd.append('file', file);
    if (deviceResolutions?.length) {
      fd.append('device_resolutions', JSON.stringify(deviceResolutions));
    }
    const res = await fetch(apiUrl(), { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf() } });
    const data = await parseUploadResponse(res);
    if (data?.csrf_token && window.ocMakerCsrfToken) window.ocMakerCsrfToken(data.csrf_token);
    if (!res.ok) throw new Error(data.error || 'Falha na importação');
    approvedData = data;
    renderDealsList();
    renderControl();
    const ir = data.import_result || {};
    const pending = ir.pending_unmatched || [];
    if (pending.length && !deviceResolutions?.length) {
      pendingReportFile = file;
      pendingReportDealId = dealDbId;
      const partialMsg = importResultMessage(ir);
      showAlert(`${partialMsg} Revise as telas pendentes.`, true);
      openAdmoohUnmatchedModal(pending, data.campaign_screen_codes || data.deal_screen_codes || []);
      return data;
    }
    closeAdmoohUnmatchedModal();
    const msg = importResultMessage(ir);
    if (ir.errors?.length) showAlert(`${msg} ${ir.errors.length} linha(s) com aviso.`);
    else showAlert(msg, true);
    return data;
  }

  async function uploadCreatives(files) {
    for (const file of files) {
      const fd = new FormData();
      fd.append('action', 'upload_creative');
      fd.append('document_id', String(approvedDocId));
      fd.append('csrf_token', csrf());
      fd.append('file', file);
      const res = await fetch(apiUrl(), { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf() } });
      const data = await parseUploadResponse(res);
      if (data?.csrf_token && window.ocMakerCsrfToken) window.ocMakerCsrfToken(data.csrf_token);
      if (!res.ok) throw new Error(data.error || 'Falha no upload');
      approvedData = data;
    }
    renderCreatives();
    renderPlaybook();
    showAlert('Criativo(s) enviado(s).', true);
  }

  function initApprovedIcons() {
    if (!window.OcIcons) return;
    const hero = el('campaignApprovedHeroIcon');
    if (hero) hero.innerHTML = OcIcons.svg('edit', 28);
    const saveBtn = el('campaignSlotsSaveBtn');
    if (saveBtn) saveBtn.innerHTML = OcIcons.svg('save', 20);
    const planning = el('campaignPlanningLink');
    if (planning) planning.innerHTML = OcIcons.svg('external-link', 20);
    const addDeal = el('campaignAddDealBtn');
    if (addDeal) addDeal.innerHTML = OcIcons.svg('plus', 20);
    const offlineBtn = el('campaignApprovedOfflineListBtn')?.querySelector('.btn-icon');
    if (offlineBtn) offlineBtn.innerHTML = OcIcons.svg('wifi-off', 18);
    window.OcOfflineScreens?.initIcons();
  }

  function bindEvents() {
    if (eventsBound) return;
    eventsBound = true;
    initApprovedIcons();
    window.OcOfflineScreens?.bindEvents();

    el('campaignApprovedOfflineListBtn')?.addEventListener('click', () => {
      window.OcOfflineScreens?.open(
        approvedData?.offline_screens || [],
        approvedData?.document || {},
      );
    });

    el('campaignApprovedModalClose')?.addEventListener('click', () => close());

    el('campaignApprovedTabs')?.addEventListener('click', (e) => {
      const tab = e.target.closest('.campaign-tab');
      if (tab?.dataset.tab) switchTab(tab.dataset.tab);
    });

    el('campaignAddDealBtn')?.addEventListener('click', () => {
      if (isReadOnlyMode()) return;
      openDealEditor(null);
    });
    el('campaignSlotsSaveBtn')?.addEventListener('click', async () => {
      try {
        approvedData = await apiPost({
          action: 'save_campaign_slots',
          document_id: approvedDocId,
          campaign_slots: Number(el('campaignSlotsInput')?.value || 1),
        });
        renderApproved();
        showAlert('Slots da campanha salvos.', true);
      } catch (e) {
        showAlert(e.message);
      }
    });

    el('campaignDealsWrap')?.addEventListener('click', async (e) => {
      const view = e.target.closest('.campaign-deal-view');
      if (view) {
        const deal = (approvedData.deals || []).find((d) => Number(d.id) === Number(view.dataset.id));
        openDealEditor(deal || null, true);
        return;
      }
      const edit = e.target.closest('.campaign-deal-edit');
      if (edit) {
        const deal = (approvedData.deals || []).find((d) => Number(d.id) === Number(edit.dataset.id));
        openDealEditor(deal || null);
        return;
      }
      const del = e.target.closest('.campaign-deal-delete');
      if (del) {
        if (!window.confirm('Remover este Deal?')) return;
        try {
          approvedData = await apiPost({ action: 'delete_deal', document_id: approvedDocId, deal_db_id: Number(del.dataset.id) });
          renderApproved();
          showAlert('Deal removido.', true);
        } catch (err) {
          showAlert(err.message);
        }
      }
    });

    el('campaignCreativeInput')?.addEventListener('change', (e) => {
      const check = validateCreativeFiles(e.target.files);
      if (!e.target.files?.length) return;
      if (!check.ok) {
        if (check.message) showAlert(check.message);
        e.target.value = '';
        return;
      }
      uploadCreatives(check.files).catch((err) => showAlert(err.message));
      e.target.value = '';
    });

    const dropZone = el('campaignCreativesDropZone');
    const creativeInput = el('campaignCreativeInput');
    if (dropZone) {
      dropZone.addEventListener('click', (e) => {
        if (isReadOnlyMode()) return;
        if (e.target.closest('.campaign-creative-delete, .campaign-creative-preview-btn, button')) return;
        creativeInput?.click();
      });
      dropZone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          creativeInput?.click();
        }
      });

      let dragDepth = 0;
      dropZone.addEventListener('dragenter', (e) => {
        e.preventDefault();
        dragDepth += 1;
        dropZone.classList.add('campaign-creatives-dropzone--over');
      });
      dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      });
      dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dragDepth -= 1;
        if (dragDepth <= 0) {
          dragDepth = 0;
          dropZone.classList.remove('campaign-creatives-dropzone--over');
        }
      });
      dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dragDepth = 0;
        dropZone.classList.remove('campaign-creatives-dropzone--over');
        if (isReadOnlyMode()) return;
        const check = validateCreativeFiles(e.dataTransfer?.files);
        if (!e.dataTransfer?.files?.length) return;
        if (!check.ok) {
          if (check.message) showAlert(check.message);
          return;
        }
        uploadCreatives(check.files).catch((err) => showAlert(err.message));
      });
    }

    el('campaignCreativesGallery')?.addEventListener('click', async (e) => {
      const previewBtn = e.target.closest('.campaign-creative-preview-btn');
      if (previewBtn) {
        openCreativePreview(Number(previewBtn.dataset.creativeId || 0));
        return;
      }

      const btn = e.target.closest('.campaign-creative-delete');
      if (!btn) return;
      if (!window.confirm('Remover este criativo?')) return;
      try {
        approvedData = await apiPost({
          action: 'delete_creative',
          document_id: approvedDocId,
          creative_id: Number(btn.dataset.id),
        });
        renderCreatives();
        showAlert('Criativo removido.', true);
      } catch (err) {
        showAlert(err.message);
      }
    });

    el('campaignCreativePreviewClose')?.addEventListener('click', closeCreativePreview);
    el('campaignCreativePreviewModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'campaignCreativePreviewModal') closeCreativePreview();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const previewModal = el('campaignCreativePreviewModal');
      if (!previewModal || previewModal.classList.contains('hidden')) return;
      e.stopPropagation();
      closeCreativePreview();
    });

    el('campaignReportColumnsCancel')?.addEventListener('click', closeReportColumnsModal);
    el('campaignReportColumnsAccept')?.addEventListener('click', acceptReportColumnsModal);
    el('campaignReportColumnsModal')?.addEventListener('click', (e) => {
      if (e.target.id === 'campaignReportColumnsModal') closeReportColumnsModal();
    });

    el('campaignControlWrap')?.addEventListener('change', (e) => {
      const networkSelect = e.target.closest('.campaign-control-network-select');
      if (!networkSelect) return;
      const dealDbId = reportPrefsKey(Number(networkSelect.dataset.dealId || 0));
      reportNetworkFilters.set(dealDbId, networkSelect.value || '');
      refreshDealReportTable(dealDbId);
      refreshDealControlMetrics(dealDbId);
      refreshDealPacingCharts(dealDbId);
    });

    el('campaignControlWrap')?.addEventListener('click', (e) => {
      const sortTh = e.target.closest('.campaign-control-sort-th');
      if (sortTh) {
        const dealDbId = Number(sortTh.dataset.dealId || 0);
        cycleReportSort(dealDbId, sortTh.dataset.sortCol || '');
        refreshDealReportTable(dealDbId);
        return;
      }

      const columnsBtn = e.target.closest('.campaign-control-columns-btn');
      if (columnsBtn) {
        openReportColumnsModal(Number(columnsBtn.dataset.dealId || 0));
        return;
      }

      const metricTab = e.target.closest('.campaign-pacing-metric-tab');
      if (metricTab) {
        const scopeId = metricTab.dataset.scope || '';
        const metric = metricTab.dataset.metric || 'impactos';
        pacingMetrics.set(scopeId, metric);
        metricTab.closest('.campaign-pacing-metric-tabs')?.querySelectorAll('.campaign-pacing-metric-tab').forEach((btn) => {
          btn.classList.toggle('campaign-pacing-metric-tab--active', btn === metricTab);
        });
        let pacing = approvedData?.control?.campaign?.pacing;
        if (scopeId !== 'campaign') {
          const deal = (approvedData?.control?.deals || []).find((d) => `deal-${d.deal_db_id}` === scopeId);
          pacing = deal ? pacingForDeal(deal) : null;
        }
        window.OcPacingCharts?.renderScope(scopeId, pacing, metric);
        if (scopeId === 'campaign') {
          refreshPacingInsights(scopeId, pacing, approvedData?.control?.campaign?.targets);
        } else {
          const deal = (approvedData?.control?.deals || []).find((d) => `deal-${d.deal_db_id}` === scopeId);
          refreshPacingInsights(scopeId, pacing, deal ? filteredDealTargets(deal) : {});
        }
        return;
      }

      const btn = e.target.closest('.campaign-control-upload-btn');
      if (!btn) return;
      pendingReportDealId = Number(btn.dataset.dealId || 0);
      el('campaignDealReportInput')?.click();
    });

    el('campaignDealReportInput')?.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      e.target.value = '';
      if (!file || !pendingReportDealId) return;
      const dealId = pendingReportDealId;
      pendingReportDealId = 0;
      uploadDealReport(dealId, file).catch((err) => showAlert(err.message));
    });

    el('campaignAdmoohUnmatchedClose')?.addEventListener('click', closeAdmoohUnmatchedModal);
    el('campaignAdmoohUnmatchedCancel')?.addEventListener('click', closeAdmoohUnmatchedModal);
    el('campaignAdmoohUnmatchedConfirm')?.addEventListener('click', async () => {
      if (!pendingReportFile || !pendingReportDealId) {
        closeAdmoohUnmatchedModal();
        return;
      }
      try {
        const resolutions = collectAdmoohResolutions();
        await uploadDealReport(pendingReportDealId, pendingReportFile, resolutions);
      } catch (err) {
        showAlert(err.message);
      }
    });

    el('campaignPlaybookWrap')?.addEventListener('click', async (e) => {
      const one = e.target.closest('[data-copy-code]');
      if (one) {
        const code = decodeURIComponent(one.dataset.copyCode || '');
        try {
          await copyText(code);
          flashCopyButton(one);
          markPlaybookRowCopied(one, code);
        } catch (err) {
          showAlert(err.message || 'Não foi possível copiar.');
        }
        return;
      }
      const all = e.target.closest('[data-copy-all-cms]');
      if (all) {
        const codes = playbookCodesForCms(all.dataset.copyAllCms || '');
        if (!codes.length) return;
        try {
          await copyText(codes.join('\n'));
          flashCopyButton(all);
          showAlert(`${codes.length} código(s) copiado(s).`, true);
        } catch (err) {
          showAlert(err.message || 'Não foi possível copiar.');
        }
      }
    });
  }

  function open(documentId) {
    bindEvents();
    el('campaignApprovedModal')?.classList.remove('hidden');
    el('campaignApprovedModal')?.setAttribute('aria-hidden', 'false');
    loadApproved(documentId).catch((e) => showAlert(e.message));
  }

  function close() {
    if (dealDirty && !isReadOnlyMode() && !window.confirm('Há rascunho local do Deal. Fechar mesmo assim?')) return;
    window.OcCampaigns?.refreshLists?.().catch(() => {});
    window.OcPacingCharts?.destroyAll();
    el('campaignApprovedModal')?.classList.add('hidden');
    el('campaignApprovedModal')?.setAttribute('aria-hidden', 'true');
    approvedDocId = 0;
    approvedData = null;
    copiedPlaybookCodes.clear();
    pacingMetrics.clear();
    reportColumnPrefs.clear();
    reportNetworkFilters.clear();
    reportTableSort.clear();
    closeReportColumnsModal();
    closeCreativePreview();
  }

  window.OcCampaignApproved = { open, close };
})();
