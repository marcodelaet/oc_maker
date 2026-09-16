<?php



declare(strict_types=1);



/** @var array<string, mixed> $user */

/** @var list<array{code:string,label:string,dial:string,flag:string}> $countryCodes */



use OcMaker\IconHelper;

use OcMaker\PageShell;



$totpEnabled = !empty($user['totp_enabled']);

$displayName = (string) ($user['display_name'] ?? $user['name'] ?? '');

$userEmail = (string) ($user['email'] ?? '');

$userRole = (string) ($user['role'] ?? '');

?>

<article class="page-card account-panel-card">

  <header class="page-card-hero page-card-hero--account">

    <div class="account-hero-avatar-wrap">

      <?= PageShell::avatarPickerHtml($user, 'avatar-xl', 'account') ?>

      <input type="file" id="accountAvatarInput" accept="image/jpeg,image/png,image/webp" hidden>

      <p class="file-meta file-meta--hero">JPG, PNG ou WebP · máx. 2 MB</p>

    </div>

    <div class="page-card-hero-text">

      <span class="account-panel-kicker">Perfil</span>

      <h1 id="accountModalTitle">Minha conta</h1>

      <p class="user-meta" id="accountHeroDisplayName"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></p>

      <p class="user-meta user-meta--muted" id="accountHeroEmail"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></p>

      <?php if ($userRole !== ''): ?>

        <span class="role-chip" id="accountHeroRole"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></span>

      <?php endif; ?>

    </div>

  </header>



  <div id="accountAlert" class="alert alert-error hidden account-panel-alert" role="alert"></div>



  <section class="account-section account-section--profile">

    <div class="account-section-head">

      <h2>Informação pessoal</h2>

      <p class="section-lead">Nome e data de nascimento exibidos no seu perfil.</p>

    </div>

    <form id="accountProfileForm" class="stack-form profile-form account-form">

      <div class="form-row two">

        <label>Nome <span class="req">*</span>

          <span class="field-input-wrap">

            <span class="field-icon" aria-hidden="true"><?= IconHelper::svg('user', 18) ?></span>

            <input type="text" id="accountFirstName" required maxlength="120" autocomplete="given-name" placeholder="Nome" value="<?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          </span>

        </label>

        <label>Sobrenome

          <span class="field-input-wrap">

            <span class="field-icon" aria-hidden="true"><?= IconHelper::svg('user', 18) ?></span>

            <input type="text" id="accountLastName" maxlength="120" autocomplete="family-name" placeholder="Sobrenome" value="<?= htmlspecialchars((string) ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          </span>

        </label>

      </div>

      <label>Data de nascimento

        <input type="date" id="accountBirthDate" autocomplete="bday" value="<?= htmlspecialchars((string) ($user['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

      </label>

    </form>

  </section>



  <section class="account-section account-section--contact">

    <div class="account-section-head">

      <h2>Informações de contato</h2>

      <p class="section-lead">Usuário, e-mail e telefone são únicos e podem ser usados no login.</p>

    </div>

    <div class="stack-form profile-form account-form">

      <label>Nome de usuário <span class="req">*</span>

        <span class="field-input-wrap">

          <span class="field-icon" aria-hidden="true"><?= IconHelper::svg('user', 18) ?></span>

          <input type="text" id="accountUsername" required maxlength="64" autocomplete="username" pattern="[a-z0-9._]{3,64}" placeholder="nome.sobrenome" value="<?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        </span>

      </label>

      <label>E-mail <span class="req">*</span>

        <span class="field-input-wrap">

          <span class="field-icon" aria-hidden="true"><?= IconHelper::svg('mail', 18) ?></span>

          <input type="email" id="accountEmail" required maxlength="190" autocomplete="email" placeholder="email@empresa.com" value="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?>">

        </span>

      </label>

      <label>Telefone

        <div class="phone-field">

          <?php

          $selectedDial = (string) ($user['phone_country_code'] ?? '+55');

          $selectedCountry = $countryCodes[0];

          foreach ($countryCodes as $cc) {

              if ($cc['dial'] === $selectedDial) {

                  $selectedCountry = $cc;

                  break;

              }

          }

          $flagBase = 'https://flagcdn.com/w40/' . strtolower($selectedCountry['code']) . '.png';

          ?>

          <div class="phone-country-picker" id="accountPhoneCountryPicker">

            <select id="accountPhoneCountry" class="phone-country-select-native" aria-hidden="true" tabindex="-1">

              <?php foreach ($countryCodes as $cc): ?>

                <option value="<?= htmlspecialchars($cc['dial'], ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars(strtolower($cc['code']), ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($cc['label'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedDial === $cc['dial'] ? 'selected' : '' ?>>

                  <?= htmlspecialchars($cc['label'] . ' ' . $cc['dial'], ENT_QUOTES, 'UTF-8') ?>

                </option>

              <?php endforeach; ?>

            </select>

            <button type="button" class="phone-country-trigger" id="accountPhoneCountryBtn" aria-haspopup="listbox" aria-expanded="false" aria-label="Código do país">

              <img class="phone-flag" id="accountPhoneFlag" src="<?= htmlspecialchars($flagBase, ENT_QUOTES, 'UTF-8') ?>" alt="" width="20" height="15">

              <span class="phone-dial" id="accountPhoneDial"><?= htmlspecialchars($selectedDial, ENT_QUOTES, 'UTF-8') ?></span>

              <span class="phone-country-chevron" aria-hidden="true">▼</span>

            </button>

            <div class="phone-country-menu hidden" id="accountPhoneCountryMenu" role="listbox" aria-label="País">

              <?php foreach ($countryCodes as $cc): ?>

                <button type="button" class="phone-country-option" role="option" data-dial="<?= htmlspecialchars($cc['dial'], ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars(strtolower($cc['code']), ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($cc['label'], ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= $selectedDial === $cc['dial'] ? 'true' : 'false' ?>">

                  <img class="phone-flag" src="https://flagcdn.com/w40/<?= htmlspecialchars(strtolower($cc['code']), ENT_QUOTES, 'UTF-8') ?>.png" alt="" width="20" height="15">

                  <span class="phone-dial"><?= htmlspecialchars($cc['dial'], ENT_QUOTES, 'UTF-8') ?></span>

                  <span class="phone-country-name"><?= htmlspecialchars($cc['label'], ENT_QUOTES, 'UTF-8') ?></span>

                </button>

              <?php endforeach; ?>

            </div>

          </div>

          <span class="phone-input-wrap">

            <span class="field-icon" aria-hidden="true"><?= IconHelper::svg('phone', 18) ?></span>

            <input type="tel" id="accountPhone" inputmode="tel" autocomplete="tel-national" placeholder="(11) 99999-9999" value="<?= htmlspecialchars((string) ($user['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

          </span>

        </div>

      </label>

      <div class="account-section-actions">

        <button type="button" class="btn btn-primary btn-block" id="accountSaveProfileBtn">Salvar informações</button>

      </div>

    </div>

  </section>



  <section class="account-section account-section--password">

    <div class="account-section-head">

      <h2>Alterar senha</h2>

      <p class="section-lead">Escolha uma senha forte para proteger sua conta.</p>

    </div>

    <form id="accountPasswordForm" class="stack-form profile-form account-form">

      <?php

      $passwordFormUsername = (string) ($user['username'] ?? '');

      if ($passwordFormUsername === '') {

          $passwordFormUsername = $userEmail;

      }

      ?>

      <input type="text" name="username" value="<?= htmlspecialchars($passwordFormUsername, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" tabindex="-1" aria-hidden="true" class="field-sr-only">



      <div class="users-password-block">

        <div class="users-password-fields">

          <label>Senha atual <span class="req">*</span>

            <div class="password-input-wrap">

              <input type="password" id="accountCurrentPassword" required autocomplete="current-password" placeholder="Senha atual">

              <button type="button" class="password-toggle-btn" id="accountCurrentPasswordToggle" aria-label="Mostrar senha"></button>

            </div>

          </label>

          <label>Nova senha <span class="req">*</span>

            <div class="password-input-wrap">

              <input type="password" id="accountNewPassword" required autocomplete="new-password" placeholder="Nova senha">

              <button type="button" class="password-toggle-btn" id="accountNewPasswordToggle" aria-label="Mostrar senha"></button>

            </div>

          </label>

        </div>

        <aside class="users-password-aside" aria-labelledby="accountNewPasswordRulesTitle">

          <h3 id="accountNewPasswordRulesTitle" class="users-password-aside-title">Requisitos da senha</h3>

          <div id="accountNewPasswordRules"></div>

        </aside>

      </div>



      <div class="account-section-actions">

        <button type="submit" class="btn btn-primary btn-block">Salvar senha</button>

      </div>

    </form>

  </section>



  <section class="account-section account-section--totp">

    <div class="account-section-head account-section-head--row">

      <div>

        <h2>Autenticação em dois fatores</h2>

        <p class="section-lead">Proteja o acesso com Google ou Microsoft Authenticator.</p>

      </div>

      <?= PageShell::statusBadge($totpEnabled, '2FA ativo', '2FA inativo') ?>

    </div>



    <?php if (!$totpEnabled): ?>

    <button type="button" class="btn btn-secondary btn-setup-totp" id="accountSetupTotpBtn">

      Configurar autenticador

    </button>

    <div id="accountTotpSetup" class="totp-setup hidden">

      <ol class="totp-steps">

        <li>Instale o app <strong>Google Authenticator</strong> ou <strong>Microsoft Authenticator</strong> no celular.</li>

        <li>Toque em <strong>Adicionar conta</strong> e escolha <strong>Escanear QR Code</strong>.</li>

        <li>Aponte a câmera para o código abaixo.</li>

      </ol>

      <div class="totp-qr-panel">

        <div id="accountTotpQr" class="totp-qr" aria-live="polite"></div>

      </div>

      <details class="totp-manual">

        <summary>Não consegue escanear? Digite a chave manualmente</summary>

        <div class="totp-secret-row">

          <code id="accountTotpSecret"></code>

          <button type="button" class="btn btn-secondary btn-sm" id="accountCopySecretBtn">Copiar</button>

        </div>

      </details>

      <label>Código de verificação (6 dígitos)

        <input type="text" id="accountTotpCode" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" class="totp-code-input">

      </label>

      <div class="account-section-actions">

        <button type="button" class="btn btn-primary" id="accountConfirmTotpBtn">Confirmar e ativar 2FA</button>

      </div>

    </div>

    <?php else: ?>

    <p class="section-note">Seu autenticador está vinculado. Para desativar, contate um administrador.</p>

    <?php endif; ?>

  </section>

</article>

