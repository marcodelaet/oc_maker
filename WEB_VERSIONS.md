# Versões Web — OC Maker

Três implementações web do gerador de **Informe de Campanha** / **Ordem de Compra**, com interface amigável e fluxo em etapas.

| Pasta | Stack | Quando usar |
|-------|-------|-------------|
| [`web-js/`](web-js/) | JavaScript puro (navegador) | Hospedagem estática, sem servidor; dados não saem do PC |
| [`web-node/`](web-node/) | Node.js + Express | API REST, deploy em VPS/containers Node |
| [`web-php/`](web-php/) | PHP 8 + Apache + MySQL | Hospedagem tradicional LAMP; histórico no banco |

## Funcionalidades (todas as versões)

- Upload de planilha `.xlsx` (drag & drop)
- Seleção de campanha por AdsID
- Campos: tipo de venda, planejador/SSP, deal, checking, relatórios
- ID único `AAAAMM-XXXX`
- Cálculo de totais, TECH FEE, valor publisher e CPM médio
- Download do PDF gerado
- Layout responsivo com painel de resumo em tempo real

## Início rápido

### JavaScript (navegador)
```bash
cd web-js && python -m http.server 8080
```

### Node.js
```bash
cd web-node && npm install && npm start
```

### PHP
```bash
cd web-php && composer install
# Configure Apache DocumentRoot → web-php/public
# Importe database/schema.sql
```

Consulte o README de cada pasta para detalhes de deploy.

## Versão desktop original

A pasta raiz (`oc_maker/`) mantém a versão Python + Word COM para Windows.
