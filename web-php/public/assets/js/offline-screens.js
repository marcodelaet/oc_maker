(function () {
  /** @type {Array<{screen_code?: string, rede_name?: string, offline_duration?: number, offline_unit?: string}>} */
  let screensCache = [];
  /** @type {Record<string, unknown>} */
  let docMeta = {};
  let initialized = false;

  function el(id) {
    return document.getElementById(id);
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

  /** Minutos equivalentes por unidade (para ordenação: anos > meses > dias > horas > minutos). */
  const OFFLINE_UNIT_MINUTES = {
    minutos: 1,
    horas: 60,
    dias: 60 * 24,
    meses: 60 * 24 * 30,
    anos: 60 * 24 * 365,
  };

  function offlineDurationSortKey(screen) {
    const unit = String(screen.offline_unit || 'horas').toLowerCase();
    if (unit === 'nunca') return Number.POSITIVE_INFINITY;
    const duration = Number(screen.offline_duration) || 0;
    const factor = OFFLINE_UNIT_MINUTES[unit] ?? OFFLINE_UNIT_MINUTES.horas;
    return duration * factor;
  }

  function sortOfflineScreens(screens) {
    return [...screens].sort((a, b) => {
      const diff = offlineDurationSortKey(b) - offlineDurationSortKey(a);
      if (diff !== 0) return diff;
      return String(a.screen_code || '').localeCompare(String(b.screen_code || ''), 'pt-BR');
    });
  }

  function offlineDurationLabel(screen) {
    if (screen.offline_unit === 'nunca') return 'NUNCA';
    return `${screen.offline_duration || 0} ${screen.offline_unit || 'horas'}`;
  }

  function buildOfflineListCopyText(screens, doc) {
    const lines = [
      'Telas offline — suporte técnico',
      '',
      `Campanha: ${doc.campanha || '—'}`,
      `Anunciante: ${doc.anunciante || '—'}`,
      `ID: ${doc.ads_id || doc.document_id || '—'}`,
      `Período: ${formatDateRange(doc.inicio, doc.termino)}`,
      '',
      'Código da Face\tRede\tÚltima conexão',
      ...screens.map((s) => [
        s.screen_code || '—',
        s.rede_name || '—',
        offlineDurationLabel(s),
      ].join('\t')),
    ];
    return lines.join('\n');
  }

  function buildOfflineListCopyHtml(screens, doc) {
    const cell = 'border:1px solid #cbd5e1;padding:8px 10px;text-align:left;vertical-align:top;';
    const th = `${cell}background:#f1f5f9;font-weight:600;color:#334155;`;
    const metaLine = (label, value) => `<p style="margin:0 0 4px;color:#475569;">${escapeHtml(label)} ${escapeHtml(value)}</p>`;

    const rows = screens.map((s, index) => {
      const rowBg = index % 2 === 1 ? 'background:#fef2f2;' : 'background:#ffffff;';
      return `<tr>
        <td style="${cell}${rowBg}font-family:Consolas,Monaco,monospace;font-size:12px;">${escapeHtml(s.screen_code || '—')}</td>
        <td style="${cell}${rowBg}">${escapeHtml(s.rede_name || '—')}</td>
        <td style="${cell}${rowBg}">${escapeHtml(offlineDurationLabel(s))}</td>
      </tr>`;
    }).join('');

    return `<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1e293b;line-height:1.45;">
      <p style="margin:0 0 12px;font-size:16px;font-weight:700;color:#0f172a;">Telas offline — suporte técnico</p>
      ${metaLine('Campanha:', doc.campanha || '—')}
      ${metaLine('Anunciante:', doc.anunciante || '—')}
      ${metaLine('ID:', doc.ads_id || doc.document_id || '—')}
      ${metaLine('Período:', formatDateRange(doc.inicio, doc.termino))}
      <table style="border-collapse:collapse;width:100%;max-width:760px;margin-top:16px;font-size:13px;">
        <thead>
          <tr>
            <th style="${th}">Código da Face</th>
            <th style="${th}">Rede</th>
            <th style="${th}">Última conexão</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  async function copyRichContent(html, plainText) {
    if (navigator.clipboard?.write && typeof ClipboardItem !== 'undefined') {
      await navigator.clipboard.write([
        new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([plainText], { type: 'text/plain' }),
        }),
      ]);
      return;
    }

    const container = document.createElement('div');
    container.innerHTML = html;
    container.setAttribute('contenteditable', 'true');
    container.style.position = 'fixed';
    container.style.left = '-9999px';
    container.style.top = '0';
    document.body.appendChild(container);

    const range = document.createRange();
    range.selectNodeContents(container);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);

    const copied = document.execCommand('copy');
    selection?.removeAllRanges();
    document.body.removeChild(container);

    if (copied) return;

    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(plainText);
      return;
    }

    const ta = document.createElement('textarea');
    ta.value = plainText;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  function flashCopyButton() {
    const btn = el('campaignOfflineCopyBtn');
    if (!btn) return;
    const original = btn.innerHTML;
    btn.classList.add('campaign-offline-copy--done');
    btn.innerHTML = `${window.OcIcons?.svg('check-circle', 16) || '✓'} Copiado!`;
    window.setTimeout(() => {
      btn.classList.remove('campaign-offline-copy--done');
      btn.innerHTML = original;
    }, 1400);
  }

  function renderOfflineList(screens) {
    screensCache = sortOfflineScreens(screens);
    const wrap = el('campaignOfflineWrap');
    const copyBtn = el('campaignOfflineCopyBtn');
    if (copyBtn) copyBtn.disabled = !screensCache.length;
    if (!wrap) return;
    if (!screensCache.length) {
      wrap.innerHTML = '<p class="campaign-empty">Nenhuma tela offline nesta campanha.</p>';
      return;
    }
    const rows = screensCache.map((s) => `<tr>
        <td><code>${escapeHtml(s.screen_code)}</code></td>
        <td>${escapeHtml(s.rede_name || '—')}</td>
        <td>${escapeHtml(offlineDurationLabel(s))}</td>
      </tr>`).join('');
    wrap.innerHTML = `<table class="history-table users-table campaign-offline-table">
      <thead><tr><th>Código da Face</th><th>Rede</th><th>Última conexão</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
  }

  async function copyOfflineList() {
    if (!screensCache.length) return;
    const plainText = buildOfflineListCopyText(screensCache, docMeta);
    const html = buildOfflineListCopyHtml(screensCache, docMeta);
    await copyRichContent(html, plainText);
    flashCopyButton();
  }

  function open(screens, doc) {
    docMeta = doc || {};
    renderOfflineList(screens || []);
    el('campaignOfflineModal')?.classList.remove('hidden');
    el('campaignOfflineModal')?.setAttribute('aria-hidden', 'false');
  }

  function close() {
    el('campaignOfflineModal')?.classList.add('hidden');
    el('campaignOfflineModal')?.setAttribute('aria-hidden', 'true');
  }

  function initIcons() {
    const offlineCopyIcon = el('campaignOfflineCopyBtn')?.querySelector('.btn-icon');
    if (offlineCopyIcon && window.OcIcons) offlineCopyIcon.innerHTML = window.OcIcons.svg('copy', 16);
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;
    initIcons();
    el('campaignOfflineCopyBtn')?.addEventListener('click', () => {
      copyOfflineList().catch(() => {
        window.alert('Não foi possível copiar a lista.');
      });
    });
    el('campaignOfflineClose')?.addEventListener('click', close);
  }

  window.OcOfflineScreens = { open, close, renderOfflineList, copyOfflineList, bindEvents, initIcons };
})();
