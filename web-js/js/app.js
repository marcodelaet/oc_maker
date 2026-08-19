import {
  PLANEJADORES,
  TIPOS_DEAL,
  TIPOS_VENDA,
  buildFinancials,
  formatBRL,
  formatDateBR,
  generateDocumentId,
  listCampaigns,
  loadCampaign,
} from "./core.js";
import { downloadPdf } from "./pdf.js";
import { formatPrazoPagamento, PRAZO_OPCOES } from "./pdfLayout.js";
import { withLoading } from "./loading.js";

const state = {
  workbook: null,
  fileName: "",
  campaign: null,
  fees: {},
};

const el = (id) => document.getElementById(id);

function setStep(step) {
  document.querySelectorAll(".step").forEach((s) => {
    const n = Number(s.dataset.step);
    s.classList.toggle("active", n === step);
    s.classList.toggle("done", n < step);
  });
}

function showError(msg) {
  const box = el("alert");
  box.textContent = msg;
  box.classList.remove("hidden");
}

function hideError() {
  el("alert").classList.add("hidden");
}

async function loadFees() {
  const res = await fetch("config/tech_fees.json");
  state.fees = await res.json();
}

function readWorkbook(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        const data = new Uint8Array(e.target.result);
        const wb = XLSX.read(data, { type: "array", cellDates: false });
        resolve(wb);
      } catch (err) {
        reject(err);
      }
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsArrayBuffer(file);
  });
}

function populateCampaigns() {
  const select = el("campaign");
  select.innerHTML = "";
  const campaigns = listCampaigns(state.workbook);
  if (!campaigns.length) {
    select.innerHTML = '<option value="">Nenhuma campanha encontrada</option>';
    return;
  }
  for (const c of campaigns) {
    const opt = document.createElement("option");
    opt.value = c.ads_id;
    opt.textContent = `${c.campanha} — ${c.anunciante || "Sem anunciante"} (${c.ads_id})`;
    select.appendChild(opt);
  }
}

function getFormOptions() {
  const tipoVenda = el("tipoVenda").value;
  return {
    documentId: el("documentId").value || generateDocumentId(),
    documentTitle: el("documentTitle").value,
    tipoVenda,
    tipoDeal: el("tipoDeal").value,
    planejadorSsp: el("planejador").value,
    dealId: el("dealId").value.trim(),
    ocInformeSsp: el("ocInforme").value.trim(),
    checkingFotografico: el("checking").checked,
    relatoriosAdicionais: el("relatorios").checked,
    prazoPagamento: Number(el("prazoPagamento").value),
    prazoUnidade: el("prazoUnidade").value,
  };
}

function toggleConditionalFields() {
  const tipoVenda = el("tipoVenda").value;
  el("sspGroup").classList.toggle("hidden", tipoVenda !== "SSP");
  el("dealGroup").classList.toggle("hidden", !el("tipoDeal").value);
  el("dealIdGroup").classList.toggle("hidden", !el("dealId").value.trim());
}

function updateSummary() {
  const panel = el("summary");
  if (!state.campaign) {
    panel.innerHTML = "<p class='file-meta'>Carregue uma planilha e selecione a campanha.</p>";
    return;
  }
  const opts = getFormOptions();
  const fin = buildFinancials(state.campaign, opts.tipoVenda, opts.planejadorSsp, state.fees);
  panel.innerHTML = `
    <dl>
      <dt>Campanha</dt><dd>${state.campaign.campanha}</dd>
      <dt>Anunciante</dt><dd>${state.campaign.anunciante}</dd>
      <dt>Período</dt><dd>${formatDateBR(state.campaign.inicio)} — ${formatDateBR(state.campaign.termino)}</dd>
      <dt>Lojas</dt><dd>${state.campaign.inventory.length}</dd>
      <dt>Total Inserções</dt><dd>${fin.totals.insercoes.toLocaleString("pt-BR")}</dd>
      <dt>Total Impactos</dt><dd>${fin.totals.impactos.toLocaleString("pt-BR")}</dd>
      <dt>Budget Bruto</dt><dd>${formatBRL(fin.totals.bruto)}</dd>
      <dt>Budget Líquido</dt><dd>${formatBRL(fin.totals.liquido)}</dd>
    </dl>
    <div class="highlight">
      <dl>
        <dt>TECH FEE</dt><dd>${fin.feePercent}% (${formatBRL(fin.feeValue)})</dd>
        <dt>Valor Publisher</dt><dd>${formatBRL(fin.valorPublisher)}</dd>
        <dt>CPM Médio</dt><dd>${formatBRL(fin.cpm)}</dd>
      </dl>
    </div>
    <div class="highlight">
      <dl>
        <dt>Prazo pagamento</dt><dd>${formatPrazoPagamento(state.campaign.termino, Number(el("prazoPagamento").value), el("prazoUnidade").value)}</dd>
      </dl>
    </div>`;
  el("prazoPreview").textContent = state.campaign
    ? formatPrazoPagamento(state.campaign.termino, Number(el("prazoPagamento").value), el("prazoUnidade").value)
    : "";
}

function loadSelectedCampaignCore() {
  const adsId = el("campaign").value;
  state.campaign = loadCampaign(state.workbook, adsId || null);
  el("documentId").placeholder = generateDocumentId();
  updateSummary();
  setStep(3);
  el("generateBtn").disabled = false;
}

function loadSelectedCampaign() {
  if (!state.workbook) return;
  hideError();
  try {
    loadSelectedCampaignCore();
  } catch (err) {
    showError(err.message);
    state.campaign = null;
    updateSummary();
  }
}

async function handleFile(file) {
  hideError();
  if (!file.name.match(/\.xlsx$/i)) {
    showError("Selecione um arquivo .xlsx válido.");
    return;
  }
  try {
    await withLoading("Lendo planilha Excel…", async () => {
      state.workbook = await readWorkbook(file);
      state.fileName = file.name;
      el("fileMeta").textContent = `Arquivo: ${file.name}`;
      populateCampaigns();
      el("formSection").classList.remove("hidden");
      setStep(2);
      loadSelectedCampaignCore();
      setStep(3);
      el("generateBtn").disabled = false;
    });
  } catch (err) {
    showError("Erro ao ler planilha: " + err.message);
  }
}

async function onGenerate() {
  if (!state.campaign) return;
  hideError();
  try {
    await withLoading("Gerando PDF…", async () => {
      const opts = getFormOptions();
      if (!el("documentId").value) el("documentId").value = opts.documentId;
      await downloadPdf(state.campaign, opts, state.fees);
      setStep(4);
    });
  } catch (err) {
    showError("Erro ao gerar PDF: " + err.message);
  }
}

function initSelects() {
  const tv = el("tipoVenda");
  TIPOS_VENDA.forEach((t) => {
    const o = document.createElement("option");
    o.value = t;
    o.textContent = t;
    tv.appendChild(o);
  });
  tv.value = "SSP";

  const pl = el("planejador");
  PLANEJADORES.forEach((p) => {
    const o = document.createElement("option");
    o.value = p;
    o.textContent = p;
    pl.appendChild(o);
  });

  const td = el("tipoDeal");
  TIPOS_DEAL.forEach((t) => {
    const o = document.createElement("option");
    o.value = t;
    o.textContent = t || "(nenhum)";
    td.appendChild(o);
  });
}

function initDropzone() {
  const zone = el("dropzone");
  const input = el("fileInput");

  zone.addEventListener("click", () => input.click());
  zone.addEventListener("dragover", (e) => {
    e.preventDefault();
    zone.classList.add("dragover");
  });
  zone.addEventListener("dragleave", () => zone.classList.remove("dragover"));
  zone.addEventListener("drop", (e) => {
    e.preventDefault();
    zone.classList.remove("dragover");
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
  });
  input.addEventListener("change", () => {
    if (input.files[0]) handleFile(input.files[0]);
  });
}

function initPrazoSelects() {
  PRAZO_OPCOES.forEach((d) => {
    const o = document.createElement("option");
    o.value = String(d);
    o.textContent = `${d} dias`;
    el("prazoPagamento").appendChild(o);
  });
  el("prazoPagamento").value = "15";
}

function init() {
  initSelects();
  initPrazoSelects();
  initDropzone();
  withLoading("Carregando configurações…", () => loadFees());

  el("campaign").addEventListener("change", loadSelectedCampaign);
  el("tipoVenda").addEventListener("change", () => {
    toggleConditionalFields();
    updateSummary();
  });
  el("tipoDeal").addEventListener("change", () => {
    toggleConditionalFields();
    updateSummary();
  });
  ["planejador", "dealId", "ocInforme", "checking", "relatorios", "documentTitle", "prazoPagamento", "prazoUnidade"].forEach((id) => {
    el(id).addEventListener("input", updateSummary);
    el(id).addEventListener("change", updateSummary);
  });
  el("generateBtn").addEventListener("click", onGenerate);
  el("newIdBtn").addEventListener("click", () => {
    el("documentId").value = generateDocumentId();
  });

  toggleConditionalFields();
  updateSummary();
  setStep(1);
}

init();
