# OC Maker — Versão PHP (Apache + MySQL)

Aplicação web para **Apache 2.4**, **PHP 8+** e **MySQL/MariaDB**, com histórico de documentos gerados.

## Requisitos

- PHP 8.0+ com extensões: `pdo_mysql`, `zip`, `xml`, `gd` ou `mbstring`
- Composer
- Apache com `mod_rewrite`
- MySQL 5.7+ ou MariaDB 10.3+

## Instalação

```bash
cd web-php
composer install
cp .env.example .env
php database/setup.php
```

Credenciais padrão em `.env`:

- Host: `127.0.0.1` (use IP do Windows se o PHP rodar no WSL)
- Porta: `3306`
- Banco: `oc_maker`
- Usuário: `theled`

Antes do setup, confirme a conexão:

```bash
php database/test-connection.php
php database/setup.php
```

Configure o **DocumentRoot** para a pasta `public/`:

```apache
<VirtualHost *:80>
    ServerName oc-maker.local
    DocumentRoot "C:/srv/src/oc_maker/web-php/public"
    <Directory "C:/srv/src/oc_maker/web-php/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Permissões de escrita em `storage/uploads` e `storage/pdf`.

## Servidor embutido PHP (desenvolvimento)

O servidor `php -S` **não lê `.htaccess`**. Use o router incluído:

```bash
cd web-php
php -S localhost:8080 -t public public/router.php
```

## Uso

1. Acesse a URL configurada
2. Envie a planilha `.xlsx`
3. Selecione campanha e opções
4. Gere o PDF — o registro é salvo no MySQL e aparece em **Histórico recente**
5. No histórico, use **Resumo** para rever os valores ou **Editar** para ajustar o formulário e gerar um novo PDF

## API

| Rota | Descrição |
|------|-----------|
| `POST /maker/api/parse` | Lista campanhas |
| `POST /maker/api/summary` | Resumo financeiro |
| `POST /maker/api/generate` | Gera PDF + grava histórico |
| `GET /maker/api/history` | Últimos documentos |
| `GET /maker/api/document?id=` | Detalhe do documento (resumo e dados para edição) |

## Estrutura

```
web-php/
  public/           # DocumentRoot Apache
  src/              # Classes PHP (PSR-4)
  templates/pdf.php # HTML do PDF (Dompdf)
  database/         # Schema SQL
  config/           # tech_fees.json
  storage/          # Uploads e PDFs
```

## Dependências Composer

- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) — leitura Excel
- [Dompdf](https://github.com/dompdf/dompdf) — geração PDF
