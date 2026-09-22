(function () {
  let initialized = false;
  let modalReturnFocus = null;
  /** @type {null | (() => void | Promise<void>)} */
  let pendingAfterLogin = null;

  function el(id) {
    return document.getElementById(id);
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.authLogin || '';
  }

  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function isLoggedIn() {
    return !!(window.OC_MAKER?.accountUser || window.ocIsLoggedIn?.());
  }

  function showAlert(msg, ok) {
    const alert = el('loginAlert');
    if (!alert) return;
    alert.className = ok
      ? 'alert alert-success login-panel-alert'
      : 'alert alert-error login-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
  }

  function hideAlert() {
    el('loginAlert')?.classList.add('hidden');
  }

  function resetForm() {
    const form = el('loginForm');
    form?.reset();
    el('loginTotpGroup')?.classList.add('hidden');
    if (el('loginTotp')) el('loginTotp').value = '';
  }

  function initPanelIcon() {
    const iconWrap = document.querySelector('.login-panel-icon');
    if (iconWrap && window.OcIcons) {
      iconWrap.innerHTML = window.OcIcons.svg('login', 28);
    }
    const headerBtn = el('loginLink');
    if (headerBtn && window.OcIcons && !headerBtn.querySelector('svg')) {
      headerBtn.innerHTML = window.OcIcons.svg('login', 20);
    }
  }

  async function submitLogin(e) {
    e.preventDefault();
    hideAlert();

    const fd = new FormData();
    fd.append('login', el('loginIdentifier')?.value.trim() || '');
    fd.append('password', el('loginPassword')?.value || '');
    const totp = el('loginTotp')?.value.trim() || '';
    if (totp) fd.append('totp', totp);

    const submitBtn = el('loginSubmitBtn');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(apiUrl(), { method: 'POST', body: fd });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Falha no login');

      if (data.requires_totp) {
        el('loginTotpGroup')?.classList.remove('hidden');
        showAlert('Informe o código do autenticador.');
        afterLayout(() => el('loginTotp')?.focus());
        return;
      }

      if (data.csrf_token && window.OC_MAKER) {
        window.OC_MAKER.csrf_token = data.csrf_token;
      }

      if (data.user && window.ocUpdateAuthUser) {
        window.ocUpdateAuthUser(data.user);
      }
      if (data.csrf_token && window.ocMakerCsrfToken) {
        window.ocMakerCsrfToken(data.csrf_token);
      }

      close();

      if (pendingAfterLogin) {
        const next = pendingAfterLogin;
        pendingAfterLogin = null;
        try {
          await window.ocLoadAuth?.();
        } catch {
          /* ignore refresh errors */
        }
        await next();
        return;
      }

      window.location.assign(window.OC_MAKER?.urls?.home || 'index.php');
    } catch (err) {
      showAlert(err.message);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;

    initPanelIcon();
    el('loginModalClose')?.addEventListener('click', close);
    el('loginForm')?.addEventListener('submit', submitLogin);
  }

  function isFocusableOutsideModal(node, modal) {
    if (!(node instanceof HTMLElement)) return false;
    if (!document.contains(node)) return false;
    if (modal?.contains(node)) return false;
    if (node.id === 'loginModalClose') return false;
    if (node.closest('.hidden')) return false;
    return true;
  }

  function restoreFocusAfterModal() {
    const modal = el('loginModal');
    const candidates = [modalReturnFocus, el('loginLink')];
    modalReturnFocus = null;
    for (const node of candidates) {
      if (!isFocusableOutsideModal(node, modal)) continue;
      node.focus({ preventScroll: true });
      if (!modal?.contains(document.activeElement)) return;
    }
  }

  function open(options = {}) {
    if (isLoggedIn()) {
      if (typeof options.onSuccess === 'function') {
        Promise.resolve(options.onSuccess()).catch(() => {});
      }
      return;
    }
    if (document.body.classList.contains('force-password-open')) return;

    pendingAfterLogin = typeof options.onSuccess === 'function' ? options.onSuccess : null;

    const modal = el('loginModal');
    if (!modal) {
      window.location.href = window.OC_MAKER?.urls?.login || 'login.php';
      return;
    }

    const active = document.activeElement;
    modalReturnFocus = isFocusableOutsideModal(active, modal) ? active : el('loginLink');

    bindEvents();
    hideAlert();
    resetForm();

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
    afterLayout(() => el('loginIdentifier')?.focus());
  }

  function close() {
    const modal = el('loginModal');
    if (!modal) return;
    pendingAfterLogin = null;
    resetForm();
    restoreFocusAfterModal();
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    hideAlert();
  }

  function init() {
    initPanelIcon();
    bindEvents();
    if (new URLSearchParams(window.location.search).get('login') === '1' && !isLoggedIn()) {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('login');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  window.OcLogin = { open, close, init };
})();
