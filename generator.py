"""Montagem de payload e geração de PDF."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path

from document_id import generate_document_id
from excel_reader import CampaignData, load_campaign

ROOT = Path(__file__).resolve().parent
TEMPLATE = next(ROOT.glob("*macros.docm"), ROOT / "template.docm")
TECH_FEES_PATH = ROOT / "tech_fees.json"
OUTPUT_DIR = ROOT / "output"
POWERSHELL = ROOT / "generate_document.ps1"


def load_tech_fees() -> dict:
    if TECH_FEES_PATH.exists():
        return json.loads(TECH_FEES_PATH.read_text(encoding="utf-8"))
    return {}


def tech_fee_percent(tipo_venda: str, planejador: str, fees: dict) -> float:
    group = fees.get(tipo_venda, {})
    if planejador and planejador in group:
        return float(group[planejador])
    return float(group.get("default", 0))


def build_payload(
    campaign: CampaignData,
    *,
    document_id: str,
    document_title: str,
    tipo_venda: str,
    tipo_deal: str,
    planejador_ssp: str,
    deal_id: str,
    oc_informe_ssp: str,
    checking_fotografico: bool,
    relatorios_adicionais: bool,
    data_emissao_nf: str,
    prazo_pagamento: str,
) -> dict:
    fees = load_tech_fees()
    fee_percent = tech_fee_percent(tipo_venda, planejador_ssp, fees)
    valor_ssp = campaign.valor_liquido_ssp
    fee_value = valor_ssp * (fee_percent / 100.0)
    valor_publisher = valor_ssp - fee_value

    return {
        "document_id": document_id,
        "document_title": document_title,
        "anunciante": campaign.anunciante,
        "campanha": campaign.campanha,
        "inicio": campaign.inicio.isoformat() if campaign.inicio else "",
        "termino": campaign.termino.isoformat() if campaign.termino else "",
        "tipo_venda": tipo_venda,
        "tipo_deal": tipo_deal,
        "planejador_ssp": planejador_ssp,
        "deal_id": deal_id,
        "oc_informe_ssp": oc_informe_ssp,
        "checking_fotografico": checking_fotografico,
        "relatorios_adicionais": relatorios_adicionais,
        "data_emissao_nf": data_emissao_nf,
        "prazo_pagamento": prazo_pagamento,
        "inventory": [
            {
                "codigo": row.codigo,
                "rede": row.rede,
                "insercoes": row.insercoes,
                "impactos": row.impactos,
                "bruto": row.bruto,
                "liquido": row.liquido,
            }
            for row in campaign.inventory
        ],
        "totals": campaign.totals,
        "valor_liquido_ssp": valor_ssp,
        "tech_fee_percent": fee_percent,
        "tech_fee_value": fee_value,
        "valor_liquido_publisher": valor_publisher,
        "cpm_medio": campaign.cpm_medio,
        "observacoes": campaign.observacoes,
    }


def generate_pdf(payload: dict, output_pdf: Path) -> None:
    output_pdf.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".json", delete=False) as tmp:
        json.dump(payload, tmp, ensure_ascii=False, indent=2)
        payload_path = tmp.name

    cmd = [
        "powershell",
        "-NoProfile",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        str(POWERSHELL),
        "-PayloadPath",
        payload_path,
        "-TemplatePath",
        str(TEMPLATE.resolve()),
        "-OutputPdfPath",
        str(output_pdf.resolve()),
    ]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
        if result.returncode != 0:
            raise RuntimeError(result.stderr or result.stdout or "Falha desconhecida ao gerar PDF.")
    finally:
        Path(payload_path).unlink(missing_ok=True)
