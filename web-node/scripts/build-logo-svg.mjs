import { mkdirSync, readFileSync, writeFileSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const pngPath = join(root, "logo_converta.png");
const data = readFileSync(pngPath);
const width = data.readUInt32BE(16);
const height = data.readUInt32BE(20);
const b64 = data.toString("base64");

const svg =
  `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ` +
  `width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" ` +
  `role="img" aria-label="Converta Ads by Retail Media">` +
  `<title>Converta Ads by Retail Media</title>` +
  `<image width="${width}" height="${height}" preserveAspectRatio="xMidYMid meet" ` +
  `xlink:href="data:image/png;base64,${b64}"/></svg>`;

const targets = [
  join(root, "logo_converta.svg"),
  join(root, "web-js", "assets", "logo_converta.svg"),
  join(root, "web-node", "public", "assets", "logo_converta.svg"),
  join(root, "web-php", "public", "assets", "logo_converta.svg"),
];

for (const file of targets) {
  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(file, svg, "utf8");
  console.log("OK", file, `${width}x${height}`);
}
