<?php



declare(strict_types=1);



/** @var list<string> $roles */

/** @var int $adminUserId */



use OcMaker\Permission;

?>

<article class="page-card users-panel-card">

  <header class="page-card-hero page-card-hero--users">

    <div class="users-panel-icon" aria-hidden="true"></div>

    <div class="page-card-hero-text">

      <span class="users-panel-kicker">Administração</span>

      <h1 id="usersModalTitle">Gestão de usuários</h1>

      <p class="section-lead">Crie e gerencie perfis de acesso ao sistema.</p>

      <p class="users-panel-count" id="usersPanelCount"></p>

    </div>

  </header>



  <div id="usersAlert" class="alert alert-error hidden users-panel-alert" role="alert"></div>



  <section class="users-section users-section--edit hidden" id="usersEditSection">

    <div class="users-section-head">

      <h2>Editar usuário</h2>

      <p class="section-lead" id="usersEditLead"></p>

    </div>

    <form id="usersEditForm" class="stack-form profile-form users-form" autocomplete="off">

      <input type="hidden" id="usersEditId">

      <div class="form-row two">

        <label>Nome <span class="req">*</span>

          <input type="text" id="usersEditName" required maxlength="120" autocomplete="off" placeholder="Nome">

        </label>

        <label>Sobrenome

          <input type="text" id="usersEditLastName" maxlength="120" autocomplete="off" placeholder="Sobrenome">

        </label>

      </div>

      <label>Usuário <span class="req">*</span>

        <input type="text" id="usersEditUsername" required maxlength="64" pattern="[a-z0-9._]{3,64}" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="nome.sobrenome">

      </label>

      <label>E-mail <span class="req">*</span>

        <input type="email" id="usersEditEmail" required maxlength="190" autocomplete="off" placeholder="email@empresa.com">

      </label>

      <label>Perfil

        <select id="usersEditRole">

          <?php foreach ($roles as $role): ?>

            <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>

          <?php endforeach; ?>

        </select>

      </label>

      <div class="users-edit-access" id="usersEditAccessWrap">
        <div class="users-edit-access-meta">
          <span id="usersEditAccessBadge"></span>
          <p class="field-hint" id="usersEditAccessHint"></p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm users-access-toggle" id="usersEditAccessToggle">Remover acesso</button>
      </div>

      <div class="users-password-block">

        <div class="users-password-fields">

          <label>Nova senha

            <span class="field-hint field-hint--inline">Opcional — exige troca no próximo login</span>

            <div class="password-field-row">

              <div class="password-input-wrap">

                <input type="password" id="usersEditPassword" autocomplete="new-password" placeholder="Nova senha">

                <button type="button" class="password-toggle-btn" id="usersEditPasswordToggle" aria-label="Mostrar senha"></button>

              </div>

              <button type="button" class="btn btn-secondary btn-sm" id="usersEditPasswordGen">Gerar senha</button>

            </div>

          </label>

          <div id="usersEditPasswordReveal" class="password-generated-row hidden" aria-live="polite">

            <span id="usersEditPasswordRevealText" class="password-generated"></span>

            <button type="button" class="icon-btn icon-btn--muted" id="usersEditPasswordCopy" title="Copiar senha" aria-label="Copiar senha"></button>

          </div>

        </div>

        <aside class="users-password-aside" aria-labelledby="usersEditPasswordRulesTitle">

          <h3 id="usersEditPasswordRulesTitle" class="users-password-aside-title">Requisitos da senha</h3>

          <div id="usersEditPasswordRules"></div>

        </aside>

      </div>



      <div class="users-section-actions users-section-actions--split">

        <button type="submit" class="btn btn-primary">Salvar alterações</button>

        <button type="button" class="btn btn-secondary" id="usersEditCancel">Cancelar</button>

      </div>

    </form>

  </section>



  <section class="users-section users-section--create">

    <div class="users-section-head">

      <h2>Novo usuário</h2>

      <p class="section-lead">Preencha os dados para criar um novo acesso.</p>

    </div>

    <form id="usersCreateForm" class="stack-form profile-form users-form" autocomplete="off">

      <div class="form-row two">

        <label>Nome <span class="req">*</span>

          <input type="text" id="usersCreateName" required maxlength="120" autocomplete="off" placeholder="Nome">

        </label>

        <label>Sobrenome

          <input type="text" id="usersCreateLastName" maxlength="120" autocomplete="off" placeholder="Sobrenome">

        </label>

      </div>

      <label>Usuário <span class="req">*</span>

        <input type="text" id="usersCreateUsername" required maxlength="64" pattern="[a-z0-9._]{3,64}" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="nome.sobrenome">

        <span class="field-hint">Sugestão automática com base no nome — edite se preferir.</span>

      </label>

      <label>E-mail <span class="req">*</span>

        <input type="email" id="usersCreateEmail" required maxlength="190" autocomplete="off" placeholder="email@empresa.com">

      </label>



      <div class="users-password-block">

        <div class="users-password-fields">

          <label>Senha inicial <span class="req">*</span>

            <div class="password-field-row">

              <div class="password-input-wrap">

                <input type="password" id="usersCreatePassword" required autocomplete="new-password" placeholder="Senha temporária">

                <button type="button" class="password-toggle-btn" id="usersCreatePasswordToggle" aria-label="Mostrar senha"></button>

              </div>

              <button type="button" class="btn btn-secondary btn-sm" id="usersCreatePasswordGen">Gerar senha</button>

            </div>

          </label>

          <div id="usersCreatePasswordReveal" class="password-generated-row hidden" aria-live="polite">

            <span id="usersCreatePasswordRevealText" class="password-generated"></span>

            <button type="button" class="icon-btn icon-btn--muted" id="usersCreatePasswordCopy" title="Copiar senha" aria-label="Copiar senha"></button>

          </div>

        </div>

        <aside class="users-password-aside" aria-labelledby="usersCreatePasswordRulesTitle">

          <h3 id="usersCreatePasswordRulesTitle" class="users-password-aside-title">Requisitos da senha</h3>

          <div id="usersCreatePasswordRules"></div>

        </aside>

      </div>



      <label>Perfil

        <select id="usersCreateRole">

          <?php foreach ($roles as $role): ?>

            <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>

          <?php endforeach; ?>

        </select>

      </label>



      <div class="users-section-actions">

        <button type="submit" class="btn btn-primary btn-block">Adicionar usuário</button>

      </div>

    </form>

  </section>



  <section class="users-section users-section--list">

    <div class="users-section-head">

      <h2>Usuários cadastrados</h2>

    </div>

    <div id="usersListWrap">

      <p class="file-meta users-list-loading">Carregando…</p>

    </div>

  </section>

</article>



<script>

window.OC_USERS_ADMIN_ID = <?= (int) $adminUserId ?>;

window.OC_USER_ROLES = <?= json_encode(Permission::ROLES, JSON_UNESCAPED_UNICODE) ?>;

</script>

