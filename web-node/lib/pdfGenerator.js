import { createRequire } from "module";
import { readFileSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";
import PdfPrinter from "pdfmake";
import { buildPdfDefinition } from "./pdf.js";
import { loadLogoFromFile } from "./pdfLayout.js";

const require = createRequire(import.meta.url);
const __dirname = dirname(fileURLToPath(import.meta.url));

function loadFonts() {
  const fontsDir = join(__dirname, "../node_modules/pdfmake/build/fonts/Roboto");
  try {
    return {
      Roboto: {
        normal: readFileSync(join(fontsDir, "Roboto-Regular.ttf")),
        bold: readFileSync(join(fontsDir, "Roboto-Medium.ttf")),
        italics: readFileSync(join(fontsDir, "Roboto-Italic.ttf")),
        bolditalics: readFileSync(join(fontsDir, "Roboto-MediumItalic.ttf")),
      },
    };
  } catch {
    const vfs = require("pdfmake/build/vfs_fonts.js");
    return {
      Roboto: {
        normal: Buffer.from(vfs["Roboto-Regular.ttf"], "base64"),
        bold: Buffer.from(vfs["Roboto-Medium.ttf"], "base64"),
        italics: Buffer.from(vfs["Roboto-Italic.ttf"], "base64"),
        bolditalics: Buffer.from(vfs["Roboto-MediumItalic.ttf"], "base64"),
      },
    };
  }
}

const printer = new PdfPrinter(loadFonts());

export function generatePdfBuffer(campaign, options, fees) {
  const logo = loadLogoFromFile();
  const docDef = buildPdfDefinition(campaign, options, fees, logo);
  const pdfDoc = printer.createPdfKitDocument(docDef);
  return new Promise((resolve, reject) => {
    const chunks = [];
    pdfDoc.on("data", (chunk) => chunks.push(chunk));
    pdfDoc.on("end", () => resolve(Buffer.concat(chunks)));
    pdfDoc.on("error", reject);
    pdfDoc.end();
  });
}
