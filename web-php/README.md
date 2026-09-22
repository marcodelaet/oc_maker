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

### Desenvolvimento: Docker no Windows

O PHP/Apache roda **dentro do container** (usuário `www-data`), com o projeto montado a partir do Windows (`C:\srv\src\oc_maker\...`). Permissões de `storage/` devem ser corrigidas **no container**, não com `icacls` no host.

Após `composer install` e setup do banco:

```cmd
cd C:\srv\src\oc_maker\web-php
database\fix-storage-docker.bat
```

Alternativa direta (PowerShell ou CMD):

```cmd
docker exec -u root webserver_php sh /var/www/html/oc_maker/web-php/database/fix-storage-docker.sh
```

**Não use `\` no path dentro do container** — sempre barras `/`.

Opcional: `.\database\fix-storage-docker.ps1` (se a política de execução do PowerShell permitir scripts locais).

Upload de criativos (até 32 MB): se ainda falhar por limite PHP, reinicie o container após deploy de `public/.user.ini` ou ajuste `upload_max_filesize` / `post_max_size` na imagem Docker.

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
    # Criativos: até 32 MB por arquivo (ajuste se necessário)
    php_value upload_max_filesize 32M
    php_value post_max_size 32M
</VirtualHost>
```

**Upload de criativos (mídia):** o projeto inclui `public/.user.ini` e regras em `public/.htaccess` com limite de **32 MB** por arquivo. Se o upload falhar com erro de `post_max_size` / `upload_max_filesize`, confira o `php.ini` do servidor ou reinicie PHP-FPM após alterar `.user.ini`.

Permissões de escrita em `storage/` (uploads, planilhas, PDFs e **criativos**):

| Ambiente | Comando |
|----------|---------|
| **Docker no Windows (dev)** | `database\fix-storage-docker.bat` |
| **Linux (produção)** | `sudo chown -R www-data:www-data storage && sudo chmod -R 775 storage` |
| **PHP nativo no host** | `php database/ensure-storage.php` |

**Windows sem Docker (Apache/IIS local):** PowerShell como administrador — `icacls ".\storage" /grant "IIS_IUSRS:(OI)(CI)M" /T`

## Servidor embutido PHP (desenvolvimento)

O servidor `php -S` **não lê `.htaccess`**. Use o router incluído:

```bash
cd web-php
php -d upload_max_filesize=32M -d post_max_size=32M -S localhost:8080 -t public public/router.php
```

## Uso

1. Acesse a URL configurada
2. Envie a planilha `.xlsx`
3. Selecione campanha e opções
4. Gere o PDF — o registro é salvo no MySQL e aparece em **Histórico recente**
5. No histórico, use **Resumo** para rever os valores ou **Editar** para ajustar o formulário e gerar um novo PDF

## Relatórios de campanha — formato Admooh (manual)

A Admooh ainda **não exporta** relatório diário por tela com o layout abaixo. Enquanto isso, os dados podem ser copiados manualmente da plataforma para uma planilha CSV e importados na aba **Controle** da campanha aprovada.

> **Atenção:** a Admooh está reformulando os formulários da plataforma. Este layout é **provisório** e pode mudar quando houver export oficial — o importador detecta colunas pelo cabeçalho e poderá ser ajustado.

### Arquivo esperado

- Formato: **CSV** (delimitador `;` ou `,`)
- Primeira linha: cabeçalho com os nomes abaixo (underscores e espaços são equivalentes)

| Coluna | Descrição |
|--------|-----------|
| `Date` | Data de referência do dia (`dd/mm/aaaa`) |
| `Placement_ID` | ID interno da unidade na Admooh (opcional para import) |
| `Placement_Name` | Nome da unidade na Admooh (opcional) |
| `Device_ID` | ID interno da tela na Admooh (opcional) |
| `Device_Name` | Nome da tela na Admooh — usado para cruzar com o código de tela da campanha |
| `Requested_Bids` | Quantidade de requisições |
| `Response_Bids` | Respostas da tela (não usado no cálculo atual) |
| `Executed_Bids` | Impressões / execuções da mídia |
| `Executed_Impacts` | Impactos |

### Detecção automática

O sistema reconhece a plataforma **admooh** quando o cabeçalho contém `Device_Name`, `Requested_Bids` e `Executed_Impacts`. O mapeamento interno é:

- `Requested_Bids` → requisições
- `Executed_Bids` → impressões
- `Executed_Impacts` → impactos
- Consumo → calculado com o **CPM do Deal** (`impactos × CPM ÷ 1000`) quando o CSV não traz valor financeiro

### Match de telas

`Device_Name` é comparado aos códigos de tela cadastrados na campanha. Sufixos comuns da Admooh são normalizados automaticamente, por exemplo:

- `1204-PDA-T01-JOAQUIM FLORIANO | Face1` → `1204-PDA-T01-JOAQUIM FLORIANO`
- `1214-PDA-T01-SÓCRATES_App` → `1214-PDA-T01-SÓCRATES`

Se alguma tela não for identificada, a importação **parcial** grava as linhas reconhecidas e abre um diálogo para cada tela pendente:

1. **Adicionar à campanha e ao Deal** — busca a tela no inventário da campanha e inclui a unidade no Deal
2. **Vincular a uma tela existente** — cria um vínculo permanente entre o nome Admooh e o código interno
3. **Ignorar identificação** — importa os dados marcados como *Tela não identificada* na grade de controle

Os vínculos ficam salvos por Deal em `campaign_deal_device_aliases` e são reutilizados em importações futuras.

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
