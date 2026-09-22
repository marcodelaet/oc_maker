(function () {
  function techFeePercent(tipoVenda, planejador, fees) {
    const group = fees[tipoVenda] || {};
    if (planejador && group[planejador] != null) return Number(group[planejador]);
    return Number(group.default ?? 0);
  }

  function buildFinancials(totals, tipoVenda, planejadorSsp, fees) {
    const budgetBruto = Number(totals.bruto || 0);
    const budgetLiquido = Number(totals.liquido || 0);
    const feePercent = techFeePercent(tipoVenda, planejadorSsp, fees);
    const feeRate = feePercent / 100;
    const denom = Math.max(0.0001, 1 - feeRate);
    const brutoBase = budgetBruto / denom;
    const feeValue = (budgetBruto * feeRate) / denom;
    const impactos = Number(totals.impactos || 0);
    const cpm = impactos > 0 ? (budgetBruto / impactos) * 1000 : 0;

    return {
      totals,
      feePercent,
      feeValue,
      brutoBase,
      faturamento: budgetBruto,
      valorPublisher: budgetLiquido,
      valorPublisherPdf: budgetBruto,
      cpm,
    };
  }

  function buildSummaryPayload(campaign, tipoVenda, planejadorSsp, fees) {
    const fin = buildFinancials(campaign.totals, tipoVenda, planejadorSsp, fees);
    return {
      campaign,
      tipoVenda,
      financials: {
        totals: fin.totals,
        feePercent: fin.feePercent,
        feeValue: fin.feeValue,
        brutoBase: fin.brutoBase,
        faturamento: fin.faturamento,
        valorPublisher: fin.valorPublisher,
        valorPublisherPdf: fin.valorPublisherPdf,
        cpm: fin.cpm,
      },
    };
  }

  window.OcFinancials = { buildFinancials, buildSummaryPayload };
})();
