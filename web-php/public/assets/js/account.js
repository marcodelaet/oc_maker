(function () {
  let currentUser = null;
  let pendingSecret = '';
  let initialized = false;
  let phoneMenuPosFrame = 0;
  let modalReturnFocus = null;

  function el(id) {
    return document.getElementById(id);
  }

  /** Evita reflow síncrono: lê layout no próximo frame, após o browser aplicar mudanças no DOM. */
  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function api() {
    return window.OC_MAKER?.api || {};
  }

  function csrf() {
    return window.OC_MAKER?.csrf_token || '';
  }

  function renderAvatarHtml(user, sizeClass, withRing, avatarUrlOverride) {
    const avatarUrl = avatarUrlOverride || user?.avatar_url;
    if (!avatarUrl) {
      return window.OcIcons?.defaultAvatar(sizeClass, withRing)
        || `<span class="user-avatar user-avatar--default ${sizeClass}"></span>`;
    }
    const ring = withRing ? ' avatar-ring' : '';
    const safeUrl = String(avatarUrl).replace(/"/g, '&quot;');
    return `<span class="user-avatar-img ${sizeClass}${ring}"><img src="${safeUrl}" alt=""></span>`;
  }

  function avatarCacheUrl(url) {
    if (!url) return '';
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}t=${Date.now()}`;
  }

  function showErr(msg) {
    const alert = el('accountAlert');
    if (!alert) return;
    alert.className = 'alert alert-error account-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
    afterLayout(() => {
      alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  function showOk(msg) {
    const alert = el('accountAlert');
    if (!alert) return;
    alert.className = 'alert alert-success account-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
  }

  function updateHeroAvatar(user) {
    const hero = el('accountHeroAvatar');
    if (hero) hero.innerHTML = renderAvatarHtml(user, 'avatar-xl', true);
  }

  function updateHeroMeta(user) {
    const name = [user.name, user.last_name].filter(Boolean).join(' ').trim()
      || user.display_name || user.name || '';
    const nameEl = el('accountHeroDisplayName');
    const emailEl = el('accountHeroEmail');
    if (nameEl) nameEl.textContent = name;
    if (emailEl) emailEl.textContent = user.email || '';
  }

  function syncPhoneCountryUI(dial) {
    const select = el('accountPhoneCountry');
    const flag = el('accountPhoneFlag');
    const dialEl = el('accountPhoneDial');
    const menu = el('accountPhoneCountryMenu');
    if (!select) return;
    if (dial) select.value = dial;
    const opt = select.options[select.selectedIndex];
    const code = opt?.dataset?.code || 'br';
    if (flag) flag.src = `https://flagcdn.com/w40/${code}.png`;
    if (dialEl) dialEl.textContent = select.value;
    menu?.querySelectorAll('.phone-country-option').forEach((btn) => {
      btn.setAttribute('aria-selected', btn.dataset.dial === select.value ? 'true' : 'false');
    });
  }

  function positionPhoneCountryMenu() {
    const btn = el('accountPhoneCountryBtn');
    const menu = el('accountPhoneCountryMenu');
    if (!btn || !menu) return;
    const rect = btn.getBoundingClientRect();
    menu.style.top = `${rect.bottom + 4}px`;
    menu.style.left = `${rect.left}px`;
    menu.style.width = `${Math.max(rect.width, 168)}px`;
  }

  function closePhoneCountryMenu() {
    const btn = el('accountPhoneCountryBtn');
    const menu = el('accountPhoneCountryMenu');
    if (!btn || !menu) return;
    menu.classList.add('hidden');
    btn.setAttribute('aria-expanded', 'false');
  }

  function schedulePhoneCountryMenuPosition() {
    if (phoneMenuPosFrame) return;
    phoneMenuPosFrame = requestAnimationFrame(() => {
      phoneMenuPosFrame = 0;
      const menu = el('accountPhoneCountryMenu');
      if (menu && !menu.classList.contains('hidden')) positionPhoneCountryMenu();
    });
  }

  function togglePhoneCountryMenu() {
    const btn = el('accountPhoneCountryBtn');
    const menu = el('accountPhoneCountryMenu');
    if (!btn || !menu) return;
    if (menu.classList.contains('hidden')) {
      menu.classList.remove('hidden');
      btn.setAttribute('aria-expanded', 'true');
      schedulePhoneCountryMenuPosition();
    } else {
      closePhoneCountryMenu();
    }
  }

  function bindPhoneCountryPicker() {
    const picker = el('accountPhoneCountryPicker');
    const btn = el('accountPhoneCountryBtn');
    const menu = el('accountPhoneCountryMenu');
    const select = el('accountPhoneCountry');
    if (!picker || !btn || !menu || !select) return;

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      togglePhoneCountryMenu();
    });

    menu.querySelectorAll('.phone-country-option').forEach((opt) => {
      opt.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        select.value = opt.dataset.dial || '+55';
        syncPhoneCountryUI();
        closePhoneCountryMenu();
      });
    });

    document.addEventListener('click', (e) => {
      if (!picker.contains(e.target) && !menu.contains(e.target)) {
        closePhoneCountryMenu();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closePhoneCountryMenu();
    });

    window.addEventListener('resize', schedulePhoneCountryMenuPosition);

    el('accountModal')?.querySelector('.account-panel-card')?.addEventListener('scroll', schedulePhoneCountryMenuPosition, { passive: true });
  }

  function fillForm(user) {
    if (!user) return;
    if (el('accountFirstName')) el('accountFirstName').value = user.name || '';
    if (el('accountLastName')) el('accountLastName').value = user.last_name || '';
    if (el('accountBirthDate')) el('accountBirthDate').value = user.birth_date || '';
    if (el('accountUsername')) el('accountUsername').value = user.username || '';
    if (el('accountEmail')) el('accountEmail').value = user.email || '';
    if (el('accountPhoneCountry')) {
      el('accountPhoneCountry').value = user.phone_country_code || '+55';
      syncPhoneCountryUI();
    }
    if (el('accountPhone')) el('accountPhone').value = user.phone || '';
    updateHeroAvatar(user);
    updateHeroMeta(user);
  }

  function syncAuthUser(user) {
    currentUser = user;
    fillForm(user);
    if (typeof window.ocUpdateAuthUser === 'function') {
      window.ocUpdateAuthUser(user);
    }
  }

  function isFocusableOutsideModal(node, modal) {
    if (!(node instanceof HTMLElement)) return false;
    if (!document.contains(node)) return false;
    if (modal?.contains(node)) return false;
    if (node.id === 'accountModalClose') return false;
    if (node.closest('.hidden')) return false;
    return true;
  }

  function restoreFocusAfterModal() {
    const modal = el('accountModal');
    const candidates = [modalReturnFocus, el('userMenuToggle')];
    modalReturnFocus = null;
    for (const node of candidates) {
      if (!isFocusableOutsideModal(node, modal)) continue;
      node.focus({ preventScroll: true });
      if (!modal?.contains(document.activeElement)) return;
    }
    const toggle = el('userMenuToggle');
    if (isFocusableOutsideModal(toggle, modal)) {
      toggle.focus({ preventScroll: true });
    }
  }

  function bindPasswordPolicy() {
    const rules = el('accountNewPasswordRules');
    if (!rules || !window.OcPassword) return;
    rules.innerHTML = window.OcPassword.checklistHtml();
    window.OcPassword.bindToggle(el('accountCurrentPassword'), el('accountCurrentPasswordToggle'));
    window.OcPassword.bindToggle(el('accountNewPassword'), el('accountNewPasswordToggle'));
    window.OcPassword.bindChecklist(el('accountNewPassword'), rules);
  }

  function open() {
    if (document.body.classList.contains('force-password-open')) return;
    const modal = el('accountModal');
    if (!modal) {
      window.location.href = window.OC_MAKER?.urls?.account || 'account.php';
      return;
    }
    const active = document.activeElement;
    const toggle = el('userMenuToggle');
    modalReturnFocus = isFocusableOutsideModal(active, modal) ? active : toggle;
    if (window.OC_MAKER?.accountUser) {
      currentUser = window.OC_MAKER.accountUser;
      fillForm(currentUser);
    }
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
    afterLayout(() => el('accountModalClose')?.focus());
  }

  function close() {
    const modal = el('accountModal');
    if (!modal) return;
    closePhoneCountryMenu();
    restoreFocusAfterModal();
    if (document.activeElement instanceof HTMLElement && modal.contains(document.activeElement)) {
      el('userMenuToggle')?.focus({ preventScroll: true });
    }
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    const alert = el('accountAlert');
    if (alert) alert.classList.add('hidden');
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;

    el('accountModalClose')?.addEventListener('click', close);
    bindPhoneCountryPicker();
    bindPasswordPolicy();

    el('accountAvatarPicker')?.addEventListener('click', () => {
      el('accountAvatarInput')?.click();
    });

    el('accountAvatarInput')?.addEventListener('change', async () => {
      const file = el('accountAvatarInput')?.files?.[0];
      if (!file) return;
      const previewUrl = URL.createObjectURL(file);
      const hero = el('accountHeroAvatar');
      if (hero) hero.innerHTML = renderAvatarHtml(currentUser, 'avatar-xl', true, previewUrl);
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('avatar', file);
      try {
        const res = await fetch(api().authAvatar, {
          method: 'POST',
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Falha no upload');
        if (!data.avatar_url) throw new Error('Upload concluído, mas a URL da foto não foi retornada.');
        const nextUser = {
          ...(currentUser || {}),
          avatar_url: avatarCacheUrl(data.avatar_url),
        };
        if (window.OC_MAKER?.accountUser) {
          window.OC_MAKER.accountUser = { ...window.OC_MAKER.accountUser, avatar_url: nextUser.avatar_url };
        }
        syncAuthUser(nextUser);
        showOk('Foto de perfil atualizada.');
        URL.revokeObjectURL(previewUrl);
      } catch (err) {
        if (currentUser) updateHeroAvatar(currentUser);
        showErr(err.message);
        URL.revokeObjectURL(previewUrl);
      } finally {
        if (el('accountAvatarInput')) el('accountAvatarInput').value = '';
      }
    });

    el('accountSaveProfileBtn')?.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('name', el('accountFirstName')?.value.trim() || '');
      fd.append('last_name', el('accountLastName')?.value.trim() || '');
      fd.append('birth_date', el('accountBirthDate')?.value || '');
      fd.append('username', el('accountUsername')?.value.trim() || '');
      fd.append('email', el('accountEmail')?.value.trim() || '');
      fd.append('phone_country_code', el('accountPhoneCountry')?.value || '+55');
      fd.append('phone', el('accountPhone')?.value.trim() || '');
      try {
        const res = await fetch(api().authProfile, {
          method: 'POST',
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Falha ao salvar');
        syncAuthUser(data.user);
        if (window.OC_MAKER) window.OC_MAKER.accountUser = data.user;
        showOk('Informações salvas com sucesso.');
      } catch (err) {
        showErr(err.message);
      }
    });

    el('accountPasswordForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const newPwd = el('accountNewPassword')?.value || '';
      if (!window.OcPassword?.isValid(newPwd)) {
        return showErr('A nova senha não atende aos requisitos de segurança.');
      }
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('current_password', el('accountCurrentPassword')?.value || '');
      fd.append('new_password', newPwd);
      const res = await fetch(api().authPassword, {
        method: 'POST',
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) return showErr(data.error);
      showOk('Senha alterada com sucesso.');
      e.target.reset();
      window.OcPassword?.updateChecklist(el('accountNewPasswordRules'), '');
    });

    el('accountSetupTotpBtn')?.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      const res = await fetch(api().authTotpSetup, {
        method: 'POST',
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) return showErr(data.error);
      pendingSecret = data.secret;
      if (el('accountTotpSecret')) el('accountTotpSecret').textContent = data.secret;
      el('accountTotpSetup')?.classList.remove('hidden');
      el('accountSetupTotpBtn')?.classList.add('hidden');
      try {
        window.OcTotpSetup.renderQr(el('accountTotpQr'), data.uri);
      } catch (err) {
        showErr(err.message);
      }
      el('accountTotpCode')?.focus();
    });

    el('accountCopySecretBtn')?.addEventListener('click', async () => {
      if (!pendingSecret) return;
      try {
        await window.OcTotpSetup.copyText(pendingSecret);
        showOk('Chave copiada.');
      } catch {
        showErr('Não foi possível copiar automaticamente.');
      }
    });

    el('accountConfirmTotpBtn')?.addEventListener('click', async () => {
      const code = el('accountTotpCode')?.value.trim() || '';
      if (code.length !== 6) return showErr('Informe o código de 6 dígitos do autenticador.');
      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('secret', pendingSecret);
      fd.append('code', code);
      const res = await fetch(api().authTotpConfirm, {
        method: 'POST',
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) return showErr(data.error);
      window.location.reload();
    });
  }

  function init(user) {
    if (user) currentUser = user;
    bindEvents();
    if (currentUser) fillForm(currentUser);
    if (new URLSearchParams(window.location.search).get('account') === '1') {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('account');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  window.OcAccount = { open, close, init, syncUser: syncAuthUser };
})();
