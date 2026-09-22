(function () {
  const STATUS_LABELS = {
    aguardando_aprovacao: 'Aguardando Aprovação',
    aprovada: 'Aprovadas',
    rejeitada: 'Rejeitadas',
    finalizada_pausada: 'Pausadas / Finalizadas',
  };

  const STATUS_ORDER = [
    'aguardando_aprovacao',
    'aprovada',
    'rejeitada',
    'finalizada_pausada',
  ];

  let initialized = false;
  let analyzeInitialized = false;
  let modalReturnFocus = null;
  let currentStatus = 'aguardando_aprovacao';
  let currentPage = 1;
  let counts = {};
  let isAdmin = false;
  let analyzeDirty = false;
  let analyzeDocumentId = 0;
  let analyzeData = null;
  let rejectUnitId = 0;

  function el(id) {
    return document.getElementById(id);
  }

  function csrf() {
    return window.OC_MAKER?.csrf_token || window.ocMakerCsrfToken?.() || '';
  }

  function setCsrfToken(token) {
    if (!token) return;
    if (window.OC_MAKER) window.OC_MAKER.csrf_token = token;
    if (typeof window.ocMakerCsrfToken === 'function') {
      window.ocMakerCsrfToken(token);
    }
  }

  async function refreshCsrfToken() {
    const meUrl = window.OC_MAKER?.api?.authMe;
    if (!meUrl) return false;
    try {
      const res = await fetch(meUrl);
      const data = await res.json();
      if (data?.csrf_token) {
        setCsrfToken(data.csrf_token);
        return true;
      }
    } catch {
      return false;
    }
    return false;
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.campaignManagement || '';
  }

  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatDateRange(inicio, termino) {
    const fmt = (iso) => {
      if (!iso) return '—';
      const [y, m, d] = String(iso).split('-');
      return `${d}/${m}/${y}`;
    };
    return `${fmt(inicio)} — ${fmt(termino)}`;
  }

  function showAlert(targetId, msg, ok) {
    const alert = el(targetId);
    if (!alert) return;
    alert.className = ok
      ? 'alert alert-success campaign-panel-alert'
      : 'alert alert-error campaign-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
    if (!ok) afterLayout(() => alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
  }

  function hideAlert(targetId) {
    el(targetId)?.classList.add('hidden');
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json();
    if (data?.csrf_token) setCsrfToken(data.csrf_token);
    if (!res.ok) {
      const err = new Error(data.error || 'Erro na requisição');
      err.status = res.status;
      err.csrfError = res.status === 419 || /csrf/i.test(String(data.error || ''));
      throw err;
    }
    return data;
  }

  async function apiGet(params) {
    const qs = new URLSearchParams(params);
    return fetchJson(`${apiUrl()}?${qs}`);
  }

  async function apiPost(payload, allowRetry = true) {
    try {
      return await fetchJson(apiUrl(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({ ...payload, csrf_token: csrf() }),
      });
    } catch (err) {
      if (allowRetry && err.csrfError && (await refreshCsrfToken())) {
        return apiPost(payload, false);
      }
      throw err;
    }
  }

  function setDirty(value) {
    analyzeDirty = !!value;
    el('campaignAnalyzeModal')?.classList.toggle('account-modal--locked', analyzeDirty);
  }

  function scheduleAnalyzeDraftSave() {
    if (!analyzeDocumentId || !window.OcCampaignDraft) return;
    setDirty(true);
    window.OcCampaignDraft.scheduleSave(
      'analyze',
      analyzeDocumentId,
      () => ({ units: collectUnitsPayload() }),
      'v1',
      2000,
    );
  }

  function applyAnalyzeDraft(draft) {
    if (!draft?.units || !analyzeData?.units) return;
    analyzeData.units = analyzeData.units.map((unit) => {
      const saved = draft.units.find((u) => u.inventory_item_id === unit.inventory_item_id);
      if (!saved) return unit;
      return {
        ...unit,
        status: saved.status || unit.status,
        rejection_reason: saved.rejection_reason,
        replacement_codigo: saved.replacement_codigo,
        face_rows: unit.face_rows.map((face, idx) => ({
          ...face,
          ...(saved.faces?.[idx] || {}),
          face_number: face.face_number,
        })),
      };
    });
    renderAnalyze({ ...analyzeData, units: analyzeData.units }, true);
  }

  function confirmDiscard(message) {
    if (!analyzeDirty) return true;
    return window.confirm(message || 'Existem alterações não salvas. Deseja sair sem salvar?');
  }

  function renderTabs() {
    const nav = el('campaignTabs');
    if (!nav) return;
    nav.innerHTML = STATUS_ORDER.map((status) => {
      const active = status === currentStatus ? ' campaign-tab--active' : '';
      const count = counts[status] ?? 0;
      return `<button type="button" class="campaign-tab${active}" data-status="${status}">
        ${escapeHtml(STATUS_LABELS[status])}
        <span class="campaign-tab-badge">${count}</span>
      </button>`;
    }).join('');
  }

  function renderCampaignList(items) {
    const wrap = el('campaignListWrap');
    if (!wrap) return;

    if (!items.length) {
      wrap.innerHTML = '<div class="campaign-empty"><p>Nenhuma campanha neste status.</p></div>';
      return;
    }

    const rows = items.map((item) => {
      const ads = item.ads_id ? `<span class="campaign-ads-id">ID ${escapeHtml(item.ads_id)}</span>` : '';
      const offlineCount = Number(item.offline_screen_count || 0);
      const offlineBtn = offlineCount > 0
        ? `<button type="button" class="icon-btn campaign-offline-btn" data-id="${item.id}" title="Telas offline para suporte (${offlineCount})" aria-label="Telas offline para suporte">${window.OcIcons?.svg('wifi-off', 18) || 'Offline'}</button>`
        : '';
      let primaryAction = '<span class="campaign-no-action">—</span>';
      if (currentStatus === 'aguardando_aprovacao') {
        primaryAction = `<button type="button" class="icon-btn icon-btn--brand campaign-analyze-btn" data-id="${item.id}" title="Analisar" aria-label="Analisar campanha">${window.OcIcons?.svg('scan', 18) || 'Analisar'}</button>`;
      } else if (currentStatus === 'aprovada' || currentStatus === 'finalizada_pausada') {
        const title = currentStatus === 'finalizada_pausada' ? 'Visualizar campanha' : 'Configurar campanha';
        primaryAction = `<button type="button" class="icon-btn icon-btn--brand campaign-configure-btn" data-id="${item.id}" title="${title}" aria-label="${title}">${window.OcIcons?.svg('edit', 18) || '⚙'}</button>`;
      } else if (currentStatus === 'rejeitada' && isAdmin) {
        primaryAction = `<button type="button" class="icon-btn campaign-restore-btn" data-id="${item.id}" title="Retornar para aguardando aprovação" aria-label="Retornar campanha">${window.OcIcons?.svg('refresh', 18) || '↺'}</button>`;
      }
      const actions = offlineBtn || primaryAction !== '<span class="campaign-no-action">—</span>'
        ? `<span class="icon-btn-group">${offlineBtn}${primaryAction}</span>`
        : primaryAction;

      return `<tr>
        <td class="campaign-cell-main">
          ${ads}
          <strong>${escapeHtml(item.campanha || 'Sem nome')}</strong>
          <span class="campaign-sub">${escapeHtml(item.anunciante || '')}</span>
        </td>
        <td>${formatDateRange(item.inicio, item.termino)}</td>
        <td class="campaign-actions">${actions}</td>
      </tr>`;
    }).join('');

    wrap.innerHTML = `<div class="users-table-wrap campaign-table-scroll">
      <table class="history-table users-table campaign-table">
        <thead><tr><th>Campanha</th><th>Período</th><th>Ações</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  async function loadSummary() {
    const data = await apiGet({ action: 'summary' });
    counts = data.counts || {};
    isAdmin = !!data.is_admin;
    renderTabs();
  }

  async function openCampaignOfflineSupport(documentId) {
    const data = await apiGet({ action: 'offline_screens', document_id: documentId });
    window.OcOfflineScreens?.open(data.offline_screens || [], data.document || {});
  }

  async function loadCampaigns() {
    hideAlert('campaignAlert');
    const data = await apiGet({
      action: 'list',
      status: currentStatus,
      q: el('campaignSearchInput')?.value.trim() || '',
      page: String(currentPage),
    });
    renderCampaignList(data.items || []);
    const total = data.total || 0;
    const perPage = data.per_page || 25;
    const pages = Math.max(1, Math.ceil(total / perPage));
    el('campaignPagination')?.classList.toggle('hidden', pages <= 1);
    if (el('campaignPageInfo')) {
      el('campaignPageInfo').textContent = `Página ${currentPage} de ${pages} (${total} campanhas)`;
    }
    el('campaignPrevPage') && (el('campaignPrevPage').disabled = currentPage <= 1);
    el('campaignNextPage') && (el('campaignNextPage').disabled = currentPage >= pages);
  }

  function buildSelectOptions(options, selected) {
    const blank = '<option value="">Selecione…</option>';
    const items = (options || []).map((opt) => {
      const sel = opt === selected ? ' selected' : '';
      return `<option value="${escapeHtml(opt)}"${sel}>${escapeHtml(opt)}</option>`;
    }).join('');
    return blank + items;
  }

  function offlineDurationHtml(face, unitId) {
    const hidden = face.is_online ? ' hidden' : '';
    return `<span class="campaign-offline-fields${hidden}" data-offline-for="${unitId}">
      <input type="number" min="0" class="campaign-offline-duration" value="${face.offline_duration || 0}" aria-label="Tempo offline">
      <select class="campaign-offline-unit" aria-label="Unidade de tempo">
        ${(analyzeData?.options?.offline_units || []).map((u) => {
          const sel = u === (face.offline_unit || 'horas') ? ' selected' : '';
          return `<option value="${u}"${sel}>${escapeHtml(u)}</option>`;
        }).join('')}
      </select>
    </span>`;
  }

  function wifiButton(face, unitId, faceIdx) {
    const online = !!face.is_online;
    const icon = online ? 'wifi' : 'wifi-off';
    const cls = online ? 'campaign-wifi campaign-wifi--online' : 'campaign-wifi campaign-wifi--offline';
    return `<button type="button" class="${cls}" data-unit="${unitId}" data-face="${faceIdx}" title="${online ? 'Online' : 'Offline'}" aria-label="Alternar status online">${window.OcIcons?.svg(icon, 20) || ''}</button>`;
  }

  function renderUnitRow(unit) {
    const statusClass = unit.status === 'approved'
      ? ' campaign-unit--approved'
      : unit.status === 'rejected'
        ? ' campaign-unit--rejected'
        : '';
    const rejectInfo = unit.status === 'rejected'
      ? `<div class="campaign-unit-reject-info"><strong>Motivo:</strong> ${escapeHtml(unit.rejection_reason || '')}<br><strong>Substituto:</strong> ${escapeHtml(unit.replacement_codigo || '')}</div>`
      : '';

    const faceRows = (unit.face_rows || []).map((face, idx) => {
      const cmsLocked = face.known_screen && face.cms;
      const cmsField = cmsLocked
        ? `<span class="campaign-readonly">${escapeHtml(face.cms || '')}</span>`
        : `<select class="campaign-face-cms" data-unit="${unit.inventory_item_id}" data-face="${idx}">${buildSelectOptions(analyzeData?.options?.cms, face.cms)}</select>`;

      return `<tr class="campaign-face-row" data-unit="${unit.inventory_item_id}" data-face="${idx}">
        <td class="campaign-face-label">face #${face.face_number}</td>
        <td><input type="text" class="campaign-face-code" value="${escapeHtml(face.screen_code || '')}" maxlength="64" placeholder="Código da tela"></td>
        <td>${cmsField}</td>
        <td><select class="campaign-face-os">${buildSelectOptions(analyzeData?.options?.os, face.os_name)}</select></td>
        <td class="campaign-face-online">${wifiButton(face, unit.inventory_item_id, idx)}${offlineDurationHtml(face, unit.inventory_item_id)}</td>
      </tr>`;
    }).join('');

    return `<section class="campaign-unit${statusClass}" data-unit-id="${unit.inventory_item_id}">
      <div class="campaign-unit-head">
        <table class="campaign-unit-table">
          <thead><tr>
            <th>Código da unidade</th><th>Denominação</th><th>Faces</th><th>Nome da rede</th><th>Ações</th>
          </tr></thead>
          <tbody><tr>
            <td><code>${escapeHtml(unit.codigo)}</code></td>
            <td><strong>${escapeHtml(unit.denominacao)}</strong></td>
            <td>${unit.faces}</td>
            <td>${escapeHtml(unit.rede_name || '—')}</td>
            <td class="campaign-unit-actions">
              <button type="button" class="icon-btn icon-btn--success campaign-unit-approve" data-id="${unit.inventory_item_id}" title="Aprovar unidade" aria-label="Aprovar">${window.OcIcons?.svg('thumbs-up', 18) || '✓'}</button>
              <button type="button" class="icon-btn icon-btn--danger campaign-unit-reject" data-id="${unit.inventory_item_id}" title="Reprovar unidade" aria-label="Reprovar">${window.OcIcons?.svg('thumbs-down', 18) || '✗'}</button>
            </td>
          </tr></tbody>
        </table>
        ${rejectInfo}
      </div>
      <table class="campaign-face-table">
        <thead><tr><th>Face</th><th>Código da tela</th><th>CMS</th><th>Sistema Operacional</th><th>Online</th></tr></thead>
        <tbody>${faceRows}</tbody>
      </table>
    </section>`;
  }

  function mergeFormIntoAnalyzeData() {
    if (!analyzeData?.units?.length) return;
    const collected = collectUnitsPayload();
    analyzeData.units = analyzeData.units.map((unit) => {
      const current = collected.find((u) => u.inventory_item_id === unit.inventory_item_id);
      if (!current) return unit;
      return {
        ...unit,
        status: current.status,
        rejection_reason: current.rejection_reason,
        replacement_codigo: current.replacement_codigo,
        face_rows: unit.face_rows.map((face, idx) => ({
          ...face,
          ...(current.faces[idx] || {}),
          face_number: face.face_number,
        })),
      };
    });
  }

  function renderAnalyze(data, keepDirty) {
    analyzeData = data;
    const doc = data.document;
    analyzeDocumentId = doc.id;
    el('campaignAnalyzeTitle').textContent = doc.campanha || 'Campanha';
    el('campaignAnalyzeMeta').textContent = `${doc.anunciante || ''} · ${formatDateRange(doc.inicio, doc.termino)} · ID ${doc.ads_id || doc.document_id}`;
    el('campaignUnitsWrap').innerHTML = (data.units || []).map(renderUnitRow).join('');
    if (!keepDirty) setDirty(false);
    hideAlert('campaignAnalyzeAlert');
  }

  function collectUnitsPayload() {
    const units = [];
    document.querySelectorAll('.campaign-unit').forEach((section) => {
      const itemId = Number(section.dataset.unitId);
      const unitData = (analyzeData?.units || []).find((u) => u.inventory_item_id === itemId);
      const faces = [];
      section.querySelectorAll('.campaign-face-row').forEach((row) => {
        const wifi = row.querySelector('.campaign-wifi');
        const isOnline = wifi?.classList.contains('campaign-wifi--online');
        const offlineWrap = row.querySelector('.campaign-offline-fields');
        faces.push({
          face_number: Number(row.querySelector('.campaign-face-label')?.textContent?.replace(/\D/g, '') || 1),
          screen_code: row.querySelector('.campaign-face-code')?.value.trim() || '',
          cms: row.querySelector('.campaign-face-cms')?.value || row.querySelector('.campaign-readonly')?.textContent.trim() || '',
          os_name: row.querySelector('.campaign-face-os')?.value || '',
          is_online: isOnline,
          offline_duration: Number(offlineWrap?.querySelector('.campaign-offline-duration')?.value || 0),
          offline_unit: offlineWrap?.querySelector('.campaign-offline-unit')?.value || 'horas',
        });
      });
      units.push({
        inventory_item_id: itemId,
        status: unitData?.status || 'pending',
        rejection_reason: unitData?.rejection_reason || null,
        replacement_codigo: unitData?.replacement_codigo || null,
        faces,
      });
    });
    return units;
  }

  async function saveReview(campaignAction, extra = {}) {
    if (!analyzeDocumentId) return;
    const payload = {
      action: 'save_review',
      document_id: analyzeDocumentId,
      units: collectUnitsPayload(),
      ...extra,
    };
    if (campaignAction) payload.campaign_action = campaignAction;
    const data = await apiPost(payload);
    renderAnalyze(data);
    await loadSummary();
    await loadCampaigns();
    showAlert('campaignAnalyzeAlert', campaignAction === 'approve'
      ? 'Campanha aprovada.'
      : campaignAction === 'reject'
        ? 'Campanha reprovada.'
        : 'Progresso salvo.', true);
    window.OcCampaignDraft?.clear('analyze', analyzeDocumentId, 'v1');
    if (campaignAction) closeAnalyze(true);
  }

  async function lookupScreenCode(input) {
    const code = input.value.trim();
    if (code.length < 3) return;
    try {
      const data = await apiGet({ action: 'screen_lookup', code });
      if (!data.screen) return;
      const row = input.closest('.campaign-face-row');
      if (!row) return;
      const cmsSelect = row.querySelector('.campaign-face-cms');
      const osSelect = row.querySelector('.campaign-face-os');
      if (data.screen.cms && cmsSelect) cmsSelect.value = data.screen.cms;
      if (data.screen.os_name && osSelect) osSelect.value = data.screen.os_name;
      const wifi = row.querySelector('.campaign-wifi');
      if (wifi) {
        wifi.classList.toggle('campaign-wifi--online', !!data.screen.is_online);
        wifi.classList.toggle('campaign-wifi--offline', !data.screen.is_online);
        wifi.innerHTML = window.OcIcons?.svg(data.screen.is_online ? 'wifi' : 'wifi-off', 20) || '';
        row.querySelector('.campaign-offline-fields')?.classList.toggle('hidden', !!data.screen.is_online);
      }
    } catch {
      /* ignore lookup errors */
    }
  }

  function bindMainEvents() {
    if (initialized) return;
    initialized = true;

    const iconWrap = document.querySelector('.campaign-panel-icon');
    if (iconWrap && window.OcIcons) iconWrap.innerHTML = window.OcIcons.svg('megaphone', 28);
    const searchIcon = document.querySelector('.campaign-search-icon');
    if (searchIcon && window.OcIcons) searchIcon.innerHTML = window.OcIcons.svg('search', 18);

    el('campaignModalClose')?.addEventListener('click', () => close(false));
    el('campaignModal')?.addEventListener('click', (e) => {
      if (e.target === e.currentTarget && !el('campaignAnalyzeModal')?.classList.contains('hidden')) return;
      if (e.target === e.currentTarget) close(false);
    });

    el('campaignTabs')?.addEventListener('click', async (e) => {
      const tab = e.target.closest('.campaign-tab');
      if (!tab) return;
      currentStatus = tab.dataset.status;
      currentPage = 1;
      renderTabs();
      await loadCampaigns().catch((err) => showAlert('campaignAlert', err.message));
    });

    let searchTimer;
    el('campaignSearchInput')?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        currentPage = 1;
        loadCampaigns().catch((err) => showAlert('campaignAlert', err.message));
      }, 250);
    });

    el('campaignPrevPage')?.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage -= 1;
        loadCampaigns().catch((err) => showAlert('campaignAlert', err.message));
      }
    });
    el('campaignNextPage')?.addEventListener('click', () => {
      currentPage += 1;
      loadCampaigns().catch((err) => showAlert('campaignAlert', err.message));
    });

    el('campaignListWrap')?.addEventListener('click', async (e) => {
      const offlineBtn = e.target.closest('.campaign-offline-btn');
      if (offlineBtn) {
        try {
          await openCampaignOfflineSupport(Number(offlineBtn.dataset.id));
        } catch (err) {
          showAlert('campaignAlert', err.message);
        }
        return;
      }

      const analyzeBtn = e.target.closest('.campaign-analyze-btn');
      if (analyzeBtn) {
        openAnalyze(Number(analyzeBtn.dataset.id)).catch((err) => showAlert('campaignAlert', err.message));
        return;
      }
      const configureBtn = e.target.closest('.campaign-configure-btn');
      if (configureBtn) {
        window.OcCampaignApproved?.open(Number(configureBtn.dataset.id));
        return;
      }
      const restoreBtn = e.target.closest('.campaign-restore-btn');
      if (restoreBtn) {
        if (!window.confirm('Retornar esta campanha para Aguardando Aprovação?')) return;
        try {
          await apiPost({ action: 'restore_pending', document_id: Number(restoreBtn.dataset.id) });
          await loadSummary();
          await loadCampaigns();
          showAlert('campaignAlert', 'Campanha retornou para aguardando aprovação.', true);
        } catch (err) {
          showAlert('campaignAlert', err.message);
        }
      }
    });
  }

  function bindAnalyzeEvents() {
    if (analyzeInitialized) return;
    analyzeInitialized = true;

    const analyzeHero = el('campaignAnalyzeHeroIcon');
    if (analyzeHero && window.OcIcons) analyzeHero.innerHTML = window.OcIcons.svg('scan', 28);

    const saveIcon = el('campaignAnalyzeSaveBtn')?.querySelector('.btn-icon');
    if (saveIcon && window.OcIcons) saveIcon.innerHTML = window.OcIcons.svg('save', 18);
    const approveIcon = el('campaignApproveBtn')?.querySelector('.btn-icon');
    if (approveIcon && window.OcIcons) approveIcon.innerHTML = window.OcIcons.svg('check-circle', 18);
    const rejectIcon = el('campaignRejectBtn')?.querySelector('.btn-icon');
    if (rejectIcon && window.OcIcons) rejectIcon.innerHTML = window.OcIcons.svg('x-circle', 18);
    const offlineIcon = el('campaignOfflineListBtn')?.querySelector('.btn-icon');
    if (offlineIcon && window.OcIcons) offlineIcon.innerHTML = window.OcIcons.svg('wifi-off', 18);
    window.OcOfflineScreens?.initIcons();

    el('campaignAnalyzeModalClose')?.addEventListener('click', () => closeAnalyze(false));
    el('campaignAnalyzeModal')?.addEventListener('click', (e) => {
      if (e.target === e.currentTarget && !confirmDiscard()) return;
      if (e.target === e.currentTarget) closeAnalyze(false);
    });

    el('campaignAnalyzeSaveBtn')?.addEventListener('click', () => {
      saveReview(null).catch((err) => showAlert('campaignAnalyzeAlert', err.message));
    });

    el('campaignApproveBtn')?.addEventListener('click', () => {
      if (!window.confirm('Aprovar esta campanha? Todas as unidades devem estar revisadas.')) return;
      saveReview('approve').catch((err) => showAlert('campaignAnalyzeAlert', err.message));
    });

    el('campaignRejectBtn')?.addEventListener('click', () => {
      const reason = window.prompt('Motivo da reprovação da campanha:');
      if (reason === null) return;
      if (!reason.trim()) return showAlert('campaignAnalyzeAlert', 'Informe o motivo da reprovação.');
      saveReview('reject', { campaign_rejection_reason: reason.trim() })
        .catch((err) => showAlert('campaignAnalyzeAlert', err.message));
    });

    el('campaignOfflineListBtn')?.addEventListener('click', () => {
      const offline = collectUnitsPayload()
        .flatMap((u) => u.faces.filter((f) => !f.is_online).map((f) => ({
          screen_code: f.screen_code,
          rede_name: (analyzeData?.units || []).find((x) => x.inventory_item_id === u.inventory_item_id)?.rede_name,
          offline_duration: f.offline_duration,
          offline_unit: f.offline_unit,
        })));
      window.OcOfflineScreens?.open(offline, analyzeData?.document || {});
    });

    window.OcOfflineScreens?.bindEvents();

    el('campaignUnitsWrap')?.addEventListener('input', (e) => {
      if (e.target.matches('.campaign-face-code, .campaign-face-cms, .campaign-face-os, .campaign-offline-duration, .campaign-offline-unit')) {
        scheduleAnalyzeDraftSave();
      }
    });

    el('campaignUnitsWrap')?.addEventListener('change', (e) => {
      if (e.target.matches('.campaign-offline-unit, .campaign-face-cms, .campaign-face-os')) scheduleAnalyzeDraftSave();
    });

    el('campaignUnitsWrap')?.addEventListener('blur', (e) => {
      if (e.target.matches('.campaign-face-code')) lookupScreenCode(e.target);
    }, true);

    el('campaignUnitsWrap')?.addEventListener('click', (e) => {
      const wifi = e.target.closest('.campaign-wifi');
      if (wifi) {
        const online = wifi.classList.toggle('campaign-wifi--online');
        wifi.classList.toggle('campaign-wifi--offline', !online);
        wifi.innerHTML = window.OcIcons?.svg(online ? 'wifi' : 'wifi-off', 20) || '';
        const row = wifi.closest('.campaign-face-row');
        row?.querySelector('.campaign-offline-fields')?.classList.toggle('hidden', online);
        scheduleAnalyzeDraftSave();
        return;
      }

      const approveBtn = e.target.closest('.campaign-unit-approve');
      if (approveBtn) {
        mergeFormIntoAnalyzeData();
        const id = Number(approveBtn.dataset.id);
        const unit = analyzeData.units.find((u) => u.inventory_item_id === id);
        if (unit) {
          unit.status = 'approved';
          unit.rejection_reason = null;
          unit.replacement_codigo = null;
          renderAnalyze({ ...analyzeData, units: analyzeData.units }, true);
          scheduleAnalyzeDraftSave();
        }
        return;
      }

      const rejectBtn = e.target.closest('.campaign-unit-reject');
      if (rejectBtn) {
        rejectUnitId = Number(rejectBtn.dataset.id);
        const unit = analyzeData.units.find((u) => u.inventory_item_id === rejectUnitId);
        el('campaignUnitRejectLead').textContent = unit
          ? `${unit.codigo} — ${unit.denominacao}`
          : '';
        el('campaignUnitRejectReason').value = unit?.rejection_reason || '';
        el('campaignUnitRejectReplacement').value = unit?.replacement_codigo || '';
        el('campaignUnitRejectModal')?.classList.remove('hidden');
        el('campaignUnitRejectModal')?.setAttribute('aria-hidden', 'false');
      }
    });

    el('campaignUnitRejectCancel')?.addEventListener('click', () => {
      el('campaignUnitRejectModal')?.classList.add('hidden');
      el('campaignUnitRejectModal')?.setAttribute('aria-hidden', 'true');
    });

    el('campaignUnitRejectForm')?.addEventListener('submit', (e) => {
      e.preventDefault();
      mergeFormIntoAnalyzeData();
      const unit = analyzeData.units.find((u) => u.inventory_item_id === rejectUnitId);
      if (!unit) return;
      unit.status = 'rejected';
      unit.rejection_reason = el('campaignUnitRejectReason').value.trim();
      unit.replacement_codigo = el('campaignUnitRejectReplacement').value.trim();
      renderAnalyze({ ...analyzeData, units: analyzeData.units }, true);
      scheduleAnalyzeDraftSave();
      el('campaignUnitRejectModal')?.classList.add('hidden');
      el('campaignUnitRejectModal')?.setAttribute('aria-hidden', 'true');
    });
  }

  async function openAnalyze(documentId) {
    bindAnalyzeEvents();
    const data = await apiGet({ action: 'detail', document_id: String(documentId) });
    renderAnalyze(data);
    const draft = window.OcCampaignDraft?.restoreIfConfirmed('analyze', documentId, 'v1');
    if (draft) applyAnalyzeDraft(draft);
    el('campaignAnalyzeModal')?.classList.remove('hidden');
    el('campaignAnalyzeModal')?.setAttribute('aria-hidden', 'false');
    afterLayout(() => el('campaignAnalyzeModalClose')?.focus());
  }

  function closeAnalyze(force) {
    if (!force && !confirmDiscard()) return;
    setDirty(false);
    el('campaignAnalyzeModal')?.classList.add('hidden');
    el('campaignAnalyzeModal')?.setAttribute('aria-hidden', 'true');
    el('campaignUnitRejectModal')?.classList.add('hidden');
    window.OcOfflineScreens?.close();
    analyzeDocumentId = 0;
    analyzeData = null;
  }

  function open() {
    if (document.body.classList.contains('force-password-open')) return;
    const modal = el('campaignModal');
    if (!modal) return;

    bindMainEvents();
    bindAnalyzeEvents();
    hideAlert('campaignAlert');
    currentStatus = 'aguardando_aprovacao';
    currentPage = 1;

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');

    Promise.all([loadSummary(), loadCampaigns()])
      .catch((err) => showAlert('campaignAlert', err.message));

    afterLayout(() => el('campaignModalClose')?.focus());
  }

  function close(force) {
    if (!force && !el('campaignAnalyzeModal')?.classList.contains('hidden')) {
      closeAnalyze(false);
      if (!el('campaignAnalyzeModal')?.classList.contains('hidden')) return;
    }
    el('campaignModal')?.classList.add('hidden');
    el('campaignModal')?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    hideAlert('campaignAlert');
  }

  function init() {
    bindMainEvents();
    if (new URLSearchParams(window.location.search).get('campaigns') === '1') {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('campaigns');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  async function refreshLists() {
    await loadSummary();
    await loadCampaigns();
  }

  window.OcCampaigns = { open, close, init, refreshLists };
})();
