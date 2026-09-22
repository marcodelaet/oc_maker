(function () {
  const BRAND = '#2563eb';
  const META = '#94a3b8';
  const MA7 = '#60a5fa';
  const PROJECTION = '#f59e0b';
  const FILL = 'rgba(37, 99, 235, 0.08)';

  /** @type {Map<string, { canvas: HTMLCanvasElement, redraw: () => void, onMove?: (e: MouseEvent) => void, onLeave?: () => void }>} */
  const mounted = new Map();

  function destroyAll() {
    mounted.forEach(({ canvas, onMove, onLeave }) => {
      if (onMove) canvas.removeEventListener('mousemove', onMove);
      if (onLeave) canvas.removeEventListener('mouseleave', onLeave);
      const clone = canvas.cloneNode(true);
      canvas.replaceWith(clone);
    });
    mounted.clear();
    hideTooltip();
  }

  function formatValue(value, isMoney) {
    if (isMoney) {
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value || 0);
    }
    return Math.round(Number(value) || 0).toLocaleString('pt-BR');
  }

  function formatTooltipValue(value, isMoney) {
    if (isMoney) {
      return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value || 0);
    }
    const n = Number(value) || 0;
    return Number.isInteger(n)
      ? n.toLocaleString('pt-BR')
      : n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function seriesHasValues(data) {
    return (data || []).some((v) => Number(v) > 0);
  }

  function maxValue(seriesList) {
    let max = 0;
    seriesList.forEach((series) => {
      (series.data || []).forEach((v) => {
        if (v === null || v === undefined) return;
        max = Math.max(max, Number(v) || 0);
      });
    });
    return max <= 0 ? 1 : max * 1.08;
  }

  function getTooltipEl() {
    let tip = document.getElementById('oc-pacing-chart-tooltip');
    if (!tip) {
      tip = document.createElement('div');
      tip.id = 'oc-pacing-chart-tooltip';
      tip.className = 'campaign-pacing-tooltip hidden';
      document.body.appendChild(tip);
    }
    return tip;
  }

  function hideTooltip() {
    getTooltipEl().classList.add('hidden');
  }

  function showTooltip(html, clientX, clientY) {
    const tip = getTooltipEl();
    tip.innerHTML = html;
    tip.classList.remove('hidden');

    const margin = 12;
    const rect = tip.getBoundingClientRect();
    let left = clientX + margin;
    let top = clientY - rect.height - margin;
    if (left + rect.width > window.innerWidth - 8) left = clientX - rect.width - margin;
    if (top < 8) top = clientY + margin;
    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;
  }

  function buildLayout(canvas, labels, seriesList) {
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.floor(rect.width || canvas.clientWidth || 320));
    const height = Math.max(180, Math.floor(rect.height || 220));
    const pad = { top: 18, right: 12, bottom: 28, left: 52 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;
    const count = Math.max(labels.length, 1);
    const yMax = maxValue(seriesList);

    return {
      width,
      height,
      pad,
      plotW,
      plotH,
      count,
      yMax,
      xLine: (index) => pad.left + (count === 1 ? plotW / 2 : (plotW * index) / (count - 1)),
      xBar: (index) => pad.left + (plotW * (index + 0.5)) / count,
      slotW: plotW / count,
      yAt: (value) => pad.top + plotH - (plotH * (Number(value) || 0)) / yMax,
    };
  }

  function indexAtMouse(mouseX, layout, mode) {
    const localX = mouseX - layout.pad.left;
    if (localX < 0 || localX > layout.plotW) return -1;
    if (layout.count <= 1) return 0;
    if (mode === 'bar') {
      const idx = Math.floor(localX / layout.slotW);
      return idx >= 0 && idx < layout.count ? idx : -1;
    }
    const ratio = localX / layout.plotW;
    return Math.max(0, Math.min(layout.count - 1, Math.round(ratio * (layout.count - 1))));
  }

  function buildTooltipHtml(label, rows, isMoney) {
    const items = rows
      .filter((row) => row.value !== null && row.value !== undefined)
      .map((row) => `<div class="campaign-pacing-tooltip-row">
        <span class="campaign-pacing-tooltip-dot" style="background:${row.color}"></span>
        <span class="campaign-pacing-tooltip-label">${row.name}</span>
        <strong>${formatTooltipValue(row.value, isMoney)}</strong>
      </div>`)
      .join('');
    if (!items) return '';
    return `<div class="campaign-pacing-tooltip-date">${label}</div>${items}`;
  }

  function attachTooltip(canvas, config) {
    const onMove = (event) => {
      const rect = canvas.getBoundingClientRect();
      const mouseX = event.clientX - rect.left;
      const layout = buildLayout(canvas, config.labels, config.seriesList);
      const index = indexAtMouse(mouseX, layout, config.mode);
      if (index < 0) {
        hideTooltip();
        canvas.style.cursor = 'default';
        return;
      }

      const rows = config.series.map((series) => ({
        name: series.label,
        color: series.color,
        value: series.data?.[index],
      })).filter((row) => row.value !== null && row.value !== undefined);

      if (!rows.length) {
        hideTooltip();
        canvas.style.cursor = 'default';
        return;
      }

      const label = config.labels[index] ?? `#${index + 1}`;
      const html = buildTooltipHtml(label, rows, config.isMoney);
      if (!html) {
        hideTooltip();
        return;
      }

      canvas.style.cursor = 'crosshair';
      showTooltip(html, event.clientX, event.clientY);
    };

    const onLeave = () => {
      hideTooltip();
      canvas.style.cursor = 'default';
    };

    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseleave', onLeave);
    return { onMove, onLeave };
  }

  function drawLineSeries(ctx, data, xAt, yAt, series) {
    let started = false;
    data.forEach((value, index) => {
      if (value === null || value === undefined) {
        started = false;
        return;
      }
      const x = xAt(index);
      const y = yAt(value);
      if (!started) {
        ctx.moveTo(x, y);
        started = true;
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.strokeStyle = series.color;
    ctx.lineWidth = series.width || 2;
    if (series.dashed) ctx.setLineDash(series.dash || [6, 4]);
    else ctx.setLineDash([]);
    ctx.stroke();
    ctx.setLineDash([]);
  }

  function setupCanvas(canvas) {
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.floor(rect.width || canvas.clientWidth || 320));
    const height = Math.max(180, Math.floor(rect.height || 220));
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width, height };
  }

  function drawChart(canvas, config) {
    const setup = setupCanvas(canvas);
    if (!setup) return;
    const { ctx, width, height } = setup;
    const labels = config.labels || [];
    const series = config.series || [];
    const layout = buildLayout(canvas, labels, series);
    const { pad, plotW, plotH, count, yMax } = layout;

    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);

    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = pad.top + (plotH * i) / 4;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(width - pad.right, y);
      ctx.stroke();
      const val = yMax * (1 - i / 4);
      ctx.fillStyle = '#64748b';
      ctx.font = '11px system-ui, sans-serif';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(formatValue(val, config.isMoney), pad.left - 8, y);
    }

    const xAt = layout.xLine;
    const yAt = layout.yAt;

    series.forEach((item) => {
      const points = (item.data || [])
        .map((value, index) => (value === null || value === undefined ? null : { x: xAt(index), y: yAt(value), value }))
        .filter(Boolean);

      if (item.fill && points.length > 1) {
        ctx.beginPath();
        ctx.moveTo(points[0].x, pad.top + plotH);
        points.forEach((p) => ctx.lineTo(p.x, p.y));
        ctx.lineTo(points[points.length - 1].x, pad.top + plotH);
        ctx.closePath();
        ctx.fillStyle = item.fill;
        ctx.fill();
      }

      ctx.beginPath();
      drawLineSeries(ctx, item.data || [], xAt, yAt, item);

      if (item.points) {
        points.forEach((p) => {
          ctx.beginPath();
          ctx.fillStyle = item.color;
          ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    });

    ctx.fillStyle = '#64748b';
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const step = count > 20 ? Math.ceil(count / 10) : count > 12 ? 2 : 1;
    labels.forEach((label, index) => {
      if (index % step !== 0 && index !== count - 1) return;
      ctx.fillText(String(label), xAt(index), pad.top + plotH + 8);
    });
  }

  function drawBarChart(canvas, config) {
    const barsHidden = !!config.barsHidden;
    const setup = setupCanvas(canvas);
    if (!setup) return;
    const { ctx, width, height } = setup;
    const labels = config.labels || [];
    const bars = config.bars || [];
    const line = config.line || null;
    const extraLines = config.extraLines || [];
    const seriesList = [
      { data: bars },
      line ? { data: line.data } : { data: [] },
      ...extraLines.map((item) => ({ data: item.data })),
    ];
    const layout = buildLayout(canvas, labels, seriesList);
    const { pad, plotW, plotH, count, yMax, slotW } = layout;

    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);

    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = pad.top + (plotH * i) / 4;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(width - pad.right, y);
      ctx.stroke();
      const val = yMax * (1 - i / 4);
      ctx.fillStyle = '#64748b';
      ctx.font = '11px system-ui, sans-serif';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(formatValue(val, config.isMoney), pad.left - 8, y);
    }

    const barW = Math.max(2, slotW * 0.55);
    if (!barsHidden) {
      bars.forEach((value, index) => {
        const x = pad.left + slotW * index + (slotW - barW) / 2;
        const h = (plotH * (Number(value) || 0)) / yMax;
        const y = pad.top + plotH - h;
        ctx.fillStyle = BRAND;
        ctx.fillRect(x, y, barW, h);
      });
    }

    const xAtBar = layout.xBar;
    const yAtBar = layout.yAt;

    if (line) {
      ctx.beginPath();
      drawLineSeries(ctx, line.data, (index) => pad.left + (slotW * index) + slotW / 2, yAtBar, { color: META, width: 2, dashed: true });
    }

    extraLines.forEach((extra) => {
      ctx.beginPath();
      drawLineSeries(ctx, extra.data, (index) => pad.left + (slotW * index) + slotW / 2, yAtBar, extra);
    });

    ctx.fillStyle = '#64748b';
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const step = count > 20 ? Math.ceil(count / 10) : count > 12 ? 2 : 1;
    labels.forEach((label, index) => {
      if (index % step !== 0 && index !== count - 1) return;
      ctx.fillText(String(label), pad.left + slotW * index + slotW / 2, pad.top + plotH + 8);
    });
  }

  function mountChart(canvas, key, redraw, tooltipConfig) {
    const existing = mounted.get(key);
    if (existing?.onMove) canvas.removeEventListener('mousemove', existing.onMove);
    if (existing?.onLeave) canvas.removeEventListener('mouseleave', existing.onLeave);

    const handlers = attachTooltip(canvas, tooltipConfig);
    mounted.set(key, { canvas, redraw, ...handlers });
  }

  function renderScope(scopeId, pacing, metric) {
    if (!pacing?.labels?.length) return;
    const isMoney = metric === 'consumo';
    const cumulativeCanvas = document.getElementById(`pacing-${scopeId}-cumulative`);
    const dailyCanvas = document.getElementById(`pacing-${scopeId}-daily`);
    if (!cumulativeCanvas || !dailyCanvas) return;

    const cumulativeKey = `${scopeId}-cumulative-${metric}`;
    const dailyKey = `${scopeId}-daily-${metric}`;
    const plannedCumulative = pacing.planned_cumulative[metric] || [];
    const plannedDaily = pacing.planned_daily[metric] || [];
    const actualCumulative = pacing.actual_cumulative[metric] || [];
    const actualDaily = pacing.actual_daily[metric] || [];
    const movingAvg7 = pacing.moving_avg_7_daily?.[metric] || [];
    const projectedCumulative = pacing.projected_cumulative?.[metric] || [];
    const showActual = pacing.has_actual && (seriesHasValues(actualCumulative) || seriesHasValues(actualDaily));
    const showProjection = showActual && projectedCumulative.some((v) => v !== null && v !== undefined);
    const showMa7 = showActual && seriesHasValues(movingAvg7);

    const cumulativeSeries = [
      {
        label: 'Meta acumulada',
        data: plannedCumulative,
        color: META,
        dashed: true,
        width: 2.5,
      },
    ];
    if (showActual) {
      cumulativeSeries.push({
        label: 'Real acumulado',
        data: actualCumulative,
        color: BRAND,
        fill: FILL,
        points: true,
      });
    }
    if (showProjection) {
      cumulativeSeries.push({
        label: 'Projeção',
        data: projectedCumulative,
        color: PROJECTION,
        dashed: true,
        dash: [4, 4],
        width: 2,
      });
    }

    const dailyTooltipSeries = [
      { label: 'Meta diária', data: plannedDaily, color: META },
    ];
    if (showActual) {
      dailyTooltipSeries.unshift({ label: 'Real diário', data: actualDaily, color: BRAND });
    }
    if (showMa7) {
      dailyTooltipSeries.push({ label: 'MM7', data: movingAvg7, color: MA7 });
    }

    const cumulativeRedraw = () => drawChart(cumulativeCanvas, {
      labels: pacing.labels,
      isMoney,
      series: cumulativeSeries,
    });

    const extraLines = showMa7
      ? [{ label: 'MM7', data: movingAvg7, color: MA7, width: 2 }]
      : [];

    const dailyRedraw = () => drawBarChart(dailyCanvas, {
      labels: pacing.labels,
      isMoney,
      bars: showActual ? actualDaily : (plannedDaily || []).map(() => 0),
      line: {
        label: 'Meta diária',
        data: plannedDaily,
      },
      extraLines,
      barsHidden: !showActual,
    });

    cumulativeRedraw();
    dailyRedraw();

    mountChart(cumulativeCanvas, cumulativeKey, cumulativeRedraw, {
      mode: 'line',
      labels: pacing.labels,
      isMoney,
      series: cumulativeSeries.map((s) => ({ label: s.label, data: s.data, color: s.color })),
      seriesList: cumulativeSeries,
    });

    mountChart(dailyCanvas, dailyKey, dailyRedraw, {
      mode: 'bar',
      labels: pacing.labels,
      isMoney,
      series: dailyTooltipSeries,
      seriesList: [
        { data: showActual ? actualDaily : [] },
        { data: plannedDaily },
        ...extraLines.map((item) => ({ data: item.data })),
      ],
    });
  }

  function renderAll(scopes) {
    destroyAll();
    scopes.forEach(({ scopeId, pacing, metric }) => renderScope(scopeId, pacing, metric));
  }

  function movingAverage7(values, isMoney) {
    return (values || []).map((_, i) => {
      const window = values.slice(Math.max(0, i - 6), i + 1);
      const avg = window.reduce((sum, v) => sum + (Number(v) || 0), 0) / Math.max(1, window.length);
      return isMoney ? Math.round(avg * 100) / 100 : Math.round(avg);
    });
  }

  function projectionAnchorIndex(dateIsoList, actualDaily) {
    if (!dateIsoList.length) return -1;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const start = new Date(`${dateIsoList[0]}T12:00:00`);
    const end = new Date(`${dateIsoList[dateIsoList.length - 1]}T12:00:00`);

    if (today < start) return -1;

    let todayIndex = -1;
    let lastDataIndex = -1;
    dateIsoList.forEach((iso, i) => {
      const cursor = new Date(`${iso}T12:00:00`);
      if ((Number(actualDaily[i]) || 0) > 0) lastDataIndex = i;
      if (cursor <= today) todayIndex = i;
    });

    if (lastDataIndex < 0) return -1;
    if (today > end) return lastDataIndex;
    return Math.min(todayIndex, lastDataIndex);
  }

  function projectedCumulativeSeries(dateIsoList, actualDaily, actualCumulative, isMoney) {
    const count = actualDaily.length;
    if (!count) return [];

    const asOfIndex = projectionAnchorIndex(dateIsoList, actualDaily);
    if (asOfIndex < 0) return Array(count).fill(null);

    const window = actualDaily.slice(Math.max(0, asOfIndex - 6), asOfIndex + 1);
    const positiveDays = window.filter((v) => (Number(v) || 0) > 0);
    let runRate;
    if (positiveDays.length) {
      runRate = positiveDays.reduce((sum, v) => sum + (Number(v) || 0), 0) / positiveDays.length;
    } else {
      const elapsedDays = asOfIndex + 1;
      runRate = elapsedDays > 0 ? (Number(actualCumulative[asOfIndex]) || 0) / elapsedDays : 0;
    }

    const projected = Array(count).fill(null);
    const base = Number(actualCumulative[asOfIndex]) || 0;
    projected[asOfIndex] = isMoney ? Math.round(base * 100) / 100 : Math.round(base);
    for (let i = asOfIndex + 1; i < count; i += 1) {
      const value = base + (runRate * (i - asOfIndex));
      projected[i] = isMoney ? Math.round(value * 100) / 100 : Math.round(value);
    }
    return projected;
  }

  function rebuildPlannedSeries(dateIsoList, targets) {
    const daysTotal = Math.max(1, dateIsoList.length);
    const targetMap = {
      impressoes: Number(targets?.impressions ?? targets?.impressoes ?? 0),
      impactos: Number(targets?.impactos ?? 0),
      consumo: Number(targets?.consumo ?? 0),
    };
    const metrics = ['impressoes', 'impactos', 'consumo'];
    const plannedDaily = {};
    const plannedCumulative = {};

    metrics.forEach((metric) => {
      const isMoney = metric === 'consumo';
      const total = targetMap[metric];
      const dailyRaw = total / daysTotal;
      plannedDaily[metric] = [];
      plannedCumulative[metric] = [];
      let running = 0;
      dateIsoList.forEach(() => {
        const roundedDaily = isMoney ? Math.round(dailyRaw * 100) / 100 : Math.round(dailyRaw);
        plannedDaily[metric].push(roundedDaily);
        running += dailyRaw;
        plannedCumulative[metric].push(isMoney ? Math.round(running * 100) / 100 : Math.round(running));
      });
    });

    return { planned_daily: plannedDaily, planned_cumulative: plannedCumulative };
  }

  /** Recalcula realizado/MM7/projeção; opcionalmente reconstrói a meta planejada (filtros). */
  function recomputeActual(basePacing, filteredReports, dateIsoList, filteredTargets = null) {
    if (!basePacing?.labels?.length || !dateIsoList.length) return basePacing;

    const byDate = {};
    (filteredReports || []).forEach((row) => {
      const dateKey = String(row.report_date || '').slice(0, 10);
      if (!dateKey) return;
      if (!byDate[dateKey]) {
        byDate[dateKey] = { impressoes: 0, impactos: 0, consumo: 0 };
      }
      byDate[dateKey].impressoes += Number(row.impressoes) || 0;
      byDate[dateKey].impactos += Number(row.impactos) || 0;
      byDate[dateKey].consumo += Number(row.consumo) || 0;
    });

    const metrics = ['impressoes', 'impactos', 'consumo'];
    const actualDaily = {};
    const actualCumulative = {};
    let hasActual = false;

    metrics.forEach((metric) => {
      actualDaily[metric] = [];
      actualCumulative[metric] = [];
      let running = 0;
      dateIsoList.forEach((iso) => {
        const dayValue = Number(byDate[iso]?.[metric]) || 0;
        if (dayValue > 0) hasActual = true;
        const roundedDaily = metric === 'consumo'
          ? Math.round(dayValue * 100) / 100
          : Math.round(dayValue);
        actualDaily[metric].push(roundedDaily);
        running += dayValue;
        actualCumulative[metric].push(
          metric === 'consumo' ? Math.round(running * 100) / 100 : Math.round(running),
        );
      });
    });

    const movingAvg7Daily = {};
    const projectedCumulative = {};
    metrics.forEach((metric) => {
      const isMoney = metric === 'consumo';
      movingAvg7Daily[metric] = movingAverage7(actualDaily[metric], isMoney);
      projectedCumulative[metric] = projectedCumulativeSeries(
        dateIsoList,
        actualDaily[metric],
        actualCumulative[metric],
        isMoney,
      );
    });

    const result = {
      ...basePacing,
      actual_daily: actualDaily,
      actual_cumulative: actualCumulative,
      moving_avg_7_daily: movingAvg7Daily,
      projected_cumulative: projectedCumulative,
      has_actual: hasActual,
    };

    if (filteredTargets) {
      const planned = rebuildPlannedSeries(dateIsoList, filteredTargets);
      result.planned_daily = planned.planned_daily;
      result.planned_cumulative = planned.planned_cumulative;
      result.targets_filtered = true;
    }

    return result;
  }

  window.OcPacingCharts = {
    renderAll,
    destroyAll,
    renderScope,
    recomputeActual,
  };
})();
