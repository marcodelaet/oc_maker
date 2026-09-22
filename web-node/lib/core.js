import { randomBytes } from "crypto";
import * as XLSX from "xlsx";

export const COL = {
  codigo: "B",
  rede: "J",
  impactos: "O",
  insercoes: "P",
  bruto: "U",
  liquido: "V",
  ads_id: "AK",
  agencia: "AL",
  anunciante: "AM",
  planejador: "AN",
  campanha: "AO",
  inicio: "AQ",
  termino: "AR",
};

export const PLANEJADORES = ["Admooh", "Adsmovil", "Magnite", "Hivestack", "Outcon"];
export const TIPOS_VENDA = ["Agência", "SSP", "Direta"];
export const TIPOS_DEAL = ["", "PG", "PD", "PMP"];

function cellRef(row, col) {
  return col + row;
}

function asText(value) {
  if (value == null) return "";
  return String(value).trim();
}

function asFloat(value) {
  if (value === "" || value == null) return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function excelDate(serial) {
  if (serial == null || serial === "") return null;
  if (typeof serial === "string") return parseIsoDate(serial);
  const n = Number(serial);
  if (!Number.isFinite(n) || n <= 0) return null;
  const utc = (n - 25569) * 86400 * 1000;
  return new Date(utc).toISOString().slice(0, 10);
}

function parseIsoDate(text) {
  if (!text) return null;
  text = text.trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
  const m = text.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (m) return `${m[3]}-${m[2]}-${m[1]}`;
  return null;
}

export function formatDateBR(iso) {
  if (!iso) return "";
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
}

function getCell(sheet, row, col) {
  const ref = cellRef(row, col);
  const cell = sheet[ref];
  if (!cell) return "";
  return cell.v;
}

function rowsWithValues(sheet, col) {
  const re = new RegExp("^" + col + "(\\d+)$", "i");
  const rows = new Set();
  for (const ref of Object.keys(sheet)) {
    const m = ref.match(re);
    if (m && sheet[ref].v != null && sheet[ref].v !== "") {
      rows.add(Number(m[1]));
    }
  }
  return [...rows].sort((a, b) => a - b);
}

function isHeaderMetadata(adsId, campanha) {
  const set = new Set([adsId.trim().toLowerCase(), campanha.trim().toLowerCase()]);
  return ["adsid", "campanha", "anunciante", "agência", "agencia"].some((h) => set.has(h));
}

export function generateDocumentId(date = new Date()) {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let suffix = "";
  const arr = randomBytes(4);
  for (let i = 0; i < 4; i++) suffix += chars[arr[i] % chars.length];
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  return `${y}${m}-${suffix}`;
}

export function pdfTimestamp(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  const h = String(date.getHours()).padStart(2, "0");
  const min = String(date.getMinutes()).padStart(2, "0");
  const s = String(date.getSeconds()).padStart(2, "0");
  return `${y}${m}${d}_${h}${min}${s}`;
}

export function removeAccents(text) {
  return String(text)
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");
}

function sanitizeFilenamePart(text, fallback) {
  return removeAccents((text || fallback).trim()).replace(/[\\/:*?"<>|]/g, "_");
}

export function buildPdfFilename(campanha, anunciante, date = new Date()) {
  const camp = sanitizeFilenamePart(campanha, "campanha");
  const adv = sanitizeFilenamePart(anunciante, "anunciante");
  return `${camp} - ${adv} - ${pdfTimestamp(date)}.pdf`;
}

export function formatBRL(value) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value || 0);
}

export function formatNumber(value) {
  return new Intl.NumberFormat("pt-BR").format(value || 0);
}

export function campaignTotals(campaign) {
  const t = { insercoes: 0, impactos: 0, bruto: 0, liquido: 0 };
  for (const row of campaign.inventory) {
    t.insercoes += row.insercoes;
    t.impactos += row.impactos;
    t.bruto += row.bruto;
    t.liquido += row.liquido;
  }
  return t;
}

export function cpmMedio(liquido, impactos) {
  if (!impactos) return 0;
  return (liquido / impactos) * 1000;
}

export function techFeePercent(tipoVenda, planejador, fees) {
  const group = fees[tipoVenda] || {};
  if (planejador && group[planejador] != null) return Number(group[planejador]);
  return Number(group.default ?? 0);
}

export function buildFinancials(campaign, tipoVenda, planejadorSsp, fees) {
  const totals = campaignTotals(campaign);
  const valorSsp = totals.liquido;
  const feePercent = techFeePercent(tipoVenda, planejadorSsp, fees);
  const feeValue = valorSsp * (feePercent / 100);
  const valorPublisher = valorSsp - feeValue;
  const cpm = cpmMedio(valorSsp, totals.impactos);
  return { totals, valorSsp, feePercent, feeValue, valorPublisher, cpm };
}

export function readWorkbook(buffer) {
  return XLSX.read(buffer, { type: "buffer", cellDates: false });
}

function findMetadataRow(invSheet, adsId) {
  const candidates = [];
  for (const row of rowsWithValues(invSheet, COL.campanha)) {
    const rowAds = asText(getCell(invSheet, row, COL.ads_id));
    const campanha = asText(getCell(invSheet, row, COL.campanha));
    if (campanha && rowAds && !isHeaderMetadata(rowAds, campanha)) {
      candidates.push({ row, rowAds });
    }
  }
  if (!candidates.length) throw new Error("Não foi encontrada linha de metadados na aba INVENTARIO.");
  if (adsId) {
    const hit = candidates.find((c) => c.rowAds === adsId);
    if (!hit) throw new Error(`AdsID '${adsId}' não encontrado na planilha.`);
    return hit.row;
  }
  let bestRow = candidates[0].row;
  let bestCount = -1;
  for (const { row, rowAds } of candidates) {
    let count = 0;
    let currentAds = rowAds;
    for (const dataRow of rowsWithValues(invSheet, COL.codigo)) {
      if (dataRow < row) continue;
      const nextAds = asText(getCell(invSheet, dataRow, COL.ads_id));
      if (nextAds) {
        if (dataRow > row && nextAds !== rowAds) break;
        currentAds = nextAds;
      }
      if (currentAds !== rowAds) continue;
      if (asText(getCell(invSheet, dataRow, COL.rede))) count++;
    }
    if (count > bestCount) {
      bestCount = count;
      bestRow = row;
    }
  }
  return bestRow;
}

export function loadCampaign(workbook, adsId = null) {
  const inv = workbook.Sheets["INVENTARIO"];
  if (!inv) throw new Error("Aba INVENTARIO não encontrada.");
  const metadataRow = findMetadataRow(inv, adsId);
  const campaignAdsId = asText(getCell(inv, metadataRow, COL.ads_id));

  const data = {
    ads_id: campaignAdsId,
    agencia: asText(getCell(inv, metadataRow, COL.agencia)),
    anunciante: asText(getCell(inv, metadataRow, COL.anunciante)),
    planejador: asText(getCell(inv, metadataRow, COL.planejador)),
    campanha: asText(getCell(inv, metadataRow, COL.campanha)),
    inicio: parseIsoDate(asText(getCell(inv, metadataRow, COL.inicio))) || excelDate(getCell(inv, metadataRow, COL.inicio)),
    termino: parseIsoDate(asText(getCell(inv, metadataRow, COL.termino))) || excelDate(getCell(inv, metadataRow, COL.termino)),
    inventory: [],
    observacoes: [],
  };

  let currentAds = campaignAdsId;
  for (const row of rowsWithValues(inv, COL.codigo)) {
    if (row < metadataRow) continue;
    const rowAds = asText(getCell(inv, row, COL.ads_id));
    if (rowAds) {
      if (row > metadataRow && rowAds !== campaignAdsId) break;
      currentAds = rowAds;
    } else if (row > metadataRow && currentAds !== campaignAdsId) {
      continue;
    }
    const codigo = asText(getCell(inv, row, COL.codigo));
    const rede = asText(getCell(inv, row, COL.rede));
    if (!codigo || !rede) continue;
    data.inventory.push({
      codigo,
      rede,
      insercoes: asFloat(getCell(inv, row, COL.insercoes)),
      impactos: asFloat(getCell(inv, row, COL.impactos)),
      bruto: asFloat(getCell(inv, row, COL.bruto)),
      liquido: asFloat(getCell(inv, row, COL.liquido)),
    });
  }

  const obsSheet = workbook.Sheets["OBSERVAÇÕES"] || workbook.Sheets["OBSERVACOES"];
  if (obsSheet) {
    for (const row of rowsWithValues(obsSheet, "A")) {
      if (row === 1) continue;
      const text = asText(getCell(obsSheet, row, "A"));
      if (text) data.observacoes.push(text);
    }
  }
  return data;
}

export function listCampaigns(workbook) {
  const inv = workbook.Sheets["INVENTARIO"];
  if (!inv) return [];
  const campaigns = [];
  for (const row of rowsWithValues(inv, COL.campanha)) {
    const ads_id = asText(getCell(inv, row, COL.ads_id));
    const campanha = asText(getCell(inv, row, COL.campanha));
    if (ads_id && campanha && !isHeaderMetadata(ads_id, campanha)) {
      campaigns.push({
        ads_id,
        campanha,
        anunciante: asText(getCell(inv, row, COL.anunciante)),
        agencia: asText(getCell(inv, row, COL.agencia)),
        row: String(row),
      });
    }
  }
  return campaigns;
}
