import {
  buildFinancials,
  formatBRL,
  formatDateBR,
  formatNumber,
  buildPdfFilename,
} from "./core.js";
import {
  PDF_COLORS,
  FOOTER_NOTE,
  buildHeaderBlock,
  buildMetaTwoColumns,
  checkboxCanvas,
  formatObservacoes,
  formatPrazoPagamento,
} from "./pdfLayout.js";

export function buildPdfDefinition(campaign, options, fees, logoDataUri = null) {
  const {
    documentId,
    documentTitle,
    tipoVenda,
    tipoDeal,
    planejadorSsp,
    dealId,
    ocInformeSsp,
    checkingFotografico,
    relatoriosAdicionais,
    prazoPagamento = 15,
    prazoUnidade = "DFM",
  } = options;

  const fin = buildFinancials(campaign, tipoVenda, planejadorSsp, fees);

  const tableBody = [
    [
      { text: "ID Loja - Converta", style: "tableHeader" },
      { text: "Rede", style: "tableHeader" },
      { text: "Total Inserções", style: "tableHeader", alignment: "right" },
      { text: "Total Impactos", style: "tableHeader", alignment: "right" },
      { text: "Budget Media Cost", style: "tableHeader", alignment: "right" },
      { text: "Budget Publisher", style: "tableHeader", alignment: "right" },
    ],
  ];

  campaign.inventory.forEach((row, idx) => {
    const zebra = idx % 2 === 1 ? PDF_COLORS.tableZebra : null;
    tableBody.push([
      { text: row.codigo, fillColor: zebra },
      { text: row.rede, fillColor: zebra },
      { text: formatNumber(row.insercoes), alignment: "right", fillColor: zebra },
      { text: formatNumber(row.impactos), alignment: "right", fillColor: zebra },
      { text: formatBRL(row.bruto), alignment: "right", fillColor: zebra },
      { text: formatBRL(row.liquido), alignment: "right", fillColor: zebra },
    ]);
  });

  tableBody.push([
    { text: "TOTAIS", bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText },
    { text: "", fillColor: PDF_COLORS.tableHeader },
    { text: formatNumber(fin.totals.insercoes), alignment: "right", bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText },
    { text: formatNumber(fin.totals.impactos), alignment: "right", bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText },
    { text: formatBRL(fin.totals.bruto), alignment: "right", bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText },
    { text: formatBRL(fin.totals.liquido), alignment: "right", bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText },
  ]);

  const metaBody = buildMetaTwoColumns(campaign, {
    tipoVenda,
    tipoDeal,
    planejadorSsp,
    dealId,
    ocInformeSsp,
    formatDateBR,
  });

  const financialStack = [
    { text: [{ text: "VALOR LÍQUIDO (SSP): ", bold: true }, formatBRL(fin.valorSsp)], alignment: "right", fontSize: 9, margin: [0, 1, 0, 1] },
    { text: [{ text: "TECH FEE (%): ", bold: true }, String(fin.feePercent), { text: "   " + formatBRL(fin.feeValue) }], alignment: "right", fontSize: 9, margin: [0, 1, 0, 1] },
    { text: [{ text: "VALOR LÍQUIDO (PUBLISHER): ", bold: true }, formatBRL(fin.valorPublisher)], alignment: "right", fontSize: 9, margin: [0, 1, 0, 1] },
    { text: [{ text: "CPM MÉDIO: ", bold: true }, formatBRL(fin.cpm)], alignment: "right", fontSize: 9, margin: [0, 1, 0, 1] },
  ];

  const prazoText = formatPrazoPagamento(campaign.termino, Number(prazoPagamento), prazoUnidade);

  return {
    pageSize: "A4",
    pageMargins: [36, 36, 36, 48],
    defaultStyle: { font: "Roboto", fontSize: 9 },
    styles: {
      tableHeader: { bold: true, fillColor: PDF_COLORS.tableHeader, color: PDF_COLORS.tableHeaderText, fontSize: 7.5 },
    },
    footer: () => ({
      columns: [{ text: FOOTER_NOTE, fontSize: 7, color: PDF_COLORS.footerNote, italics: true, margin: [36, 0, 36, 0] }],
    }),
    content: [
      ...buildHeaderBlock(documentTitle, documentId, logoDataUri),
      { table: { widths: ["*", "*"], body: metaBody }, layout: "noBorders", margin: [0, 0, 0, 10] },
      {
        table: { headerRows: 1, widths: [52, "*", 58, 58, 68, 68], body: tableBody },
        layout: {
          hLineWidth: (i, node) => (i === 0 || i === 1 || i === node.table.body.length ? 0.5 : 0.25),
          vLineWidth: () => 0.25,
          hLineColor: () => "#dddddd",
          vLineColor: () => "#dddddd",
        },
      },
      { stack: financialStack, margin: [0, 8, 0, 12] },
      {
        table: {
          widths: ["*", "*"],
          body: [[
            { columns: [{ text: "Checking Fotográfico?", width: "auto" }, checkboxCanvas(checkingFotografico)], columnGap: 4, fontSize: 9 },
            { alignment: "right", columns: [{ text: "Relatórios Adicionais?", width: "auto" }, checkboxCanvas(relatoriosAdicionais)], columnGap: 4, fontSize: 9 },
          ]],
        },
        layout: "noBorders",
        margin: [0, 0, 0, 10],
      },
      { text: "Observações:", bold: true, fontSize: 9, margin: [0, 0, 0, 4] },
      ...formatObservacoes(campaign.observacoes),
      { text: prazoText, bold: true, fontSize: 9, margin: [0, 14, 0, 0] },
    ],
  };
}
