<article class="page-card force-password-card">

  <header class="page-card-hero page-card-hero--force">

    <div class="force-password-icon" aria-hidden="true"></div>

    <div class="page-card-hero-text">

      <span class="force-password-kicker">Primeiro acesso</span>

      <h1 id="forcePasswordTitle">Defina sua nova senha</h1>

      <p class="section-lead">Por segurança, altere a senha temporária antes de continuar usando o sistema.</p>

      <p class="force-password-greeting hidden" id="forcePasswordGreeting"></p>

    </div>

  </header>



  <div id="forcePasswordAlert" class="alert alert-error hidden" role="alert"></div>



  <form id="forcePasswordForm" class="force-password-form stack-form profile-form" autocomplete="off">

    <input type="text" name="username" id="forcePasswordUsername" autocomplete="username" tabindex="-1" aria-hidden="true" class="field-sr-only">



    <div class="force-password-body">

      <div class="force-password-fields">

        <label>Nova senha <span class="req">*</span>

          <div class="password-input-wrap">

            <input type="password" id="forcePasswordNew" required autocomplete="new-password" placeholder="Digite sua nova senha">

            <button type="button" class="password-toggle-btn" id="forcePasswordNewToggle" aria-label="Mostrar senha"></button>

          </div>

        </label>

        <label>Confirmar nova senha <span class="req">*</span>

          <div class="password-input-wrap">

            <input type="password" id="forcePasswordConfirm" required autocomplete="new-password" placeholder="Repita a nova senha">

            <button type="button" class="password-toggle-btn" id="forcePasswordConfirmToggle" aria-label="Mostrar senha"></button>

          </div>

        </label>

        <ul class="password-rules password-match" id="forcePasswordMatch" aria-live="polite">

          <li class="password-rule password-rule--pending" data-rule="match">

            <span class="password-rule-icon" aria-hidden="true">○</span>

            <span>As senhas coincidem</span>

          </li>

        </ul>

      </div>



      <aside class="force-password-requirements" aria-labelledby="forcePasswordRulesTitle">

        <h2 id="forcePasswordRulesTitle" class="force-password-requirements-title">Requisitos da senha</h2>

        <div id="forcePasswordRules"></div>

      </aside>

    </div>



    <div class="force-password-actions">

      <button type="submit" class="btn btn-primary btn-block" id="forcePasswordSubmit" disabled>Alterar senha e continuar</button>

    </div>

  </form>

</article>

