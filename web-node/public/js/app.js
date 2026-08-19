const PLANEJADORES = ["Admooh", "Adsmovil", "Magnite", "Hivestack", "Outcon"];
const TIPOS_VENDA = ["Agência", "SSP", "Direta"];
const TIPOS_DEAL = ["", "PG", "PD", "PMP"];
const PRAZO_OPCOES = [15, 30, 60, 70, 90];

const state = {
  spreadsheetKey: null,
  campaigns: [],
  campaignCache: {},
  campaignSnapshot: null,
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

function formatBRL(v) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(v || 0);
}

function generateDocumentId() {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let suffix = "";
  const arr = crypto.getRandomValues(new Uint8Array(4));
  for (let i = 0; i < 4; i++) suffix += chars[arr[i] % chars.length];
  const d = new Date();
  return `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, "0")}-${suffix}`;
}

function pdfTimestamp(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  const h = String(date.getHours()).padStart(2, "0");
  const min = String(date.getMinutes()).padStart(2, "0");
  const s = String(date.getSeconds()).padStart(2, "0");
  return `${y}${m}${d}_${h}${min}${s}`;
}

function removeAccents(text) {
  return String(text).normalize("NFD").replace(/[\u0300-\u036f]/g, "");
}

function sanitizeFilenamePart(text, fallback) {
  return removeAccents((text || fallback).trim()).replace(/[\\/:*?"<>|]/g, "_");
}

function buildPdfFilename(campanha, anunciante, date = new Date()) {
  const camp = sanitizeFilenamePart(campanha, "campanha");
  const adv = sanitizeFilenamePart(anunciante, "anunciante");
  return `${camp} - ${adv} - ${pdfTimestamp(date)}.pdf`;
}

const { withLoading } = AppLoading;
const { buildSummaryPayload } = OcFinancials;

async function fetchJson(url, options) {
  const res = await fetch(url, options);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "Erro na requisição");
  return data;
}

function getFormData() {
  const fd = new FormData();
  if (state.spreadsheetKey) fd.append("spreadsheetKey", state.spreadsheetKey);
  fd.append("adsId", el("campaign").value);
  fd.append("documentId", el("documentId").value || generateDocumentId());
  fd.append("documentTitle", el("documentTitle").value);
  fd.append("tipoVenda", el("tipoVenda").value);
  fd.append("tipoDeal", el("tipoDeal").value);
  fd.append("planejadorSsp", el("planejador").value);
  fd.append("dealId", el("dealId").value.trim());
  fd.append("ocInformeSsp", el("ocInforme").value.trim());
  fd.append("checkingFotografico", el("checking").checked);
  fd.append("relatoriosAdicionais", el("relatorios").checked);
  fd.append("prazoPagamento", el("prazoPagamento").value);
  fd.append("prazoUnidade", el("prazoUnidade").value);
  return fd;
}

function toggleConditionalFields() {
  const tipoVenda = el("tipoVenda").value;
  el("sspGroup").classList.toggle("hidden", tipoVenda !== "SSP");
  el("dealIdGroup").classList.toggle("hidden", !el("dealId").value.trim());
}

function populateCampaigns() {
  const select = el("campaign");
  select.innerHTML = "";
  for (const c of state.campaigns) {
    const opt = document.createElement("option");
    opt.value = c.ads_id;
    opt.textContent = `${c.campanha} — ${c.anunciante || "Sem anunciante"} (${c.ads_id})`;
    select.appendChild(opt);
  }
}

function formatPrazoPagamento(terminoIso, prazoDias, unidade) {
  if (!terminoIso) return "—";
  const [y, m, d] = terminoIso.split("-").map(Number);
  const termino = new Date(y, m - 1, d, 12, 0, 0);
  let payment;
  if (unidade === "DFM") {
    payment = new Date(y, m, 0, 12, 0, 0);
    payment.setDate(payment.getDate() + Number(prazoDias));
  } else {
    payment = new Date(termino);
    payment.setDate(payment.getDate() + Number(prazoDias));
  }
  const ds = String(payment.getDate()).padStart(2, "0");
  const ms = String(payment.getMonth() + 1).padStart(2, "0");
  return `Prazo para Pagamento: ${prazoDias} dias (${ds}/${ms}/${payment.getFullYear()})`;
}

function updatePrazoPreview(termino) {
  if (!el("prazoPreview")) return;
  el("prazoPreview").textContent = termino
    ? formatPrazoPagamento(termino, el("prazoPagamento").value, el("prazoUnidade").value)
    : "";
}

function updateSummaryFromClient(data) {
  const panel = el("summary");
  if (!data) {
    panel.innerHTML = "<p class='file-meta'>Carregue uma planilha para começar.</p>";
    return;
  }
  const { campaign, financials: fin } = data;
  const fmt = (iso) => {
    if (!iso) return "—";
    const [y, m, d] = iso.split("-");
    return `${d}/${m}/${y}`;
  };
  panel.innerHTML = `
    <dl>
      <dt>Campanha</dt><dd>${campaign.campanha}</dd>
      <dt>Anunciante</dt><dd>${campaign.anunciante}</dd>
      <dt>Período</dt><dd>${fmt(campaign.inicio)} — ${fmt(campaign.termino)}</dd>
      <dt>Lojas</dt><dd>${campaign.inventoryCount ?? campaign.inventory?.length ?? 0}</dd>
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
    </div>`;
  updatePrazoPreview(campaign.termino);
}

async function loadFees() {
  state.fees = await fetchJson("/maker/api/fees");
}

function refreshSummary() {
  if (!state.campaignSnapshot || !Object.keys(state.fees).length) return;
  updateSummaryFromClient(buildSummaryPayload(
    state.campaignSnapshot,
    el("tipoVenda").value,
    el("planejador").value,
    state.fees,
  ));
}

async function fetchCampaignSnapshot(adsId) {
  if (state.campaignCache[adsId]) return state.campaignCache[adsId];
  const fd = new FormData();
  fd.append("spreadsheetKey", state.spreadsheetKey);
  fd.append("adsId", adsId);
  const data = await fetchJson("/maker/api/campaign", { method: "POST", body: fd });
  state.campaignCache[adsId] = data.campaign;
  return data.campaign;
}

async function loadCampaignSnapshot() {
  const adsId = el("campaign").value;
  if (!adsId) return;
  await withLoading("Carregando dados da campanha…", async () => {
    state.campaignSnapshot = await fetchCampaignSnapshot(adsId);
    refreshSummary();
  });
}

async function handleFile(file) {
  hideError();
  if (!file.name.match(/\.xlsx$/i)) {
    showError("Selecione um arquivo .xlsx válido.");
    return;
  }
  try {
    await withLoading("Lendo planilha e carregando campanhas…", async () => {
      const fd = new FormData();
      fd.append("file", file);
      const data = await fetchJson("/maker/api/parse", { method: "POST", body: fd });
      state.campaigns = data.campaigns;
      state.spreadsheetKey = data.spreadsheetKey;
      state.campaignCache = {};
      el("fileMeta").textContent = `Arquivo: ${file.name}`;
      populateCampaigns();
      el("formSection").classList.remove("hidden");
      setStep(2);
      state.campaignSnapshot = await fetchCampaignSnapshot(el("campaign").value);
      refreshSummary();
      setStep(3);
      el("generateBtn").disabled = false;
    });
  } catch (err) {
    showError(err.message);
  }
}

function buildDownloadFilename() {
  const c = state.campaigns.find((x) => x.ads_id === el("campaign").value);
  return buildPdfFilename(c?.campanha, c?.anunciante);
}

async function onGenerate() {
  if (!state.spreadsheetKey) return;
  hideError();
  try {
    await withLoading("Gerando PDF…", async () => {
      const fd = getFormData();
      if (!el("documentId").value) {
        const id = generateDocumentId();
        el("documentId").value = id;
        fd.set("documentId", id);
      }
      const res = await fetch("/maker/api/generate", { method: "POST", body: fd });
      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error);
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = res.headers.get("Content-Disposition")?.match(/filename="(.+)"/)?.[1]
        || buildDownloadFilename();
      a.click();
      URL.revokeObjectURL(url);
      setStep(4);
    });
  } catch (err) {
    showError(err.message);
  }
}

function initSelects() {
  TIPOS_VENDA.forEach((t) => {
    const o = document.createElement("option");
    o.value = t;
    o.textContent = t;
    el("tipoVenda").appendChild(o);
  });
  el("tipoVenda").value = "SSP";
  PLANEJADORES.forEach((p) => {
    const o = document.createElement("option");
    o.value = p;
    o.textContent = p;
    el("planejador").appendChild(o);
  });
  TIPOS_DEAL.forEach((t) => {
    const o = document.createElement("option");
    o.value = t;
    o.textContent = t || "(nenhum)";
    el("tipoDeal").appendChild(o);
  });
}

function initDropzone() {
  const zone = el("dropzone");
  const input = el("fileInput");
  zone.addEventListener("click", () => input.click());
  zone.addEventListener("dragover", (e) => { e.preventDefault(); zone.classList.add("dragover"); });
  zone.addEventListener("dragleave", () => zone.classList.remove("dragover"));
  zone.addEventListener("drop", (e) => {
    e.preventDefault();
    zone.classList.remove("dragover");
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
  });
  input.addEventListener("change", () => { if (input.files[0]) handleFile(input.files[0]); });
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
  loadFees().catch(() => {});
  el("campaign").addEventListener("change", () => loadCampaignSnapshot().catch((e) => showError(e.message)));
  el("tipoVenda").addEventListener("change", () => { toggleConditionalFields(); refreshSummary(); });
  el("planejador").addEventListener("change", refreshSummary);
  el("tipoDeal").addEventListener("change", toggleConditionalFields);
  el("dealId").addEventListener("input", toggleConditionalFields);
  el("prazoPagamento").addEventListener("change", refreshSummary);
  el("prazoUnidade").addEventListener("change", refreshSummary);
  el("generateBtn").addEventListener("click", onGenerate);
  el("newIdBtn").addEventListener("click", () => { el("documentId").value = generateDocumentId(); });
  toggleConditionalFields();
  setStep(1);
}

init();
