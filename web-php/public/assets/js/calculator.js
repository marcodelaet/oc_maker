(function () {
  let initialized = false;
  let modalReturnFocus = null;

  function el(id) {
    return document.getElementById(id);
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.calculator || '';
  }

  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function escapeHtml(text) {
    return String(text ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);
  }

  function showAlert(msg, ok) {
    const alert = el('calculatorAlert');
    if (!alert) return;
    alert.className = ok
      ? 'alert alert-success calculator-panel-alert'
      : 'alert alert-error calculator-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
  }

  function hideAlert() {
    el('calculatorAlert')?.classList.add('hidden');
  }

  function getGlobalParams() {
    return {
      tipoProduto: el('calculatorTipoProduto')?.value.trim() || 'PADRÃO',
      tipoCompra: el('calculatorTipoCompra')?.value || 'PROGRAMÁTICA',
    };
  }

  function resetGlobalParams() {
    if (el('calculatorTipoProduto')) el('calculatorTipoProduto').value = 'PADRÃO';
    if (el('calculatorTipoCompra')) el('calculatorTipoCompra').value = 'PROGRAMÁTICA';
  }

  function copyButtonHtml() {
    const icon = window.OcIcons?.svg('copy', 16) || '⎘';
    return `<button type="button" class="calculator-table-copy" data-calculator-copy="1" aria-label="Copiar tabela" title="Copiar tabela">${icon}</button>`;
  }

  function readTableRowValue(valueCell) {
    if (!valueCell) return '';
    const input = valueCell.querySelector('[data-revenue-percent]');
    if (input) {
      const span = valueCell.querySelector('[data-revenue-value]');
      return `${input.value || '0'}% ${span?.textContent || ''}`.trim();
    }
    return valueCell.textContent.trim();
  }

  function exportTableForClipboard(table) {
    const title = table.querySelector('.calculator-table-title-text')?.textContent.trim() || '';
    const rows = [];
    table.querySelectorAll('tbody tr').forEach((tr) => {
      const labelCell = tr.querySelector('td.label');
      const valueCell = tr.querySelector('td:not(.label)');
      if (!labelCell || !valueCell) return;
      rows.push({
        label: labelCell.textContent.trim(),
        value: readTableRowValue(valueCell),
        red: labelCell.classList.contains('value-red') || valueCell.classList.contains('value-red'),
      });
    });

    let html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;width:100%;">';
    html += `<tr><th colspan="2" style="background:#f97316;color:#ffffff;padding:8px;text-align:left;font-weight:bold;">${escapeHtml(title)}</th></tr>`;
    rows.forEach(({ label, value, red }) => {
      const labelStyle = red
        ? 'border:1px solid #cbd5e1;padding:6px;width:42%;color:#b91c1c;font-weight:bold;'
        : 'border:1px solid #cbd5e1;padding:6px;width:42%;font-weight:bold;';
      const valueStyle = red
        ? 'border:1px solid #cbd5e1;padding:6px;color:#b91c1c;font-weight:bold;'
        : 'border:1px solid #cbd5e1;padding:6px;';
      html += `<tr><td style="${labelStyle}">${escapeHtml(label)}</td><td style="${valueStyle}">${escapeHtml(value)}</td></tr>`;
    });
    html += '</table>';

    const plain = [title, ...rows.map((row) => `${row.label}\t${row.value}`)].join('\n');
    return { html: `<html><body>${html}</body></html>`, plain };
  }

  async function copyRichText(html, plain) {
    if (navigator.clipboard?.write && window.ClipboardItem) {
      try {
        await navigator.clipboard.write([
          new ClipboardItem({
            'text/html': new Blob([html], { type: 'text/html' }),
            'text/plain': new Blob([plain], { type: 'text/plain' }),
          }),
        ]);
        return;
      } catch (_) {
        /* fallback below */
      }
    }

    const node = document.createElement('div');
    node.contentEditable = 'true';
    node.innerHTML = html;
    node.style.position = 'fixed';
    node.style.left = '-9999px';
    document.body.appendChild(node);
    const range = document.createRange();
    range.selectNodeContents(node);
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    document.execCommand('copy');
    sel?.removeAllRanges();
    node.remove();

    if (navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(plain);
      } catch (_) {
        /* plain text already attempted via execCommand */
      }
    }
  }

  function showCopyFeedback(button) {
    const previousLabel = button.getAttribute('aria-label') || 'Copiar tabela';
    button.setAttribute('aria-label', 'Copiado!');
    button.setAttribute('title', 'Copiado!');
    button.classList.add('calculator-table-copy--done');
    window.setTimeout(() => {
      button.setAttribute('aria-label', previousLabel);
      button.setAttribute('title', previousLabel);
      button.classList.remove('calculator-table-copy--done');
    }, 1800);
  }

  function bindCopyButtons(container) {
    container.querySelectorAll('[data-calculator-copy]').forEach((button) => {
      button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const table = button.closest('table');
        if (!table) return;
        try {
          const payload = exportTableForClipboard(table);
          await copyRichText(payload.html, payload.plain);
          showCopyFeedback(button);
        } catch (_) {
          showAlert('Não foi possível copiar a tabela. Tente novamente.');
        }
      });
    });
  }

  function renderEntry(entry, title, globalParams) {
    const total = Number(entry.total_revenue || 0);

    return `<table class="calculator-table"><thead><tr><th colspan="2"><div class="calculator-table-title-inner"><span class="calculator-table-title-text">${escapeHtml(title)}</span>${copyButtonHtml()}</div></th></tr></thead><tbody>
      <tr><td class="label">AGÊNCIA</td><td>${escapeHtml(entry.agencia || '—')}</td></tr>
      <tr><td class="label">VAREJISTA</td><td>${escapeHtml(entry.varejista || '—')}</td></tr>
      <tr><td class="label">TIPO DE PRODUTO</td><td>${escapeHtml(globalParams.tipoProduto)}</td></tr>
      <tr><td class="label">TIPO DE COMPRA</td><td>${escapeHtml(globalParams.tipoCompra)}</td></tr>
      <tr><td class="label">QTD. LOJAS</td><td>${escapeHtml(entry.qtd_lojas)}</td></tr>
      <tr><td class="label">PERÍODO DE VEICULAÇÃO</td><td>${escapeHtml(entry.periodo_veiculacao)}</td></tr>
      <tr><td class="label">TOTAL REVENUE</td><td>${escapeHtml(fmtCurrency(entry.total_revenue))}</td></tr>
      <tr><td class="label value-red">REVENUE VAREJISTA (%)</td><td class="value-red"><input type="number" min="0" max="100" step="0.01" value="0" class="calculator-percent-input" data-revenue-percent data-total="${total}"> <span data-revenue-value>R$ 0,00</span></td></tr>
      <tr><td class="label">PRAZO DE RECEBIMENTO (PI)</td><td>${escapeHtml(entry.prazo_recebimento_pi)}</td></tr>
      <tr><td class="label value-red">PRAZO DE PAGAMENTO REPASSE (15 DFM)</td><td class="value-red">${escapeHtml(entry.prazo_pagamento_repasse)}</td></tr>
    </tbody></table>`;
  }

  function bindRevenueInputs(container) {
    container.querySelectorAll('[data-revenue-percent]').forEach((input) => {
      const span = input.parentElement?.querySelector('[data-revenue-value]');
      if (!span) return;
      const update = () => {
        const total = Number(input.dataset.total || 0);
        const pct = Number(input.value || 0);
        span.textContent = fmtCurrency(total * (pct / 100));
      };
      input.addEventListener('input', update);
      update();
    });
  }

  function renderGlobalSummary(globalParams) {
    const summary = el('calculatorGlobalSummary');
    if (!summary) return;
    summary.innerHTML = `
      <span><strong>Tipo de produto:</strong> ${escapeHtml(globalParams.tipoProduto)}</span>
      <span><strong>Tipo de compra:</strong> ${escapeHtml(globalParams.tipoCompra)}</span>
    `;
    summary.classList.remove('hidden');
  }

  function setResultsLayout(active, summaryText) {
    el('calculatorPanelCard')?.classList.toggle('calculator-panel-card--has-results', !!active);
    const toolbar = el('calculatorResultsToolbar');
    const summary = el('calculatorResultsSummary');
    if (toolbar) toolbar.classList.toggle('hidden', !active);
    if (summary) summary.textContent = active ? (summaryText || '') : '';
  }

  function scrollToResults() {
    const body = el('calculatorPanelBody');
    const section = el('calculatorOutputSection');
    if (!body || !section || section.classList.contains('hidden')) return;
    afterLayout(() => {
      const bodyTop = body.getBoundingClientRect().top;
      const sectionTop = section.getBoundingClientRect().top;
      body.scrollTo({
        top: Math.max(0, body.scrollTop + (sectionTop - bodyTop) - 8),
        behavior: 'smooth',
      });
    });
  }

  function resetOutput() {
    setResultsLayout(false);
    el('calculatorOutputSection')?.classList.add('hidden');
    el('calculatorParamsSection')?.classList.add('hidden');
    if (el('calculatorOutputLead')) el('calculatorOutputLead').textContent = '';
    el('calculatorGlobalSummary')?.classList.add('hidden');
    el('calculatorScrollHint')?.classList.add('hidden');
    const output = el('calculatorOutput');
    if (output) output.innerHTML = '';
    el('calculatorPanelBody')?.scrollTo({ top: 0, behavior: 'auto' });
  }

  function onDocumentChange(documentId) {
    hideAlert();
    resetOutput();
    resetGlobalParams();

    if (!documentId) return;

    el('calculatorParamsSection')?.classList.remove('hidden');
    updateRunButtonState();
  }

  function updateRunButtonState() {
    const btn = el('calculatorRunBtn');
    const docId = el('calculatorDocumentSelect')?.value || '';
    if (btn) btn.disabled = !docId;
  }

  function populateDocumentSelect(documents) {
    const select = el('calculatorDocumentSelect');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">Selecione…</option>' + (documents || []).map((doc) => {
      const label = `${doc.document_id || ''} — ${doc.campanha || ''}`.trim();
      return `<option value="${Number(doc.id)}">${escapeHtml(label)}</option>`;
    }).join('');
    if (current && [...select.options].some((opt) => opt.value === current)) {
      select.value = current;
    }
    updateRunButtonState();
  }

  async function loadDocuments() {
    const res = await fetch(apiUrl());
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Falha ao carregar documentos');
    populateDocumentSelect(data.documents || []);
  }

  async function runCalculator() {
    const documentId = el('calculatorDocumentSelect')?.value || '';
    if (!documentId) {
      showAlert('Selecione um documento antes de calcular.');
      return;
    }

    const output = el('calculatorOutput');
    const section = el('calculatorOutputSection');
    const lead = el('calculatorOutputLead');
    const runBtn = el('calculatorRunBtn');
    if (!output || !section) return;

    hideAlert();
    output.innerHTML = '<p class="file-meta">Calculando…</p>';
    section.classList.remove('hidden');
    el('calculatorPanelCard')?.classList.add('calculator-panel-card--has-results');
    scrollToResults();
    if (runBtn) runBtn.disabled = true;

    try {
      const forceResync = el('calculatorResyncFromSheet')?.checked ? '1' : '0';
      const res = await fetch(
        `${apiUrl()}?document_id=${encodeURIComponent(documentId)}&force_resync=${forceResync}`,
      );
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Falha ao calcular');

      const calc = data.calculator || {};
      const doc = calc.document || {};
      const calcMeta = calc.meta || {};
      const inventoryMeta = data.inventory || {};
      const globalParams = getGlobalParams();

      const entryCount = (calc.entries || []).length;
      const unitCount = calcMeta.units ?? inventoryMeta.linked ?? entryCount;
      const networkCount = calcMeta.networks ?? entryCount;
      const inventorySummary = `${unitCount} unidades · ${networkCount} redes`;

      if (inventoryMeta.resynced) {
        showAlert(inventoryMeta.message || `Inventário re-sincronizado (${inventorySummary}).`, true);
      } else if (inventoryMeta.message) {
        showAlert(inventoryMeta.message, !inventoryMeta.source_path);
      } else if (
        inventoryMeta.expected
        && inventoryMeta.linked < inventoryMeta.expected
      ) {
        showAlert(`Inventário incompleto (${inventoryMeta.linked}/${inventoryMeta.expected} unidades). Gere o PDF novamente ou reenvie a planilha.`);
      } else if (entryCount > 0) {
        showAlert(`Calculado a partir do inventário salvo (${inventorySummary}).`, true);
      }

      if (lead) {
        lead.textContent = doc.document_id
          ? `${doc.document_id}${doc.campanha ? ` · ${doc.campanha}` : ''}`
          : '';
      }

      renderGlobalSummary(globalParams);

      const entries = calc.entries || [];
      const scrollHint = el('calculatorScrollHint');

      if (entries.length === 0) {
        setResultsLayout(false);
        scrollHint?.classList.add('hidden');
        output.innerHTML = '<div class="calculator-empty"><p>Nenhum dado de inventário para este documento.</p></div>';
        scrollToResults();
        return;
      }

      if (scrollHint) {
        if (entries.length > 1) {
          const names = (calcMeta.network_names || entries.map((entry) => entry.varejista)).join(', ');
          scrollHint.textContent = `${entries.length} redes encontradas (${unitCount} unidades): ${names}. Role a área abaixo para ver todas.`;
          scrollHint.classList.remove('hidden');
        } else {
          scrollHint.classList.add('hidden');
        }
      }

      output.innerHTML = entries.map((entry) => renderEntry(
        entry,
        entry.varejista || '—',
        globalParams,
      )).join('');

      bindRevenueInputs(output);
      bindCopyButtons(output);
      output.parentElement?.scrollTo({ top: 0, behavior: 'auto' });

      const docLabel = doc.document_id
        ? `${doc.document_id}${doc.campanha ? ` — ${doc.campanha}` : ''}`
        : '';
      setResultsLayout(true, docLabel ? `${docLabel} · ${inventorySummary}` : inventorySummary);
      scrollToResults();
    } catch (err) {
      setResultsLayout(false);
      section.classList.add('hidden');
      showAlert(err.message);
    } finally {
      if (runBtn) runBtn.disabled = false;
      updateRunButtonState();
    }
  }

  function initPanelIcon() {
    const iconWrap = document.querySelector('.calculator-panel-icon');
    if (iconWrap && window.OcIcons) {
      iconWrap.innerHTML = window.OcIcons.svg('calculator', 28);
    }
    const headerBtn = el('calculatorLink');
    if (headerBtn && window.OcIcons && !headerBtn.querySelector('svg')) {
      headerBtn.innerHTML = window.OcIcons.svg('calculator', 20);
    }
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;

    initPanelIcon();

    el('calculatorModalClose')?.addEventListener('click', close);
    el('calculatorPanelClose')?.addEventListener('click', close);
    el('calculatorFooterClose')?.addEventListener('click', close);
    el('calculatorEditParamsBtn')?.addEventListener('click', () => {
      setResultsLayout(false);
      el('calculatorParamsSection')?.classList.remove('hidden');
      const body = el('calculatorPanelBody');
      const target = el('calculatorDocumentSection');
      if (body && target) {
        afterLayout(() => {
          const bodyTop = body.getBoundingClientRect().top;
          const targetTop = target.getBoundingClientRect().top;
          body.scrollTo({
            top: Math.max(0, body.scrollTop + (targetTop - bodyTop) - 8),
            behavior: 'smooth',
          });
        });
      }
    });

    el('calculatorModal')?.addEventListener('click', (e) => {
      if (e.target === e.currentTarget) close();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const modal = el('calculatorModal');
      if (!modal || modal.classList.contains('hidden')) return;
      close();
    });

    el('calculatorDocumentSelect')?.addEventListener('change', (e) => {
      onDocumentChange(e.target.value);
    });

    el('calculatorRunBtn')?.addEventListener('click', () => {
      runCalculator().catch((err) => showAlert(err.message));
    });
  }

  function isFocusableOutsideModal(node, modal) {
    if (!(node instanceof HTMLElement)) return false;
    if (!document.contains(node)) return false;
    if (modal?.contains(node)) return false;
    if (node.id === 'calculatorModalClose' || node.id === 'calculatorPanelClose' || node.id === 'calculatorFooterClose') {
      return false;
    }
    if (node.closest('.hidden')) return false;
    return true;
  }

  function restoreFocusAfterModal() {
    const modal = el('calculatorModal');
    const candidates = [modalReturnFocus, el('calculatorLink'), el('userMenuToggle')];
    modalReturnFocus = null;
    for (const node of candidates) {
      if (!isFocusableOutsideModal(node, modal)) continue;
      node.focus({ preventScroll: true });
      if (!modal?.contains(document.activeElement)) return;
    }
    el('calculatorLink')?.focus({ preventScroll: true });
  }

  function open() {
    if (document.body.classList.contains('force-password-open')) return;
    const modal = el('calculatorModal');
    if (!modal) {
      window.location.href = window.OC_MAKER?.urls?.calculator || 'calculator.php';
      return;
    }

    const active = document.activeElement;
    modalReturnFocus = isFocusableOutsideModal(active, modal) ? active : el('calculatorLink');

    bindEvents();
    hideAlert();
    resetOutput();
    resetGlobalParams();
    if (el('calculatorDocumentSelect')) el('calculatorDocumentSelect').value = '';
    updateRunButtonState();

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
    afterLayout(() => el('calculatorDocumentSelect')?.focus());

    loadDocuments().catch((err) => showAlert(err.message));
  }

  function close() {
    const modal = el('calculatorModal');
    if (!modal) return;
    resetOutput();
    resetGlobalParams();
    restoreFocusAfterModal();
    if (document.activeElement instanceof HTMLElement && modal.contains(document.activeElement)) {
      el('calculatorLink')?.focus({ preventScroll: true });
    }
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    hideAlert();
  }

  function init() {
    initPanelIcon();
    bindEvents();
    updateRunButtonState();
    if (new URLSearchParams(window.location.search).get('calculator') === '1') {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('calculator');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  window.OcCalculator = { open, close, init };
})();
