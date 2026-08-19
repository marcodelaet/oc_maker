"""Interface gráfica para geração de Informe de Campanha / Ordem de Compra."""

from __future__ import annotations

import tkinter as tk
from pathlib import Path
from tkinter import filedialog, messagebox, ttk

from document_id import build_pdf_filename, generate_document_id
from excel_reader import CampaignData, list_campaigns, load_campaign
from generator import OUTPUT_DIR, build_payload, generate_pdf as export_pdf

TIPOS_VENDA = ["Agência", "SSP", "Direta"]
TIPOS_DEAL = ["", "PG", "PD", "PMP"]
PLANEJADORES_SSP = ["", "Admooh", "Adsmovil", "Magnite", "Hivestack", "Outcon"]
DOCUMENT_TYPES = ["Informe de Campanha", "Ordem de Compra"]


class App(tk.Tk):
    def __init__(self) -> None:
        super().__init__()
        self.title("OC Maker — Informe / Ordem de Compra")
        self.geometry("760x620")
        self.campaign: CampaignData | None = None
        self.xlsx_path: Path | None = None
        self.campaign_map: dict[str, str] = {}
        self._build_ui()

    def _build_ui(self) -> None:
        pad = {"padx": 8, "pady": 4}
        frm = ttk.Frame(self, padding=12)
        frm.pack(fill="both", expand=True)

        row = 0
        ttk.Label(frm, text="Planilha Excel (.xlsx)").grid(row=row, column=0, sticky="w", **pad)
        self.xlsx_var = tk.StringVar()
        ttk.Entry(frm, textvariable=self.xlsx_var, width=70).grid(row=row, column=1, **pad)
        ttk.Button(frm, text="Procurar...", command=self.pick_xlsx).grid(row=row, column=2, **pad)

        row += 1
        ttk.Label(frm, text="Campanha").grid(row=row, column=0, sticky="w", **pad)
        self.campaign_var = tk.StringVar()
        self.campaign_combo = ttk.Combobox(frm, textvariable=self.campaign_var, width=67, state="readonly")
        self.campaign_combo.grid(row=row, column=1, columnspan=2, sticky="we", **pad)
        self.campaign_combo.bind("<<ComboboxSelected>>", lambda _e: self.load_selected_campaign())

        row += 1
        ttk.Label(frm, text="Tipo de documento").grid(row=row, column=0, sticky="w", **pad)
        self.doc_type_var = tk.StringVar(value=DOCUMENT_TYPES[0])
        ttk.Combobox(
            frm, textvariable=self.doc_type_var, values=DOCUMENT_TYPES, state="readonly", width=30
        ).grid(row=row, column=1, sticky="w", **pad)

        row += 1
        ttk.Label(frm, text="ID do documento").grid(row=row, column=0, sticky="w", **pad)
        self.doc_id_var = tk.StringVar(value=generate_document_id())
        ttk.Entry(frm, textvariable=self.doc_id_var, width=30).grid(row=row, column=1, sticky="w", **pad)
        ttk.Button(frm, text="Gerar novo ID", command=lambda: self.doc_id_var.set(generate_document_id())).grid(
            row=row, column=2, sticky="w", **pad
        )

        row += 1
        ttk.Separator(frm).grid(row=row, column=0, columnspan=3, sticky="ew", pady=8)

        row += 1
        ttk.Label(frm, text="Tipo de Venda").grid(row=row, column=0, sticky="w", **pad)
        self.tipo_venda_var = tk.StringVar(value="Agência")
        cb = ttk.Combobox(frm, textvariable=self.tipo_venda_var, values=TIPOS_VENDA, state="readonly", width=30)
        cb.grid(row=row, column=1, sticky="w", **pad)
        cb.bind("<<ComboboxSelected>>", lambda _e: self._sync_conditional_fields())

        row += 1
        ttk.Label(frm, text="Planejador / SSP").grid(row=row, column=0, sticky="w", **pad)
        self.planejador_var = tk.StringVar()
        self.planejador_combo = ttk.Combobox(
            frm, textvariable=self.planejador_var, values=PLANEJADORES_SSP, state="readonly", width=30
        )
        self.planejador_combo.grid(row=row, column=1, sticky="w", **pad)

        row += 1
        ttk.Label(frm, text="Tipo de DEAL").grid(row=row, column=0, sticky="w", **pad)
        self.tipo_deal_var = tk.StringVar()
        ttk.Combobox(frm, textvariable=self.tipo_deal_var, values=TIPOS_DEAL, state="readonly", width=30).grid(
            row=row, column=1, sticky="w", **pad
        )

        row += 1
        ttk.Label(frm, text="Deal ID").grid(row=row, column=0, sticky="w", **pad)
        self.deal_id_var = tk.StringVar()
        ttk.Entry(frm, textvariable=self.deal_id_var, width=40).grid(row=row, column=1, sticky="w", **pad)

        row += 1
        ttk.Label(frm, text="OC/Informe (SSP)").grid(row=row, column=0, sticky="w", **pad)
        self.oc_ssp_var = tk.StringVar()
        self.oc_ssp_entry = ttk.Entry(frm, textvariable=self.oc_ssp_var, width=40)
        self.oc_ssp_entry.grid(row=row, column=1, sticky="w", **pad)

        row += 1
        self.checking_var = tk.BooleanVar(value=True)
        ttk.Checkbutton(frm, text="Checking Fotográfico", variable=self.checking_var).grid(
            row=row, column=1, sticky="w", **pad
        )

        row += 1
        self.relatorios_var = tk.BooleanVar(value=False)
        ttk.Checkbutton(frm, text="Relatórios Adicionais", variable=self.relatorios_var).grid(
            row=row, column=1, sticky="w", **pad
        )

        row += 1
        ttk.Label(frm, text="Data de Emissão da NF").grid(row=row, column=0, sticky="w", **pad)
        self.nf_var = tk.StringVar()
        ttk.Entry(frm, textvariable=self.nf_var, width=20).grid(row=row, column=1, sticky="w", **pad)

        row += 1
        ttk.Label(frm, text="Prazo para Pagamento").grid(row=row, column=0, sticky="w", **pad)
        self.prazo_var = tk.StringVar(value="15dfm")
        ttk.Entry(frm, textvariable=self.prazo_var, width=20).grid(row=row, column=1, sticky="w", **pad)

        row += 1
        ttk.Separator(frm).grid(row=row, column=0, columnspan=3, sticky="ew", pady=8)

        row += 1
        self.summary = tk.Text(frm, height=12, width=90, state="disabled")
        self.summary.grid(row=row, column=0, columnspan=3, sticky="nsew", **pad)

        row += 1
        ttk.Button(frm, text="Gerar PDF", command=self.generate_pdf).grid(row=row, column=1, pady=12)

        frm.columnconfigure(1, weight=1)
        frm.rowconfigure(row - 1, weight=1)
        self._sync_conditional_fields()

    def pick_xlsx(self) -> None:
        path = filedialog.askopenfilename(filetypes=[("Excel", "*.xlsx")])
        if not path:
            return
        self.xlsx_path = Path(path)
        self.xlsx_var.set(str(self.xlsx_path))
        campaigns = list_campaigns(self.xlsx_path)
        labels = [f"{c['campanha']} — {c['anunciante']} ({c['ads_id']})" for c in campaigns]
        self.campaign_map = {label: c["ads_id"] for label, c in zip(labels, campaigns)}
        self.campaign_combo["values"] = labels
        if labels:
            self.campaign_combo.current(0)
            self.load_selected_campaign()

    def load_selected_campaign(self) -> None:
        if not self.xlsx_path:
            return
        ads_id = self.campaign_map.get(self.campaign_var.get())
        self.campaign = load_campaign(self.xlsx_path, ads_id)
        self._update_summary()

    def _update_summary(self) -> None:
        if not self.campaign:
            return
        c = self.campaign
        t = c.totals
        lines = [
            f"Anunciante: {c.anunciante}",
            f"Agência: {c.agencia}",
            f"Campanha: {c.campanha}",
            f"Planejador (planilha): {c.planejador}",
            f"Período: {c.inicio} — {c.termino}",
            f"Lojas na tabela: {len(c.inventory)}",
            f"Total Inserções: {t['insercoes']:,.0f}",
            f"Total Impactos: {t['impactos']:,.0f}",
            f"Budget Media Cost: R$ {t['bruto']:,.2f}",
            f"Budget Publisher: R$ {t['liquido']:,.2f}",
            f"VALOR LÍQUIDO (SSP): R$ {c.valor_liquido_ssp:,.2f}",
            f"CPM MÉDIO: R$ {c.cpm_medio:,.2f}",
            f"Observações: {len(c.observacoes)} linha(s)",
        ]
        self.summary.configure(state="normal")
        self.summary.delete("1.0", "end")
        self.summary.insert("1.0", "\n".join(lines))
        self.summary.configure(state="disabled")

    def _sync_conditional_fields(self) -> None:
        is_ssp = self.tipo_venda_var.get() == "SSP"
        self.oc_ssp_entry.configure(state="normal" if is_ssp else "disabled")
        if not is_ssp:
            self.oc_ssp_var.set("")

    def generate_pdf(self) -> None:
        if not self.xlsx_path or not self.campaign:
            messagebox.showerror("Erro", "Selecione uma planilha e uma campanha.")
            return

        payload = build_payload(
            self.campaign,
            document_id=self.doc_id_var.get().strip(),
            document_title=self.doc_type_var.get(),
            tipo_venda=self.tipo_venda_var.get(),
            tipo_deal=self.tipo_deal_var.get().strip(),
            planejador_ssp=self.planejador_var.get().strip(),
            deal_id=self.deal_id_var.get().strip(),
            oc_informe_ssp=self.oc_ssp_var.get().strip(),
            checking_fotografico=self.checking_var.get(),
            relatorios_adicionais=self.relatorios_var.get(),
            data_emissao_nf=self.nf_var.get().strip(),
            prazo_pagamento=self.prazo_var.get().strip(),
        )

        pdf_path = OUTPUT_DIR / build_pdf_filename(self.campaign.campanha, self.campaign.anunciante)

        try:
            export_pdf(payload, pdf_path)
            messagebox.showinfo("Sucesso", f"PDF gerado em:\n{pdf_path}")
        except Exception as exc:
            messagebox.showerror("Erro ao gerar PDF", str(exc))


def main() -> None:
    app = App()
    app.mainloop()


if __name__ == "__main__":
    main()
