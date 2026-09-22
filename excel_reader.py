"""Leitura de planilhas .xlsx usando apenas biblioteca padrão."""

from __future__ import annotations

import datetime as dt
import re
import zipfile
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
COL = {
    "codigo": "B",
    "rede": "J",
    "impactos": "O",
    "insercoes": "P",
    "bruto": "U",
    "liquido": "V",
    "ads_id": "AK",
    "agencia": "AL",
    "anunciante": "AM",
    "planejador": "AN",
    "campanha": "AO",
    "inicio": "AQ",
    "termino": "AR",
}


def _col_index(col: str) -> int:
    value = 0
    for ch in col.upper():
        value = value * 26 + (ord(ch) - ord("A") + 1)
    return value


def _cell_ref(row: int, col: str) -> str:
    return f"{col.upper()}{row}"


def _excel_date(value: float | int | None) -> dt.date | None:
    if value in (None, ""):
        return None
    try:
        serial = int(float(value))
    except (TypeError, ValueError):
        return None
    if serial <= 0:
        return None
    return dt.date(1899, 12, 30) + dt.timedelta(days=serial)


def _parse_iso_date(text: str | None) -> dt.date | None:
    if not text:
        return None
    text = text.strip()
    for fmt in ("%Y-%m-%d", "%d/%m/%Y"):
        try:
            return dt.datetime.strptime(text, fmt).date()
        except ValueError:
            continue
    return None


@dataclass
class InventoryRow:
    codigo: str
    rede: str
    insercoes: float
    impactos: float
    bruto: float
    liquido: float


@dataclass
class CampaignData:
    ads_id: str = ""
    agencia: str = ""
    anunciante: str = ""
    planejador: str = ""
    campanha: str = ""
    inicio: dt.date | None = None
    termino: dt.date | None = None
    inventory: list[InventoryRow] = field(default_factory=list)
    observacoes: list[str] = field(default_factory=list)

    @property
    def totals(self) -> dict[str, float]:
        return {
            "insercoes": sum(r.insercoes for r in self.inventory),
            "impactos": sum(r.impactos for r in self.inventory),
            "bruto": sum(r.bruto for r in self.inventory),
            "liquido": sum(r.liquido for r in self.inventory),
        }

    @property
    def valor_liquido_ssp(self) -> float:
        return self.totals["liquido"]

    @property
    def cpm_medio(self) -> float:
        impactos = self.totals["impactos"]
        if not impactos:
            return 0.0
        return (self.valor_liquido_ssp / impactos) * 1000


class XlsxReader:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.shared_strings: list[str] = []
        self.sheets: dict[str, str] = {}
        self._load_workbook()

    def _load_workbook(self) -> None:
        with zipfile.ZipFile(self.path) as zf:
            shared_path = "xl/sharedStrings.xml"
            if shared_path in zf.namelist():
                root = ET.fromstring(zf.read(shared_path))
                for si in root.findall("m:si", NS):
                    parts = [node.text or "" for node in si.findall(".//m:t", NS)]
                    self.shared_strings.append("".join(parts))

            workbook = ET.fromstring(zf.read("xl/workbook.xml"))
            rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
            rel_map = {
                rel.attrib["Id"]: rel.attrib["Target"]
                for rel in rels
                if rel.attrib.get("Type", "").endswith("/worksheet")
            }
            for sheet in workbook.findall("m:sheets/m:sheet", NS):
                name = sheet.attrib["name"]
                rel_id = sheet.attrib[
                    "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id"
                ]
                target = rel_map[rel_id].lstrip("/")
                if not target.startswith("xl/"):
                    target = f"xl/{target}"
                self.sheets[name] = target

            self._cache: dict[str, dict[str, Any]] = {}
            for sheet_name, sheet_path in self.sheets.items():
                self._cache[sheet_name] = self._parse_sheet(zf.read(sheet_path))

    def _parse_sheet(self, xml_bytes: bytes) -> dict[str, Any]:
        root = ET.fromstring(xml_bytes)
        cells: dict[str, Any] = {}
        for row in root.findall("m:sheetData/m:row", NS):
            for cell in row.findall("m:c", NS):
                ref = cell.attrib.get("r")
                if not ref:
                    continue
                cells[ref] = self._parse_cell(cell)
        return cells

    def _parse_cell(self, cell: ET.Element) -> Any:
        cell_type = cell.attrib.get("t")
        value_node = cell.find("m:v", NS)
        if cell_type == "s" and value_node is not None and value_node.text is not None:
            idx = int(value_node.text)
            return self.shared_strings[idx]
        if cell_type == "str":
            inline = cell.find("m:is/m:t", NS)
            if inline is not None and inline.text is not None:
                return inline.text
        if cell_type == "inlineStr":
            inline = cell.find("m:is/m:t", NS)
            if inline is not None and inline.text is not None:
                return inline.text
        formula = cell.find("m:f", NS)
        if formula is not None and formula.text:
            # Prefer cached value when present.
            if value_node is not None and value_node.text is not None:
                return self._coerce_number(value_node.text)
        if value_node is None or value_node.text is None:
            return ""
        return self._coerce_number(value_node.text)

    @staticmethod
    def _coerce_number(text: str) -> Any:
        if text == "":
            return ""
        try:
            if re.fullmatch(r"-?\d+", text):
                return int(text)
            return float(text)
        except ValueError:
            return text

    def cell(self, sheet: str, row: int, col: str) -> Any:
        return self._cache[sheet].get(_cell_ref(row, col), "")

    def sheet_rows_with_values_in_col(self, sheet: str, col: str) -> list[int]:
        rows: set[int] = set()
        pattern = re.compile(rf"^{col}(\d+)$", re.I)
        for ref in self._cache[sheet]:
            match = pattern.match(ref)
            if match and self._cache[sheet][ref] not in ("", None):
                rows.add(int(match.group(1)))
        return sorted(rows)


def _as_float(value: Any) -> float:
    if value in ("", None):
        return 0.0
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def _as_text(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def load_campaign(xlsx_path: Path, ads_id: str | None = None) -> CampaignData:
    reader = XlsxReader(xlsx_path)
    inv = "INVENTARIO"
    obs = "OBSERVAÇÕES"

    metadata_row = _find_metadata_row(reader, ads_id)
    campaign_ads_id = _as_text(reader.cell(inv, metadata_row, COL["ads_id"]))
    data = CampaignData(
        ads_id=campaign_ads_id,
        agencia=_as_text(reader.cell(inv, metadata_row, COL["agencia"])),
        anunciante=_as_text(reader.cell(inv, metadata_row, COL["anunciante"])),
        planejador=_as_text(reader.cell(inv, metadata_row, COL["planejador"])),
        campanha=_as_text(reader.cell(inv, metadata_row, COL["campanha"])),
        inicio=_parse_iso_date(_as_text(reader.cell(inv, metadata_row, COL["inicio"])))
        or _excel_date(reader.cell(inv, metadata_row, COL["inicio"])),
        termino=_parse_iso_date(_as_text(reader.cell(inv, metadata_row, COL["termino"])))
        or _excel_date(reader.cell(inv, metadata_row, COL["termino"])),
    )

    last_rede = ""
    row_markers = set(reader.sheet_rows_with_values_in_col(inv, COL["codigo"]))
    row_markers.update(reader.sheet_rows_with_values_in_col(inv, COL["rede"]))
    row_markers.add(metadata_row)
    highest_row = max(row_markers)
    for row in range(metadata_row, highest_row + 1):
        if _is_next_campaign_metadata_row(reader, inv, row, metadata_row, campaign_ads_id):
            break

        codigo = _as_text(reader.cell(inv, row, COL["codigo"]))
        rede = _as_text(reader.cell(inv, row, COL["rede"]))

        if not codigo and rede:
            if not _is_group_header_rede(rede) and not _is_column_header_label(rede):
                last_rede = rede
            continue

        if not codigo or _is_inventory_summary_row(codigo) or _is_column_header_label(codigo):
            continue

        effective_rede = rede or last_rede
        if not effective_rede or _is_group_header_rede(effective_rede) or _is_column_header_label(effective_rede):
            continue

        if rede:
            last_rede = rede

        data.inventory.append(
            InventoryRow(
                codigo=codigo,
                rede=effective_rede,
                insercoes=_as_float(reader.cell(inv, row, COL["insercoes"])),
                impactos=_as_float(reader.cell(inv, row, COL["impactos"])),
                bruto=_as_float(reader.cell(inv, row, COL["bruto"])),
                liquido=_as_float(reader.cell(inv, row, COL["liquido"])),
            )
        )

    if obs in reader._cache:
        for row in reader.sheet_rows_with_values_in_col(obs, "A"):
            if row == 1:
                continue
            text = _as_text(reader.cell(obs, row, "A"))
            if text:
                data.observacoes.append(text)

    return data


def _is_header_metadata(row_ads: str, campanha: str) -> bool:
    normalized = {row_ads.strip().lower(), campanha.strip().lower()}
    return normalized & {"adsid", "campanha", "anunciante", "agência", "agencia"}


def _is_group_header_rede(rede: str) -> bool:
    normalized = rede.strip().upper()
    return (
        normalized.startswith("PRAÇA ")
        or normalized.startswith("PRACA ")
        or normalized in {"TOTAL", "TOTAIS"}
    )


def _is_inventory_summary_row(codigo: str) -> bool:
    return codigo.strip().upper() in {"TOTAL", "TOTAIS", "SUBTOTAL", "SUB-TOTAL"}


def _is_column_header_label(value: str) -> bool:
    return value.strip().lower() in {
        "código",
        "codigo",
        "rede",
        "veículo",
        "veiculo",
        "denominação",
        "denominacao",
    }


def _is_campaign_metadata_row(reader: XlsxReader, sheet: str, row: int) -> bool:
    row_ads = _as_text(reader.cell(sheet, row, COL["ads_id"]))
    campanha = _as_text(reader.cell(sheet, row, COL["campanha"]))
    return bool(row_ads and campanha and not _is_header_metadata(row_ads, campanha))


def _is_next_campaign_metadata_row(
    reader: XlsxReader,
    sheet: str,
    row: int,
    metadata_row: int,
    campaign_ads_id: str,
) -> bool:
    if row <= metadata_row or not _is_campaign_metadata_row(reader, sheet, row):
        return False
    return _as_text(reader.cell(sheet, row, COL["ads_id"])) != campaign_ads_id


def _find_metadata_row(reader: XlsxReader, ads_id: str | None) -> int:
    candidates: list[tuple[int, str]] = []
    for row in reader.sheet_rows_with_values_in_col("INVENTARIO", COL["campanha"]):
        row_ads = _as_text(reader.cell("INVENTARIO", row, COL["ads_id"]))
        campanha = _as_text(reader.cell("INVENTARIO", row, COL["campanha"]))
        if campanha and row_ads and not _is_header_metadata(row_ads, campanha):
            candidates.append((row, row_ads))
    if not candidates:
        raise ValueError("Não foi encontrada linha de metadados da campanha na aba INVENTARIO.")
    if ads_id:
        for row, row_ads in candidates:
            if row_ads == ads_id:
                return row
        raise ValueError(f"AdsID '{ads_id}' não encontrado na planilha.")

    best_row = candidates[0][0]
    best_count = -1
    for row, row_ads in candidates:
        count = 0
        current_ads = row_ads
        for data_row in reader.sheet_rows_with_values_in_col("INVENTARIO", COL["codigo"]):
            if data_row < row:
                continue
            next_ads = _as_text(reader.cell("INVENTARIO", data_row, COL["ads_id"]))
            if next_ads:
                if data_row > row and next_ads != row_ads:
                    break
                current_ads = next_ads
            if current_ads != row_ads:
                continue
            if _as_text(reader.cell("INVENTARIO", data_row, COL["rede"])):
                count += 1
        if count > best_count:
            best_count = count
            best_row = row
    return best_row


def list_campaigns(xlsx_path: Path) -> list[dict[str, str]]:
    reader = XlsxReader(xlsx_path)
    campaigns: list[dict[str, str]] = []
    for row in reader.sheet_rows_with_values_in_col("INVENTARIO", COL["campanha"]):
        ads_id = _as_text(reader.cell("INVENTARIO", row, COL["ads_id"]))
        campanha = _as_text(reader.cell("INVENTARIO", row, COL["campanha"]))
        if ads_id and campanha and not _is_header_metadata(ads_id, campanha):
            campaigns.append(
                {
                    "ads_id": ads_id,
                    "campanha": campanha,
                    "anunciante": _as_text(reader.cell("INVENTARIO", row, COL["anunciante"])),
                    "agencia": _as_text(reader.cell("INVENTARIO", row, COL["agencia"])),
                    "row": str(row),
                }
            )
    return campaigns
