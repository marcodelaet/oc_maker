# OC Maker — Versão Node.js

Servidor **Express** com API REST para leitura de planilhas Excel e geração de PDF.

## Requisitos

- Node.js 18+
- npm

## Instalação

```bash
cd web-node
npm install
npm start
```

Abra `http://localhost:3000`

## API

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/api/health` | Status do serviço |
| POST | `/api/parse` | `multipart/form-data` campo `file` → lista campanhas |
| POST | `/api/summary` | `file` + `adsId`, `tipoVenda`, `planejadorSsp` → resumo |
| POST | `/api/generate` | `file` + campos do formulário → PDF |

## Variáveis de ambiente

| Variável | Padrão |
|----------|--------|
| `PORT` | `3000` |

## Estrutura

```
web-node/
  server.js           # Express + rotas
  lib/core.js         # Leitura Excel e cálculos
  lib/pdf.js          # Definição do documento PDF
  lib/pdfGenerator.js # pdfmake no servidor
  public/             # Interface web
  config/tech_fees.json
```
