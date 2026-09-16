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
php database/ensure-storage.php
```

## Ambientes (desenvolvimento vs produção)

O mesmo código serve os dois ambientes. A diferença fica no arquivo **`web-php/.env`** (não é necessário duplicar pastas).

**Prioridade de configuração do banco:** `.env` → `config/database.local.php` → `config/database.php` (defaults).

| Variável | Desenvolvimento (local) | Produção (services.invian.ai) |
|----------|-------------------------|-------------------------------|
| `APP_ENV` | `development` | `production` |
| `DB_HOST` | `127.0.0.1` | host MySQL do servidor |
| `DB_NAME` / `DB_USER` / `DB_PASS` | credenciais locais | credenciais oficiais |

**Desenvolvimento** (`http://localhost/oc_maker/web-php/public/`):

```env
APP_ENV=development
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oc_maker
DB_USER=theled
DB_PASS=sua_senha_local
```

Se o Apache roda em **Docker/Linux** (`/var/www/html/...`) e o MySQL está no **host Windows/WSL**, `127.0.0.1` aponta para o container — use:

```env
DB_HOST=host.docker.internal
```

Exibe faixa amarela/preta no topo e a tag **DEV** no título.

**Produção** (`https://services.invian.ai/maker/`):

```env
APP_ENV=production
DB_HOST=mysql.seu-servidor
DB_PORT=3306
DB_NAME=oc_maker_prod
DB_USER=oc_maker_user
DB_PASS=senha_segura
```

Sem faixa de aviso — interface idêntica à versão homologada.

`APP_BASE_PATH` é opcional (ex.: `/maker` ou `/oc_maker/web-php/public`). Se omitido, o sistema detecta automaticamente a partir da URL do `index.php`.

Credenciais padrão em `.env.example`:

- Host: `127.0.0.1` (use IP do Windows se o PHP rodar no WSL)
- Porta: `3306`
- Banco: `oc_maker`
- Usuário: `theled`

Antes do setup, confirme a conexão:

```bash
php database/test-connection.php
php database/setup.php
php database/seed_admin.php
```

O seed cria o administrador inicial (`admin@retailmedia.local` / senha definida no comando).

**Logo Retail Media:** copie a imagem enviada para `public/assets/logo_retail_media.png`.

## Autenticação e perfis

| Perfil | Acesso |
|--------|--------|
| **Comercial** (sem login) | Upload, PDF, histórico, resumo, editar |
| **Logado** | Tudo do comercial + **Remover** histórico |
| **Financeiro** | + Calculadora |
| **Administrador** | Tudo + gestão de usuários |

- Login opcional: `login.php`
- 2FA (Google/Microsoft Authenticator): `account.php`
- Usuários (admin): `admin/users.php`
- Calculadora: `calculator.php`

Segurança: senhas com `password_hash`, sessões HTTP-only, CSRF em POSTs sensíveis, PDO prepared statements, sanitização de entradas.

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

Permissões de escrita em `storage/` (uploads, planilhas arquivadas e PDFs). Em produção Linux:

```bash
cd /var/www/html/oc_maker/web-php
php database/ensure-storage.php
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage
```

Se o Apache usar outro usuário (ex.: `apache`), substitua `www-data`.

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
| `POST …/api/parse.php` | Lista campanhas |
| `POST …/api/campaign.php` | Dados da campanha (totais) |
| `GET …/api/fees.php` | Tech fees |
| `POST …/api/generate.php` | Gera PDF + grava histórico |
| `GET …/api/history.php` | Últimos documentos |
| `GET …/api/document.php?id=` | Detalhe do documento (resumo e dados para edição) |
| `GET …/api/db-check.php` | Diagnóstico de conexão MySQL |

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
