<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\AuthService;
use OcMaker\Permission;
use OcMaker\UserProfileService;

$isDev = isDevEnvironment();
$accountUser = (new AuthService())->currentUser();
$isAdmin = $accountUser !== null && ($accountUser['role'] ?? '') === 'administrador';
$canCalculator = $accountUser !== null && in_array($accountUser['role'] ?? '', ['administrador', 'financeiro'], true);
$canCampaigns = $accountUser !== null && in_array($accountUser['role'] ?? '', ['administrador', 'programatica'], true);
$pageTitle = $isDev ? 'OC Maker — DESENVOLVIMENTO' : 'OC Maker';
$footerLabel = $isDev ? 'OC Maker — versão PHP · AMBIENTE DE DESENVOLVIMENTO' : 'OC Maker — versão PHP · Apache + MariaDB/MySQL';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="<?= htmlspecialchars(url('favicon.png'), ENT_QUOTES, 'UTF-8') ?>" type="image/png">
  <link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="app-page <?= $isDev ? 'has-dev-banner' : '' ?>">
<?php if ($isDev): ?>
  <div class="dev-environment-banner" role="status" aria-live="polite">
    <span>⚠ Ambiente de desenvolvimento — dados locais, não use em produção</span>
  </div>
<?php endif; ?>
  <header class="app-header">
    <div class="header-inner">
      <div class="header-top">
        <div class="header-logo">
          <img src="<?= htmlspecialchars(url('assets/logo_retail_media.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Retail Media" onerror="this.onerror=null;this.src='<?= htmlspecialchars(url('assets/logo_converta.svg'), ENT_QUOTES, 'UTF-8') ?>'">
        </div>
        <p class="header-subtitle">Gerador de Informe / OdC em PDF e histórico no banco de dados.</p>
        <div class="header-title-block">
          <h1>OC Maker<?= $isDev ? ' <small class="dev-title-tag">DEV</small>' : '' ?></h1>
          <nav class="top-nav" id="authNav">
            <button type="button" class="icon-btn icon-btn--header" id="loginLink" title="Entrar" aria-label="Entrar"></button>
            <div id="userNav" class="user-nav hidden">
              <button type="button" class="icon-btn icon-btn--header hidden" id="calculatorLink" title="Calculadora financeira" aria-label="Calculadora financeira"></button>
              <button type="button" class="icon-btn icon-btn--header hidden" id="campaignsLink" title="Gerenciamento de campanhas" aria-label="Gerenciamento de campanhas"></button>
              <div class="user-menu" id="userMenu">
                <button type="button" class="user-menu-trigger" id="userMenuToggle" aria-expanded="false" aria-haspopup="true" aria-label="Menu do usuário">
                  <span id="userAvatarTrigger"></span>
                </button>
                <div class="user-menu-panel hidden" id="userMenuPanel" role="menu">
                  <div class="user-menu-card">
                    <span id="userMenuAvatar"></span>
                    <div class="user-menu-card-text">
                      <strong id="userMenuName"></strong>
                      <span id="userMenuEmail" class="user-menu-email"></span>
                      <span id="userMenuRole" class="user-menu-role"></span>
                    </div>
                  </div>
                  <div class="user-menu-list">
                    <a href="#" class="user-menu-item" role="menuitem" id="userMenuAccount">
                      <span class="user-menu-item-icon" aria-hidden="true"></span>
                      <span>Minha conta</span>
                    </a>
                    <a href="#" class="user-menu-item hidden" role="menuitem" id="userMenuAdmin">
                      <span class="user-menu-item-icon" aria-hidden="true"></span>
                      <span>Gerenciar usuários</span>
                    </a>
                    <a href="#" class="user-menu-item hidden" role="menuitem" id="userMenuActivityLog">
                      <span class="user-menu-item-icon" aria-hidden="true"></span>
                      <span>Log de eventos</span>
                    </a>
                    <a href="#" class="user-menu-item hidden" role="menuitem" id="userMenuCampaigns">
                      <span class="user-menu-item-icon" aria-hidden="true"></span>
                      <span>Gerenciar campanhas</span>
                    </a>
                    <button type="button" class="user-menu-item user-menu-item--danger" role="menuitem" id="logoutBtn">
                      <span class="user-menu-item-icon" aria-hidden="true"></span>
                      <span>Sair</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </nav>
        </div>
      </div>
    </div>
  </header>

  <main class="container">
    <div id="alert" class="alert alert-error hidden" role="alert"></div>
    <div class="steps">
      <span class="step active" data-step="1">1. Planilha</span>
      <span class="step" data-step="2">2. Campanha</span>
      <span class="step" data-step="3">3. Configurar</span>
      <span class="step" data-step="4">4. PDF</span>
    </div>

    <div class="grid grid-main">
      <section>
        <article class="card">
          <h2>1. Enviar planilha Excel</h2>
          <div id="dropzone" class="dropzone" tabindex="0">
            <input type="file" id="fileInput" accept=".xlsx">
            <strong>Arraste o arquivo .xlsx ou clique para selecionar</strong>
            <span>Processado no servidor Apache com PHP 8.</span>
          </div>
          <p id="fileMeta" class="file-meta"></p>
        </article>

        <article id="formSection" class="card hidden" style="margin-top:1.25rem">
          <h2>2. Configuração do documento</h2>
          <div class="form-row">
            <div>
              <label for="campaign">Campanha</label>
              <select id="campaign"></select>
            </div>
            <div>
              <label for="documentTitle">Tipo de documento</label>
              <select id="documentTitle">
                <option value="Informe de Campanha">Informe de Campanha</option>
                <option value="Ordem de Compra">Ordem de Compra</option>
              </select>
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="documentId">ID do documento</label>
              <div style="display:flex;gap:0.5rem">
                <input type="text" id="documentId" placeholder="AAAAMM-XXXX">
                <button type="button" class="icon-btn icon-btn--table" id="newIdBtn" title="Gerar novo ID" aria-label="Gerar novo ID"></button>
              </div>
            </div>
            <div>
              <label for="tipoVenda">Tipo de Venda</label>
              <select id="tipoVenda"></select>
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="planejador">Planejador / SSP</label>
              <select id="planejador"></select>
            </div>
            <div>
              <label for="tipoDeal">Tipo de DEAL (opcional)</label>
              <select id="tipoDeal"></select>
            </div>
          </div>
          <div class="form-row two">
            <div id="dealIdGroup" class="hidden">
              <label for="dealId">Deal ID (opcional)</label>
              <input type="text" id="dealId">
            </div>
            <div id="sspGroup" class="hidden">
              <label for="ocInforme">OC/Informe (SSP)</label>
              <input type="text" id="ocInforme">
            </div>
          </div>
          <div class="form-row two">
            <div>
              <label for="prazoPagamento">Prazo para Pagamento</label>
              <select id="prazoPagamento"></select>
            </div>
            <div>
              <label for="prazoUnidade">Calcular em</label>
              <select id="prazoUnidade">
                <option value="Dias">Dias</option>
                <option value="DFM" selected>DFM (dias após fim do mês)</option>
              </select>
            </div>
          </div>
          <p id="prazoPreview" class="file-meta"></p>
          <div class="checkbox-row">
            <input type="checkbox" id="checking" checked>
            <label for="checking">Checking Fotográfico</label>
          </div>
          <div class="checkbox-row">
            <input type="checkbox" id="relatorios">
            <label for="relatorios">Relatórios Adicionais</label>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-primary btn-with-icon" id="generateBtn" disabled>
              <span class="btn-icon" aria-hidden="true"></span>
              Gerar PDF
            </button>
          </div>
        </article>
      </section>

      <aside>
        <article class="card summary">
          <h2>Resumo</h2>
          <div id="summary"><p class="file-meta">Carregue uma planilha para começar.</p></div>
        </article>
        <article class="card" style="margin-top:1.25rem">
          <h2>Histórico recente</h2>
          <div id="history"><p class="file-meta">Carregando…</p></div>
        </article>
      </aside>
    </div>
  </main>

  <footer class="app-footer"><?= htmlspecialchars($footerLabel, ENT_QUOTES, 'UTF-8') ?></footer>

  <div id="loginModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">
    <div class="account-modal-panel account-modal-panel--narrow">
      <button type="button" class="account-modal-close" id="loginModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/login-panel.php'; ?>
    </div>
  </div>

  <?php if ($accountUser !== null): ?>
  <div id="accountModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
    <div class="account-modal-panel account-modal-panel--account">
      <button type="button" class="account-modal-close" id="accountModalClose" aria-label="Fechar">&times;</button>
      <?php
      $user = $accountUser;
      $countryCodes = UserProfileService::COUNTRY_CODES;
      require dirname(__DIR__) . '/templates/account-panel.php';
      ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canCalculator): ?>
  <div id="calculatorModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="calculatorModalTitle">
    <div class="account-modal-panel account-modal-panel--users">
      <button type="button" class="account-modal-close" id="calculatorModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/calculator-panel.php'; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($accountUser !== null): ?>
  <div id="usersModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="usersModalTitle">
    <div class="account-modal-panel account-modal-panel--users">
      <button type="button" class="account-modal-close" id="usersModalClose" aria-label="Fechar">&times;</button>
      <?php
      $roles = Permission::ROLES;
      $adminUserId = (int) ($accountUser['id'] ?? 0);
      require dirname(__DIR__) . '/templates/users-panel.php';
      ?>
    </div>
  </div>
  <div id="activityLogModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="activityLogModalTitle">
    <div class="account-modal-panel account-modal-panel--users">
      <button type="button" class="account-modal-close" id="activityLogModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/activity-log-panel.php'; ?>
    </div>
  </div>
  <?php if ($canCampaigns): ?>
  <div id="campaignModal" class="account-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignModalTitle">
    <div class="account-modal-panel account-modal-panel--users">
      <button type="button" class="account-modal-close" id="campaignModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/campaign-management-panel.php'; ?>
    </div>
  </div>
  <div id="campaignAnalyzeModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignAnalyzeTitle">
    <div class="account-modal-panel account-modal-panel--users account-modal-panel--analyze">
      <button type="button" class="account-modal-close" id="campaignAnalyzeModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/campaign-analyze-panel.php'; ?>
    </div>
  </div>
  <div id="campaignUnitRejectModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignUnitRejectTitle">
    <div class="account-modal-backdrop-panel">
      <?php require dirname(__DIR__) . '/templates/campaign-unit-reject-panel.php'; ?>
    </div>
  </div>
  <div id="campaignOfflineModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignOfflineTitle">
    <div class="account-modal-backdrop-panel">
      <?php require dirname(__DIR__) . '/templates/campaign-offline-panel.php'; ?>
    </div>
  </div>
  <div id="campaignApprovedModal" class="account-modal account-modal--stack hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="campaignApprovedTitle">
    <div class="account-modal-panel account-modal-panel--users account-modal-panel--analyze">
      <button type="button" class="account-modal-close" id="campaignApprovedModalClose" aria-label="Fechar">&times;</button>
      <?php require dirname(__DIR__) . '/templates/campaign-approved-panel.php'; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($accountUser !== null): ?>
  <div id="forcePasswordModal" class="account-modal account-modal--locked hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="forcePasswordTitle">
    <div class="account-modal-panel account-modal-panel--force-password">
      <?php require dirname(__DIR__) . '/templates/force-password-panel.php'; ?>
    </div>
  </div>
  <?php endif; ?>

  <div id="deleteModal" class="confirm-modal hidden" aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="confirm-modal-panel">
      <h2 id="deleteModalTitle">Confirmar remoção</h2>
      <p id="deleteModalMessage">Deseja remover este documento do histórico?</p>
      <div class="confirm-modal-actions">
        <button type="button" class="btn btn-secondary" id="deleteCancelBtn">Cancelar</button>
        <button type="button" class="btn btn-danger" id="deleteConfirmBtn">Remover</button>
      </div>
    </div>
  </div>

  <div id="appLoading" class="app-loading hidden" aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="loadingTitle">
    <div class="app-loading-panel">
      <div class="loading-spinner" aria-hidden="true"></div>
      <p id="loadingTitle" class="loading-title">Aguarde</p>
      <p class="loading-message">Processando…</p>
      <p class="loading-hint">Estamos lendo dados ou gerando o documento. A tela ficará bloqueada até concluir — não feche esta página.</p>
    </div>
  </div>

  <script>
    window.OC_MAKER = {
      env: <?= json_encode(appEnv(), JSON_UNESCAPED_UNICODE) ?>,
      isDev: <?= $isDev ? 'true' : 'false' ?>,
      basePath: <?= json_encode(appBasePath(), JSON_UNESCAPED_UNICODE) ?>,
      csrf_token: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>,
      api: {
        parse: <?= json_encode(url('api/parse.php'), JSON_UNESCAPED_UNICODE) ?>,
        campaign: <?= json_encode(url('api/campaign.php'), JSON_UNESCAPED_UNICODE) ?>,
        fees: <?= json_encode(url('api/fees.php'), JSON_UNESCAPED_UNICODE) ?>,
        generate: <?= json_encode(url('api/generate.php'), JSON_UNESCAPED_UNICODE) ?>,
        history: <?= json_encode(url('api/history.php'), JSON_UNESCAPED_UNICODE) ?>,
        document: <?= json_encode(url('api/document.php'), JSON_UNESCAPED_UNICODE) ?>,
        authMe: <?= json_encode(url('api/auth/me.php'), JSON_UNESCAPED_UNICODE) ?>,
        authLogin: <?= json_encode(url('api/auth/login.php'), JSON_UNESCAPED_UNICODE) ?>,
        authLogout: <?= json_encode(url('api/auth/logout.php'), JSON_UNESCAPED_UNICODE) ?>,
        authAvatar: <?= json_encode(url('api/auth/avatar.php'), JSON_UNESCAPED_UNICODE) ?>,
        authProfile: <?= json_encode(url('api/auth/profile.php'), JSON_UNESCAPED_UNICODE) ?>,
        authPassword: <?= json_encode(url('api/auth/password.php'), JSON_UNESCAPED_UNICODE) ?>,
        authTotpSetup: <?= json_encode(url('api/auth/totp-setup.php'), JSON_UNESCAPED_UNICODE) ?>,
        authTotpConfirm: <?= json_encode(url('api/auth/totp-confirm.php'), JSON_UNESCAPED_UNICODE) ?>,
        deleteDocument: <?= json_encode(url('api/documents/delete.php'), JSON_UNESCAPED_UNICODE) ?>,
        calculator: <?= json_encode(url('api/calculator.php'), JSON_UNESCAPED_UNICODE) ?>,
        adminUsers: <?= json_encode(url('api/admin/users.php'), JSON_UNESCAPED_UNICODE) ?>,
        adminActivityLog: <?= json_encode(url('api/admin/activity-log.php'), JSON_UNESCAPED_UNICODE) ?>,
        adminActivityLogExport: <?= json_encode(url('api/admin/activity-log-export.php'), JSON_UNESCAPED_UNICODE) ?>,
        campaignManagement: <?= json_encode(url('api/campaign-management.php'), JSON_UNESCAPED_UNICODE) ?>,
      },
      accountUser: <?= json_encode($accountUser, JSON_UNESCAPED_UNICODE) ?>,
      urls: {
        login: <?= json_encode(url('index.php') . '?login=1', JSON_UNESCAPED_UNICODE) ?>,
        home: <?= json_encode(url('index.php'), JSON_UNESCAPED_UNICODE) ?>,
        account: <?= json_encode(url('index.php') . '?account=1', JSON_UNESCAPED_UNICODE) ?>,
        adminUsers: <?= json_encode(url('index.php') . '?users=1', JSON_UNESCAPED_UNICODE) ?>,
        activityLog: <?= json_encode(url('index.php') . '?activityLog=1', JSON_UNESCAPED_UNICODE) ?>,
        calculator: <?= json_encode(url('index.php') . '?calculator=1', JSON_UNESCAPED_UNICODE) ?>,
        campaigns: <?= json_encode(url('index.php') . '?campaigns=1', JSON_UNESCAPED_UNICODE) ?>,
      },
    };
  </script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/icons.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/loading.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/financials.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/password-policy.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/login.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php if ($accountUser !== null): ?>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/qrcode-generator.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/totp-setup.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/account.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/force-password.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php endif; ?>
  <?php if ($canCalculator): ?>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/calculator.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php endif; ?>
  <?php if ($accountUser !== null): ?>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/users.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/activity-log.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php endif; ?>
  <?php if ($canCampaigns): ?>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/offline-screens.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/campaign-draft.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/campaign-management.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/campaign-pacing-charts.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/campaign-approved.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <?php endif; ?>
  <script src="<?= htmlspecialchars(assetUrl('assets/js/app.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
