<article class="page-card login-panel-card">

  <header class="page-card-hero page-card-hero--login">

    <div class="login-panel-icon" aria-hidden="true"></div>

    <div class="page-card-hero-text">

      <span class="login-panel-kicker">Acesso</span>

      <h1 id="loginModalTitle">Entrar</h1>

      <p class="section-lead">Acesso opcional para equipe logada. Comercial pode usar o sistema sem login.</p>

    </div>

  </header>



  <div id="loginAlert" class="alert alert-error hidden login-panel-alert" role="alert"></div>



  <section class="login-section">

    <form id="loginForm" class="stack-form">

      <label for="loginIdentifier">Usuário, e-mail ou telefone

        <input type="text" id="loginIdentifier" required autocomplete="username" placeholder="usuário, e-mail ou +55…">

      </label>

      <label for="loginPassword">Senha

        <input type="password" id="loginPassword" required autocomplete="current-password" placeholder="••••••••">

      </label>

      <label id="loginTotpGroup" class="hidden" for="loginTotp">Código do autenticador

        <input type="text" id="loginTotp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" class="totp-code-input">

      </label>

      <div class="section-actions section-actions--split">

        <button type="submit" class="btn btn-primary" id="loginSubmitBtn">Entrar</button>

      </div>

    </form>

  </section>

</article>
