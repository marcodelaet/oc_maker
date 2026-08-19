# OC Maker — Versão JavaScript (navegador)

Aplicação **100% client-side**: lê planilhas `.xlsx` e gera PDF no próprio navegador, sem backend.

## Requisitos

- Navegador moderno (Chrome, Edge, Firefox)
- Servidor HTTP local (módulos ES6 e `fetch` de config)

## Como executar

```bash
# Python
cd web-js
python -m http.server 8080

# Node (npx)
npx serve .
```

Abra `http://localhost:8080`

## Uso

1. Arraste ou selecione a planilha Excel (`.xlsx`)
2. Escolha a campanha (AdsID)
3. Configure tipo de venda, planejador, deal e opções
4. Clique em **Gerar PDF** — o download inicia automaticamente

## Arquivos

| Arquivo | Descrição |
|---------|-----------|
| `js/core.js` | Leitura Excel, cálculos, ID do documento |
| `js/pdf.js` | Montagem e download do PDF (pdfmake) |
| `js/app.js` | Interface e fluxo |
| `config/tech_fees.json` | Percentuais de TECH FEE |

## Dependências (CDN)

- [SheetJS](https://sheetjs.com/) — leitura Excel
- [pdfmake](https://pdfmake.github.io/docs/) — geração PDF

Nenhuma instalação de pacotes necessária.
