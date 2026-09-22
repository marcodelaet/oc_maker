export const COMPANY = {
  name: "Converta Ads Comercialização de Mídias Ltda",
  address: "Rua Capitão Rosa, 376",
  city: "Jardim Paulistano - São Paulo - SP - CEP 01443-900",
  cnpj: "CNPJ: 60.436.341/0001-36",
};

export const PDF_COLORS = {
  titleBar: "#2b2b2b",
  titleText: "#ffffff",
  tableHeader: "#f97316",
  tableHeaderText: "#ffffff",
  tableZebra: "#f5f5f5",
  footerNote: "#888888",
  label: "#333333",
};

export const PRAZO_OPCOES = [15, 30, 60, 70, 90];
export const PRAZO_UNIDADES = ["Dias", "DFM"];

export function parseIsoDate(iso) {
  if (!iso) return null;
  const [y, m, d] = iso.split("-").map(Number);
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d, 12, 0, 0);
}

export function formatDateBRFromDate(date) {
  if (!date) return "";
  const d = String(date.getDate()).padStart(2, "0");
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const y = date.getFullYear();
  return `${d}/${m}/${y}`;
}

/** @param {string|null} terminoIso @param {number} prazoDias @param {'Dias'|'DFM'} unidade */
export function calcPaymentDate(terminoIso, prazoDias, unidade) {
  const termino = parseIsoDate(terminoIso);
  if (!termino || !prazoDias) return null;
  if (unidade === "DFM") {
    const lastDay = new Date(termino.getFullYear(), termino.getMonth() + 1, 0, 12, 0, 0);
    lastDay.setDate(lastDay.getDate() + prazoDias);
    return lastDay;
  }
  const d = new Date(termino);
  d.setDate(d.getDate() + prazoDias);
  return d;
}

export function formatPrazoPagamento(terminoIso, prazoDias, unidade) {
  const payment = calcPaymentDate(terminoIso, prazoDias, unidade);
  const dateStr = payment ? formatDateBRFromDate(payment) : "—";
  return `Prazo para Pagamento: ${prazoDias} dias (${dateStr})`;
}

export function checkboxCanvas(checked) {
  const box = { type: "rect", x: 0, y: 0, w: 11, h: 11, lineWidth: 1, lineColor: "#333333" };
  if (!checked) return { canvas: [box], width: 14, height: 14, margin: [4, 0, 0, 0] };
  return {
    canvas: [
      box,
      { type: "line", x1: 2, y1: 6, x2: 5, y2: 9, lineWidth: 1.2, lineColor: "#333333" },
      { type: "line", x1: 5, y1: 9, x2: 10, y2: 2, lineWidth: 1.2, lineColor: "#333333" },
    ],
    width: 14,
    height: 14,
    margin: [4, 0, 0, 0],
  };
}

export function formatObservacoes(observacoes) {
  if (!observacoes?.length) return [{ text: "—", fontSize: 8, color: "#444" }];
  const blocks = [];
  for (const line of observacoes) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    const isHeading = /^[A-ZÁÉÍÓÚÃÕÇ0-9][A-ZÁÉÍÓÚÃÕÇ0-9\s\/\-]+:$/.test(trimmed);
    blocks.push({
      text: trimmed,
      fontSize: 8,
      lineHeight: 1.35,
      bold: isHeading,
      margin: [0, isHeading ? 6 : 2, 0, 0],
      color: "#333333",
    });
  }
  return blocks.length ? blocks : [{ text: "—", fontSize: 8 }];
}

export async function loadLogoBase64(basePath = "/maker/assets/logo_converta.png") {
  const candidates = [
    basePath,
    "/maker/assets/logo_converta.png",
    "/maker/assets/logo_converta.svg",
  ];
  for (const path of candidates) {
    try {
      const res = await fetch(path);
      if (!res.ok) continue;
      const buf = await res.arrayBuffer();
      const bytes = new Uint8Array(buf);
      let binary = "";
      for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
      const b64 = btoa(binary);
      const mime = path.endsWith(".svg") ? "image/svg+xml" : "image/png";
      return `data:${mime};base64,${b64}`;
    } catch {
      /* try next */
    }
  }
  return null;
}

export function logoBase64Sync() {
  return null;
}

export function buildMetaTwoColumns(campaign, options) {
  const { tipoVenda, tipoDeal, planejadorSsp, dealId, ocInformeSsp } = options;
  const showOcInformeSsp = tipoVenda === "SSP";
  const anunciante = [campaign.anunciante, campaign.agencia].filter(Boolean).join(" / ");

  const left = [
    { label: "Anunciante:", value: anunciante || "—" },
    { label: "Data de Início:", value: options.formatDateBR(campaign.inicio) || "—" },
    { label: "Tipo de Venda:", value: tipoVenda || "—" },
    { label: "Planejador:", value: planejadorSsp || "—" },
  ];
  if (showOcInformeSsp) left.push({ label: "OC/Informe (SSP):", value: ocInformeSsp || "—" });

  const right = [
    { label: "Campanha:", value: campaign.campanha || "—" },
    { label: "Término:", value: options.formatDateBR(campaign.termino) || "—" },
  ];
  if (tipoDeal) right.push({ label: "Tipo de DEAL:", value: tipoDeal });
  if (dealId) right.push({ label: "Deal ID:", value: dealId });

  const maxRows = Math.max(left.length, right.length);
  const body = [];
  for (let i = 0; i < maxRows; i++) {
    body.push([
      left[i] ? metaCell(left[i]) : { text: "" },
      right[i] ? metaCell(right[i]) : { text: "" },
    ]);
  }
  return body;
}

function metaCell({ label, value }) {
  return {
    text: [
      { text: label + " ", bold: true, color: "#333333" },
      { text: value, color: "#111111" },
    ],
    margin: [0, 2, 0, 2],
    fontSize: 9,
  };
}

export function buildHeaderBlock(documentTitle, documentId, logoDataUri) {
  const headerRow = [];
  if (logoDataUri) {
    headerRow.push({ image: logoDataUri, width: 130, margin: [0, 2, 0, 0] });
  } else {
    headerRow.push({
      stack: [
        { text: "Converta Ads", fontSize: 16, bold: true, color: "#3d3d3d" },
        { text: "by Retail Media", fontSize: 9, color: "#666666", margin: [0, 2, 0, 0] },
      ],
      width: 130,
    });
  }
  headerRow.push({
    stack: [
      { text: COMPANY.name, fontSize: 8, alignment: "right" },
      { text: COMPANY.address, fontSize: 8, alignment: "right", margin: [0, 2, 0, 0] },
      { text: COMPANY.city, fontSize: 8, alignment: "right", margin: [0, 2, 0, 0] },
      { text: COMPANY.cnpj, fontSize: 8, alignment: "right", margin: [0, 2, 0, 0] },
    ],
    width: "*",
  });

  return [
    { table: { widths: [140, "*"], body: [[headerRow[0], headerRow[1]]] }, layout: "noBorders", margin: [0, 0, 0, 10] },
    {
      table: {
        widths: ["*"],
        body: [[{ text: documentTitle, color: PDF_COLORS.titleText, bold: true, fontSize: 14, alignment: "center", margin: [0, 6, 0, 6] }]],
      },
      layout: {
        fillColor: () => PDF_COLORS.titleBar,
        hLineWidth: () => 0,
        vLineWidth: () => 0,
      },
      margin: [0, 0, 0, 6],
    },
    { text: `#${documentId}`, alignment: "center", fontSize: 11, bold: true, margin: [0, 0, 0, 12] },
  ];
}

export const FOOTER_NOTE =
  "* Não esquecer de enviar / anexar os criativos da campanha para solicitar aprovação das redes";
