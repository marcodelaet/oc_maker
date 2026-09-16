(function () {
  const STORAGE_KEY = 'oc_activity_log_columns';
  const PAGE_SIZE = 50;
  const DEFAULT_VISIBLE = [
    'created_at', 'actor_label', 'action', 'message', 'ip_address', 'client_browser',
  ];

  let initialized = false;
  let modalReturnFocus = null;
  let columns = [];
  let visibleColumns = [];
  let entries = [];
  let total = 0;
  let offset = 0;
  let actionsList = [];
  let searchTimer = null;

  function el(id) {
    return document.getElementById(id);
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.adminActivityLog || '';
  }

  function exportUrl(format) {
    const base = window.OC_MAKER?.api?.adminActivityLogExport || '';
    const params = new URLSearchParams({ format });
    const action = el('activityLogActionFilter')?.value || '';
    const q = el('activityLogSearch')?.value.trim() || '';
    if (action) params.set('action', action);
    if (q) params.set('q', q);
    return `${base}?${params.toString()}`;
  }

  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function showAlert(msg, ok) {
    const alert = el('activityLogAlert');
    if (!alert) return;
    alert.className = ok
      ? 'alert alert-success activity-log-alert'
      : 'alert alert-error activity-log-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('pt-BR');
  }

  function loadVisibleColumns() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [...DEFAULT_VISIBLE];
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed) || !parsed.length) return [...DEFAULT_VISIBLE];
      return parsed.filter((key) => columns.some((c) => c.key === key));
    } catch {
      return [...DEFAULT_VISIBLE];
    }
  }

  function saveVisibleColumns() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(visibleColumns));
  }

  function cellValue(entry, key) {
    if (key === 'created_at') return formatDateTime(entry.created_at);
    if (key === 'details_json') {
      if (entry.details && typeof entry.details === 'object') {
        return JSON.stringify(entry.details);
      }
      return entry.details_json || '';
    }
    const val = entry[key];
    if (val === null || val === undefined || val === '') return '—';
    return String(val);
  }

  function renderColumnsMenu() {
    const menu = el('activityLogColumnsMenu');
    if (!menu) return;
    menu.innerHTML = columns.map((col) => {
      const checked = visibleColumns.includes(col.key) ? 'checked' : '';
      return `<label class="activity-log-column-option">
        <input type="checkbox" data-col="${escapeHtml(col.key)}" ${checked}>
        <span>${escapeHtml(col.label)}</span>
      </label>`;
    }).join('');
  }

  function renderTable() {
    const wrap = el('activityLogTableWrap');
    if (!wrap) return;

    if (!entries.length) {
      wrap.innerHTML = '<div class="activity-log-empty"><p>Nenhum evento encontrado.</p></div>';
      updatePaginationMeta();
      return;
    }

    const heads = visibleColumns.map((key) => {
      const col = columns.find((c) => c.key === key);
      return `<th>${escapeHtml(col?.label || key)}</th>`;
    }).join('');

    const rows = entries.map((entry) => {
      const cells = visibleColumns.map((key) => {
        const raw = cellValue(entry, key);
        const short = raw.length > 80 ? `${raw.slice(0, 77)}…` : raw;
        return `<td title="${escapeHtml(raw)}">${escapeHtml(short)}</td>`;
      }).join('');
      return `<tr class="activity-log-row" data-id="${entry.id}" tabindex="0" role="button" aria-label="Ver detalhes do evento ${entry.id}">${cells}</tr>`;
    }).join('');

    wrap.innerHTML = `<div class="users-table-wrap activity-log-table-scroll"><table class="history-table users-table activity-log-table">
      <thead><tr>${heads}</tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;

    wrap.querySelectorAll('.activity-log-row').forEach((row) => {
      const open = () => {
        const id = Number(row.dataset.id);
        const entry = entries.find((e) => e.id === id);
        if (entry) openDetail(entry);
      };
      row.addEventListener('click', open);
      row.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          open();
        }
      });
    });

    updatePaginationMeta();
  }

  function updateCount() {
    const countEl = el('activityLogCount');
    if (!countEl) return;
    countEl.textContent = total === 1
      ? '1 evento registrado'
      : `${total.toLocaleString('pt-BR')} eventos registrados`;
  }

  function updatePaginationMeta() {
    const meta = el('activityLogPageMeta');
    const moreBtn = el('activityLogLoadMore');
    if (meta) {
      meta.textContent = entries.length
        ? `Exibindo ${entries.length.toLocaleString('pt-BR')} de ${total.toLocaleString('pt-BR')}`
        : '';
    }
    if (moreBtn) {
      const hasMore = entries.length < total;
      moreBtn.classList.toggle('hidden', !hasMore);
    }
  }

  function populateActionFilter() {
    const select = el('activityLogActionFilter');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">Todas</option>' + actionsList.map(
      (action) => `<option value="${escapeHtml(action)}">${escapeHtml(action)}</option>`,
    ).join('');
    if (current && actionsList.includes(current)) select.value = current;
  }

  async function fetchLogs(append) {
    const params = new URLSearchParams({
      limit: String(PAGE_SIZE),
      offset: String(append ? offset : 0),
    });
    const action = el('activityLogActionFilter')?.value || '';
    const q = el('activityLogSearch')?.value.trim() || '';
    if (action) params.set('action', action);
    if (q) params.set('q', q);

    const res = await fetch(`${apiUrl()}?${params.toString()}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Falha ao carregar log');

    if (!append) {
      entries = data.entries || [];
      offset = entries.length;
    } else {
      entries = entries.concat(data.entries || []);
      offset = entries.length;
    }

    total = data.total ?? entries.length;
    if (data.columns?.length) columns = data.columns;
    if (data.actions) actionsList = data.actions;
    if (!visibleColumns.length) visibleColumns = loadVisibleColumns();

    populateActionFilter();
    renderColumnsMenu();
    updateCount();
    renderTable();
  }

  async function reloadLogs() {
    offset = 0;
    closeDetail();
    el('activityLogAlert')?.classList.add('hidden');
    await fetchLogs(false);
  }

  function openDetail(entry) {
    const section = el('activityLogDetailSection');
    const grid = el('activityLogDetailGrid');
    const lead = el('activityLogDetailLead');
    if (!section || !grid) return;

    if (lead) {
      lead.textContent = `${formatDateTime(entry.created_at)} · ${entry.action || '—'}`;
    }

    const detailFields = [
      ['ID', entry.id],
      ['Data/hora', formatDateTime(entry.created_at)],
      ['Usuário', entry.actor_label],
      ['Tipo', entry.actor_type === 'guest' ? 'Convidado' : 'Usuário logado'],
      ['ID usuário', entry.user_id ?? '—'],
      ['Ação', entry.action],
      ['Mensagem', entry.message ?? '—'],
      ['Tipo entidade', entry.entity_type ?? '—'],
      ['ID entidade', entry.entity_id ?? '—'],
      ['IP', entry.ip_address ?? '—'],
      ['SO', entry.client_os ?? '—'],
      ['Navegador', entry.client_browser ?? '—'],
      ['Plataforma', entry.client_platform ?? '—'],
      ['Host reverso', entry.reverse_hostname ?? '—'],
      ['User-Agent', entry.user_agent ?? '—'],
    ];

    grid.innerHTML = detailFields.map(([label, value]) => {
      return `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(String(value ?? '—'))}</dd></div>`;
    }).join('');

    if (entry.details) {
      grid.innerHTML += `<div class="activity-log-detail-json"><dt>Detalhes</dt><dd><pre>${escapeHtml(JSON.stringify(entry.details, null, 2))}</pre></dd></div>`;
    }

    section.classList.remove('hidden');
    afterLayout(() => section.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
  }

  function closeDetail() {
    el('activityLogDetailSection')?.classList.add('hidden');
    const grid = el('activityLogDetailGrid');
    if (grid) grid.innerHTML = '';
  }

  function toggleColumnsMenu(show) {
    const menu = el('activityLogColumnsMenu');
    const btn = el('activityLogColumnsBtn');
    if (!menu || !btn) return;
    const open = show ?? menu.classList.contains('hidden');
    menu.classList.toggle('hidden', !open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function initToolbarIcons() {
    if (!window.OcIcons) return;
    const iconWrap = document.querySelector('.activity-log-icon');
    if (iconWrap) iconWrap.innerHTML = window.OcIcons.svg('clipboard-list', 28);

    const buttons = [
      ['activityLogColumnsBtn', 'columns', false],
      ['activityLogExportCsv', 'csv', true],
      ['activityLogExportXlsx', 'excel', true],
      ['activityLogExportPdf', 'acrobat', true],
    ];
    buttons.forEach(([id, name, brand]) => {
      const btn = el(id);
      if (!btn) return;
      btn.innerHTML = brand
        ? window.OcIcons.brandSvg(name, 20)
        : window.OcIcons.svg(name, 20);
    });
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;

    initToolbarIcons();

    el('activityLogModalClose')?.addEventListener('click', close);

    el('activityLogSearch')?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => reloadLogs().catch((err) => showAlert(err.message)), 350);
    });

    el('activityLogActionFilter')?.addEventListener('change', () => {
      reloadLogs().catch((err) => showAlert(err.message));
    });

    el('activityLogLoadMore')?.addEventListener('click', () => {
      fetchLogs(true).catch((err) => showAlert(err.message));
    });

    el('activityLogColumnsBtn')?.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleColumnsMenu();
    });

    el('activityLogColumnsMenu')?.addEventListener('change', (e) => {
      const input = e.target.closest('input[data-col]');
      if (!input) return;
      const key = input.dataset.col;
      if (input.checked) {
        if (!visibleColumns.includes(key)) visibleColumns.push(key);
      } else {
        if (visibleColumns.length <= 1) {
          input.checked = true;
          return showAlert('Mantenha ao menos uma coluna visível.');
        }
        visibleColumns = visibleColumns.filter((k) => k !== key);
      }
      saveVisibleColumns();
      renderTable();
    });

    document.addEventListener('click', (e) => {
      const wrap = document.querySelector('.activity-log-columns-wrap');
      if (wrap && !wrap.contains(e.target)) toggleColumnsMenu(false);
    });

    el('activityLogExportCsv')?.addEventListener('click', () => {
      window.location.href = exportUrl('csv');
    });
    el('activityLogExportXlsx')?.addEventListener('click', () => {
      window.location.href = exportUrl('xlsx');
    });
    el('activityLogExportPdf')?.addEventListener('click', () => {
      window.location.href = exportUrl('pdf');
    });

    el('activityLogDetailClose')?.addEventListener('click', closeDetail);
  }

  function isFocusableOutsideModal(node, modal) {
    if (!(node instanceof HTMLElement)) return false;
    if (!document.contains(node)) return false;
    if (modal?.contains(node)) return false;
    if (node.id === 'activityLogModalClose') return false;
    if (node.closest('.hidden')) return false;
    return true;
  }

  function restoreFocusAfterModal() {
    const modal = el('activityLogModal');
    const candidates = [modalReturnFocus, el('userMenuToggle')];
    modalReturnFocus = null;
    for (const node of candidates) {
      if (!isFocusableOutsideModal(node, modal)) continue;
      node.focus({ preventScroll: true });
      if (!modal?.contains(document.activeElement)) return;
    }
    el('userMenuToggle')?.focus({ preventScroll: true });
  }

  function open() {
    if (document.body.classList.contains('force-password-open')) {
      window.alert('Altere sua senha antes de acessar o log de eventos.');
      return;
    }
    const modal = el('activityLogModal');
    if (!modal) {
      window.alert('Log de eventos indisponível nesta página. Recarregue após o login.');
      return;
    }

    const active = document.activeElement;
    modalReturnFocus = isFocusableOutsideModal(active, modal) ? active : el('userMenuToggle');

    bindEvents();
    closeDetail();
    visibleColumns = loadVisibleColumns();
    el('activityLogAlert')?.classList.add('hidden');
    if (el('activityLogSearch')) el('activityLogSearch').value = '';
    if (el('activityLogActionFilter')) el('activityLogActionFilter').value = '';

    reloadLogs().catch((err) => showAlert(err.message));

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
    afterLayout(() => el('activityLogSearch')?.focus());
  }

  function close() {
    const modal = el('activityLogModal');
    if (!modal) return;
    closeDetail();
    toggleColumnsMenu(false);
    restoreFocusAfterModal();
    if (document.activeElement instanceof HTMLElement && modal.contains(document.activeElement)) {
      el('userMenuToggle')?.focus({ preventScroll: true });
    }
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    el('activityLogAlert')?.classList.add('hidden');
  }

  function init() {
    bindEvents();
    if (new URLSearchParams(window.location.search).get('activityLog') === '1') {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('activityLog');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  window.OcActivityLog = { open, close, init };
})();
