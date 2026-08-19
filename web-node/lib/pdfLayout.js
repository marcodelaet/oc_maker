import { readFileSync, existsSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";

export {
  COMPANY,
  PDF_COLORS,
  PRAZO_OPCOES,
  PRAZO_UNIDADES,
  parseIsoDate,
  formatDateBRFromDate,
  calcPaymentDate,
  formatPrazoPagamento,
  checkboxCanvas,
  formatObservacoes,
  buildMetaTwoColumns,
  buildHeaderBlock,
  FOOTER_NOTE,
  logoBase64Sync,
} from "/maker/assets/js/pdfLayout.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

export function loadLogoFromFile() {
  const png = join(__dirname, "/maker/assets/logo_converta.png");
  const svg = join(__dirname, "/maker/assets/logo_converta.svg");
  const file = existsSync(png) ? png : svg;
  try {
    const data = readFileSync(file);
    const mime = file.endsWith(".svg") ? "image/svg+xml" : "image/png";
    return `data:${mime};base64,${data.toString("base64")}`;
  } catch {
    return null;
  }
}
