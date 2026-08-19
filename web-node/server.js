import { randomBytes } from "crypto";
import express from "express";
import multer from "multer";
import { readFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, join } from "path";
import {
  buildFinancials,
  campaignTotals,
  generateDocumentId,
  listCampaigns,
  loadCampaign,
  readWorkbook,
  buildPdfFilename,
} from "./lib/core.js";
import { generatePdfBuffer } from "./lib/pdfGenerator.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const app = express();
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 25 * 1024 * 1024 },
  fileFilter: (_req, file, cb) => {
    if (!file.originalname.match(/\.xlsx$/i)) {
      return cb(new Error("Apenas arquivos .xlsx são aceitos."));
    }
    cb(null, true);
  },
});

const fees = JSON.parse(readFileSync(join(__dirname, "config/tech_fees.json"), "utf8"));
const workbookCache = new Map();
const PORT = process.env.PORT || 3000;

app.use(express.json());
app.use(express.static(join(__dirname, "public")));

app.get("/maker/api/health", (_req, res) => {
  res.json({ ok: true, service: "oc-maker-node" });
});

app.get("/maker/api/fees", (_req, res) => {
  res.json(fees);
});

app.post("/maker/api/parse", upload.single("file"), (req, res) => {
  try {
    if (!req.file) return res.status(400).json({ error: "Arquivo não enviado." });
    const workbook = readWorkbook(req.file.buffer);
    const spreadsheetKey = randomBytes(16).toString("hex");
    workbookCache.set(spreadsheetKey, workbook);
    const campaigns = listCampaigns(workbook);
    res.json({ campaigns, spreadsheetKey, fileName: req.file.originalname });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.post("/maker/api/campaign", upload.none(), (req, res) => {
  try {
    const { spreadsheetKey, adsId } = req.body;
    const workbook = workbookCache.get(spreadsheetKey);
    if (!workbook) {
      return res.status(400).json({ error: "Sessão da planilha expirou. Envie o arquivo novamente." });
    }
    const campaign = loadCampaign(workbook, adsId || null);
    const totals = campaignTotals(campaign);
    res.json({
      campaign: {
        ads_id: campaign.ads_id,
        campanha: campaign.campanha,
        anunciante: campaign.anunciante,
        agencia: campaign.agencia,
        inicio: campaign.inicio,
        termino: campaign.termino,
        inventoryCount: campaign.inventory.length,
        totals,
      },
    });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.post("/maker/api/generate", upload.single("file"), async (req, res) => {
  try {
    const body = req.body;
    let workbook = workbookCache.get(body.spreadsheetKey);
    if (!workbook) {
      if (!req.file) return res.status(400).json({ error: "Arquivo não enviado." });
      workbook = readWorkbook(req.file.buffer);
    }
    const campaign = loadCampaign(workbook, body.adsId || null);

    const documentId = body.documentId || generateDocumentId();
    const options = {
      documentId,
      documentTitle: body.documentTitle || "Informe de Campanha",
      tipoVenda: body.tipoVenda || "SSP",
      tipoDeal: body.tipoDeal || "",
      planejadorSsp: body.planejadorSsp || "Admooh",
      dealId: body.dealId || "",
      ocInformeSsp: body.ocInformeSsp || "",
      checkingFotografico: body.checkingFotografico === "true" || body.checkingFotografico === true,
      relatoriosAdicionais: body.relatoriosAdicionais === "true" || body.relatoriosAdicionais === true,
      prazoPagamento: Number(body.prazoPagamento || 15),
      prazoUnidade: body.prazoUnidade || "DFM",
    };

    const pdf = await generatePdfBuffer(campaign, options, fees);
    const filename = buildPdfFilename(campaign.campanha, campaign.anunciante);
    res.setHeader("Content-Type", "application/pdf");
    res.setHeader("Content-Disposition", `attachment; filename="${filename}"`);
    res.send(pdf);
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.use((err, _req, res, _next) => {
  res.status(400).json({ error: err.message || "Erro interno" });
});

app.listen(PORT, () => {
  console.log(`OC Maker (Node) em http://localhost:${PORT}`);
});
