<<<<<<< HEAD
# OC Maker

Gerador de **Informe de Campanha** / **Ordem de Compra** em PDF a partir de planilhas Excel (.xlsx), usando o template Word com macros como base visual.

## Requisitos

- Windows com **Microsoft Word** instalado
- Python 3.10+ (incluído em `.python/` neste projeto)
- Feche instâncias abertas do Word antes de gerar o PDF

## Como executar (interface gráfica)

```powershell
cd C:\srv\src\oc_maker
.python\python.exe app.py
```

Ou execute `run.bat`.

## Linha de comando

```powershell
.python\python.exe generate_cli.py "6ef211ff....xlsx" --ads-id 6a0f77afd1bc8 --tipo-venda Agência
```

## Fluxo

1. Selecione a planilha `.xlsx`
2. Escolha a campanha (quando houver mais de uma na aba `INVENTARIO`)
3. Ajuste **Tipo de Venda**, **Planejador/SSP**, **Deal**, checkboxes etc.
4. Clique em **Gerar PDF**

O arquivo é salvo em `output/`.

## Mapeamento da tabela (aba `INVENTARIO`)

| Campo PDF | Coluna Excel |
|-----------|--------------|
| ID Loja | Código (B) |
| Rede | Rede (J) |
| Total Inserções | Inserções (P) |
| Total Impactos | Impactos (O) |
| Budget Media Cost | Bruto Negociado (U) |
| Budget Publisher | Líquido Negociado (V) |

**Totais:** soma de cada coluna.

**VALOR LÍQUIDO (SSP):** soma de Budget Publisher.

**CPM MÉDIO:** `VALOR LÍQUIDO (SSP) / Total Impactos × 1000`.

## TECH FEE

Percentuais configuráveis em `tech_fees.json`, por **Tipo de Venda** e **Planejador/SSP**.

## Arquivos de referência

- Template: `Informe de Campanha - 202602-0000 - MODELO - com macros.docm`
- Planilha e PDF de exemplo na pasta do projeto

# oc_maker
Gerador de Ordens de Compra / Informe de Campanha. Partindo dos dados da planilha de Planning. 

