(function () {
  function techFeePercent(tipoVenda, planejador, fees) {
    const group = fees[tipoVenda] || {};
    if (planejador && group[planejador] != null) return Number(group[planejador]);
    return Number(group.default ?? 0);
  }

  function buildFinancials(totals, tipoVenda, planejadorSsp, fees) {
    const valorSsp = Number(totals.liquido || 0);
    const feePercent = techFeePercent(tipoVenda, planejadorSsp, fees);
    const feeValue = valorSsp * (feePercent / 100);
    const valorPublisher = valorSsp - feeValue;
    const impactos = Number(totals.impactos || 0);
    const cpm = impactos > 0 ? (valorSsp / impactos) * 1000 : 0;
    return { totals, feePercent, feeValue, valorPublisher, cpm };
  }

  function buildSummaryPayload(campaign, tipoVenda, planejadorSsp, fees) {
    const fin = buildFinancials(campaign.totals, tipoVenda, planejadorSsp, fees);
    return {
      campaign,
      financials: {
        totals: fin.totals,
        feePercent: fin.feePercent,
        feeValue: fin.feeValue,
        valorPublisher: fin.valorPublisher,
        cpm: fin.cpm,
      },
    };
  }

  window.OcFinancials = { buildFinancials, buildSummaryPayload };
})();
