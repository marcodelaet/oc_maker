"""Linha de comando para gerar PDF sem interface gráfica."""

from __future__ import annotations

import argparse
from pathlib import Path

from document_id import build_pdf_filename, generate_document_id
from excel_reader import load_campaign
from generator import OUTPUT_DIR, build_payload, generate_pdf


def main() -> None:
    parser = argparse.ArgumentParser(description="Gera Informe de Campanha / Ordem de Compra em PDF")
    parser.add_argument("xlsx", type=Path, help="Caminho da planilha .xlsx")
    parser.add_argument("--ads-id", help="AdsID da campanha na aba INVENTARIO")
    parser.add_argument("--document-type", default="Informe de Campanha", choices=["Informe de Campanha", "Ordem de Compra"])
    parser.add_argument("--document-id", default=generate_document_id())
    parser.add_argument("--tipo-venda", default="Agência", choices=["Agência", "SSP", "Direta"])
    parser.add_argument("--planejador", default="", help="Planejador / SSP")
    parser.add_argument("--tipo-deal", default="", choices=["", "PG", "PD", "PMP"])
    parser.add_argument("--deal-id", default="")
    parser.add_argument("--oc-ssp", default="")
    parser.add_argument("--checking", action="store_true", default=True)
    parser.add_argument("--no-checking", action="store_false", dest="checking")
    parser.add_argument("--relatorios", action="store_true", default=False)
    parser.add_argument("--output", type=Path, help="Caminho do PDF de saída")
    args = parser.parse_args()

    campaign = load_campaign(args.xlsx, args.ads_id)
    payload = build_payload(
        campaign,
        document_id=args.document_id,
        document_title=args.document_type,
        tipo_venda=args.tipo_venda,
        tipo_deal=args.tipo_deal,
        planejador_ssp=args.planejador,
        deal_id=args.deal_id,
        oc_informe_ssp=args.oc_ssp,
        checking_fotografico=args.checking,
        relatorios_adicionais=args.relatorios,
        data_emissao_nf="",
        prazo_pagamento="15dfm",
    )

    if args.output:
        pdf_path = args.output
    else:
        pdf_path = OUTPUT_DIR / build_pdf_filename(campaign.campanha, campaign.anunciante)

    generate_pdf(payload, pdf_path)
    print(pdf_path)


if __name__ == "__main__":
    main()
