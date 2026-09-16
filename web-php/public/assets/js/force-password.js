(function () {
  let initialized = false;

  function el(id) {
    return document.getElementById(id);
  }

  function csrf() {
    return window.OC_MAKER?.csrf_token || '';
  }

  function refreshSubmitState() {
    const pwd = el('forcePasswordNew')?.value || '';
    const confirm = el('forcePasswordConfirm')?.value || '';
    const ok = window.OcPassword?.isValid(pwd) && pwd !== '' && pwd === confirm;
    const btn = el('forcePasswordSubmit');
    if (btn) btn.disabled = !ok;
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;

    const rules = el('forcePasswordRules');
    if (rules) rules.innerHTML = window.OcPassword?.checklistHtml() || '';
    window.OcPassword?.bindChecklist(el('forcePasswordNew'), rules);
    window.OcPassword?.bindMatch(el('forcePasswordNew'), el('forcePasswordConfirm'), el('forcePasswordMatch'));
    window.OcPassword?.bindToggle(el('forcePasswordNew'), el('forcePasswordNewToggle'));
    window.OcPassword?.bindToggle(el('forcePasswordConfirm'), el('forcePasswordConfirmToggle'));

    const iconWrap = document.querySelector('.force-password-icon');
    if (iconWrap && window.OcIcons) {
      iconWrap.innerHTML = window.OcIcons.svg('lock', 28);
    }

    el('forcePasswordNew')?.addEventListener('input', refreshSubmitState);
    el('forcePasswordConfirm')?.addEventListener('input', refreshSubmitState);

    el('forcePasswordForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const pwd = el('forcePasswordNew')?.value || '';
      const confirm = el('forcePasswordConfirm')?.value || '';
      if (!window.OcPassword?.isValid(pwd) || pwd !== confirm) return;

      const fd = new FormData();
      fd.append('csrf_token', csrf());
      fd.append('force', '1');
      fd.append('new_password', pwd);
      fd.append('confirm_password', confirm);

      try {
        const res = await fetch(window.OC_MAKER?.api?.authPassword, {
          method: 'POST',
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Falha ao alterar senha');
        if (data.user) {
          window.OC_MAKER.accountUser = data.user;
          if (typeof window.ocUpdateAuthUser === 'function') {
            window.ocUpdateAuthUser(data.user);
          }
          if (window.OcAccount) window.OcAccount.syncUser(data.user);
        }
        close();
      } catch (err) {
        const alert = el('forcePasswordAlert');
        if (alert) {
          alert.textContent = err.message;
          alert.classList.remove('hidden');
        }
      }
    });
  }

  function open(user) {
    const modal = el('forcePasswordModal');
    if (!modal) return;
    bindEvents();
    const username = user?.username || user?.email || '';
    if (el('forcePasswordUsername')) el('forcePasswordUsername').value = username;
    const greeting = el('forcePasswordGreeting');
    const displayName = user?.display_name || user?.name || '';
    if (greeting) {
      if (displayName) {
        greeting.textContent = `Olá, ${displayName}!`;
        greeting.classList.remove('hidden');
      } else {
        greeting.textContent = '';
        greeting.classList.add('hidden');
      }
    }
    el('forcePasswordForm')?.reset();
    el('forcePasswordAlert')?.classList.add('hidden');
    window.OcPassword?.updateChecklist(el('forcePasswordRules'), '');
    el('forcePasswordNew')?.dispatchEvent(new Event('input', { bubbles: true }));
    el('forcePasswordConfirm')?.dispatchEvent(new Event('input', { bubbles: true }));
    refreshSubmitState();
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('force-password-open');
    el('forcePasswordNew')?.focus();
  }

  function close() {
    const modal = el('forcePasswordModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('force-password-open');
  }

  function maybeOpen(user) {
    if (user?.must_change_password) open(user);
  }

  window.OcForcePassword = { open, close, maybeOpen, init: bindEvents };
})();
