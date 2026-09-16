(function () {
  let initialized = false;
  let modalReturnFocus = null;
  let usersCache = [];
  let usernameManual = false;
  const adminId = window.OC_USERS_ADMIN_ID || 0;

  function el(id) {
    return document.getElementById(id);
  }

  function csrf() {
    return window.OC_MAKER?.csrf_token || '';
  }

  function apiUrl() {
    return window.OC_MAKER?.api?.adminUsers || '';
  }

  function afterLayout(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
  }

  function showAlert(msg, ok) {
    const alert = el('usersAlert');
    if (!alert) return;
    alert.className = ok
      ? 'alert alert-success users-panel-alert'
      : 'alert alert-error users-panel-alert';
    alert.textContent = msg;
    alert.classList.remove('hidden');
    if (!ok) {
      afterLayout(() => alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    }
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normalizePart(text) {
    return String(text || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, '')
      .trim();
  }

  function nameParts(text) {
    return String(text || '')
      .split(/\s+/)
      .map((p) => normalizePart(p))
      .filter(Boolean);
  }

  function randomSuffix() {
    const len = 2 + Math.floor(Math.random() * 3);
    let out = '';
    for (let i = 0; i < len; i++) out += Math.floor(Math.random() * 10);
    return out;
  }

  function takenUsernames(excludeUserId) {
    return new Set(
      usersCache
        .filter((u) => !excludeUserId || u.id !== excludeUserId)
        .map((u) => (u.username || '').toLowerCase())
    );
  }

  function sanitizeUsername(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/[^a-z0-9._]/g, '')
      .replace(/\.+/g, '.')
      .replace(/^\.|\.$/g, '');
  }

  function pickUsername(value, taken) {
    const s = sanitizeUsername(value);
    if (s.length >= 3 && s.length <= 64 && !taken.has(s)) return s;
    return null;
  }

  function suggestWithSuffix(base, taken) {
    const sanitized = sanitizeUsername(base);
    if (!sanitized) return 'user' + randomSuffix();
    for (let i = 0; i < 25; i++) {
      const candidate = (sanitized + randomSuffix()).slice(0, 64);
      const picked = pickUsername(candidate, taken);
      if (picked) return picked;
    }
    return (sanitized + randomSuffix()).slice(0, 64);
  }

  function suggestUsername(firstName, lastName) {
    const taken = takenUsernames();
    const first = nameParts(firstName);
    const last = nameParts(lastName);
    if (!first.length) return '';

    if (last.length) {
      const dotted = `${first.join('')}.${last.join('.')}`;
      for (const candidate of [
        dotted,
        first.join('') + last.join(''),
        first.join(''),
        last.join(''),
      ]) {
        const picked = pickUsername(candidate, taken);
        if (picked) return picked;
      }
      return suggestWithSuffix(dotted, taken);
    }

    const picked = pickUsername(first.join(''), taken);
    if (picked) return picked;
    return suggestWithSuffix(first.join(''), taken);
  }

  function applyUsernameSuggestion() {
    if (usernameManual) return;
    const name = el('usersCreateName')?.value || '';
    const lastName = el('usersCreateLastName')?.value || '';
    if (!name.trim()) return;
    const field = el('usersCreateUsername');
    if (field) field.value = suggestUsername(name, lastName);
  }

  function validateUsernameField(field, excludeUserId) {
    if (!field) return true;
    const username = sanitizeUsername(field.value);
    field.value = username;
    if (username.length < 3) {
      field.classList.remove('username-field--duplicate');
      return true;
    }

    const taken = takenUsernames(excludeUserId);
    if (!taken.has(username)) {
      field.classList.remove('username-field--duplicate');
      return true;
    }

    const suggestion = suggestWithSuffix(username, taken);
    field.value = suggestion;
    field.classList.remove('username-field--duplicate');
    showAlert(
      `O usuário "${username}" já está em uso. Sugestão aplicada: "${suggestion}".`,
      false,
    );
    return true;
  }

  function bindUsernameAvailability(field, excludeUserIdFn) {
    if (!field) return;
    field.addEventListener('input', () => {
      if (field.id === 'usersCreateUsername') usernameManual = true;
      field.classList.remove('username-field--duplicate');
    });
    field.addEventListener('blur', () => {
      const excludeId = typeof excludeUserIdFn === 'function' ? excludeUserIdFn() : excludeUserIdFn;
      validateUsernameField(field, excludeId);
    });
  }

  function actionIconBtn(action, id, icon, label, extraClass = '') {
    const iconHtml = window.OcIcons?.svg(icon, 18) || '';
    return `<button type="button" class="icon-btn icon-btn--table ${extraClass}" data-action="${action}" data-id="${id}" title="${escapeHtml(label)}" aria-label="${escapeHtml(label)}">${iconHtml}</button>`;
  }

  function accessActionCell(u) {
    const isSelf = u.id === adminId;
    if (isUserActive(u)) {
      return actionIconBtn('deactivate', u.id, 'user-x', 'Remover acesso', isSelf ? 'is-disabled' : '');
    }
    return `<button type="button" class="btn btn-primary btn-sm users-reactivate-btn" data-action="activate" data-id="${u.id}">Reativar</button>`;
  }

  function updateEditAccessControls(user) {
    const badge = el('usersEditAccessBadge');
    const hint = el('usersEditAccessHint');
    const toggle = el('usersEditAccessToggle');
    if (!user || !toggle) return;

    const active = isUserActive(user);
    const isSelf = user.id === adminId;

    if (badge) {
      badge.innerHTML = active
        ? '<span class="users-badge users-badge--ok">Acesso ativo</span>'
        : '<span class="users-badge users-badge--muted">Acesso removido</span>';
    }
    if (hint) {
      hint.textContent = active
        ? 'Este usuário pode entrar no sistema.'
        : 'Este usuário não consegue fazer login até o acesso ser reativado.';
    }

    toggle.textContent = active ? 'Remover acesso' : 'Reativar acesso';
    toggle.dataset.action = active ? 'deactivate' : 'activate';
    toggle.className = active
      ? 'btn btn-secondary btn-sm users-access-toggle'
      : 'btn btn-primary btn-sm users-access-toggle';
    toggle.disabled = isSelf && active;
  }

  async function runAccessAction(action, userId) {
    const labels = {
      deactivate: 'Remover o acesso deste usuário?',
      activate: 'Reativar o acesso deste usuário?',
    };
    if (!window.confirm(labels[action] || 'Confirmar ação?')) return false;

    try {
      await postAction({ action, id: userId });
      showAlert(action === 'activate' ? 'Acesso reativado com sucesso.' : 'Acesso removido.', true);
      await loadUsers();
      const editingId = Number(el('usersEditId')?.value || 0);
      if (editingId === userId) {
        const updated = usersCache.find((u) => u.id === userId);
        if (updated) updateEditAccessControls(updated);
      }
      return true;
    } catch (err) {
      showAlert(err.message);
      return false;
    }
  }

  function avatarCell(u) {
    if (!u.avatar_url) {
      return window.OcIcons?.defaultAvatar('avatar-xs', true)
        || '<span class="user-avatar user-avatar--default avatar-xs"></span>';
    }
    const safeUrl = String(u.avatar_url).replace(/"/g, '&quot;');
    return `<span class="user-avatar-img avatar-xs avatar-ring"><img src="${safeUrl}" alt=""></span>`;
  }

  function roleBadge(role) {
    if (!role) return '<span class="users-badge users-badge--muted">—</span>';
    return `<span class="users-badge users-badge--role">${escapeHtml(role)}</span>`;
  }

  function isUserActive(user) {
    return user?.active === true || user?.active === 1 || user?.active === '1';
  }

  function statusBadges(u) {
    const active = isUserActive(u)
      ? '<span class="users-badge users-badge--ok">Ativo</span>'
      : '<span class="users-badge users-badge--muted">Inativo</span>';
    const totp = u.totp_enabled
      ? '<span class="users-badge users-badge--totp">2FA</span>'
      : '';
    return `${active}${totp}`;
  }

  function updateUserCount() {
    const countEl = el('usersPanelCount');
    if (!countEl) return;
    const n = usersCache.length;
    countEl.textContent = n === 1 ? '1 usuário cadastrado' : `${n} usuários cadastrados`;
  }

  function setEditingRow(userId) {
    document.querySelectorAll('.users-table tbody tr').forEach((row) => {
      row.classList.toggle('is-editing', userId != null && Number(row.dataset.userId) === userId);
    });
  }

  function renderEmptyState() {
    const icon = window.OcIcons?.svg('users', 24) || '';
    return `<div class="users-empty">
      <div class="users-empty-icon">${icon}</div>
      <p>Nenhum usuário cadastrado ainda</p>
      <p class="file-meta">Use o formulário acima para adicionar o primeiro acesso.</p>
    </div>`;
  }

  function renderUsersTable() {
    const wrap = el('usersListWrap');
    if (!wrap) return;
    updateUserCount();
    if (!usersCache.length) {
      wrap.innerHTML = renderEmptyState();
      return;
    }
    const editingId = Number(el('usersEditId')?.value || 0);
    wrap.innerHTML = `<div class="users-table-wrap"><table class="history-table users-table">
      <thead><tr>
        <th></th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Ações</th>
      </tr></thead>
      <tbody>
        ${usersCache.map((u) => {
          const username = u.username ? `@${escapeHtml(u.username)}` : '—';
          const rowClass = [
            editingId === u.id ? 'is-editing' : '',
            !isUserActive(u) ? 'users-table-row--inactive' : '',
          ].filter(Boolean).join(' ');
          return `<tr data-user-id="${u.id}" class="${rowClass}">
            <td class="users-table-avatar">${avatarCell(u)}</td>
            <td class="users-table-name-cell">
              <span class="users-table-display">${escapeHtml(u.display_name || u.name || '—')}</span>
              <span class="users-table-username">${username}</span>
            </td>
            <td class="users-table-email">${escapeHtml(u.email || '—')}</td>
            <td>${roleBadge(u.role)}</td>
            <td><div class="users-table-status">${statusBadges(u)}</div></td>
            <td class="users-actions">
              ${actionIconBtn('edit', u.id, 'edit', 'Editar usuário')}
              ${u.totp_enabled ? actionIconBtn('disable_totp', u.id, 'shield-off', 'Desativar 2FA') : ''}
              ${accessActionCell(u)}
            </td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>`;

    wrap.querySelectorAll('.users-actions .is-disabled').forEach((btn) => {
      btn.disabled = true;
    });
  }

  async function loadUsers() {
    const res = await fetch(apiUrl());
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Falha ao carregar usuários');
    usersCache = data.users || [];
    renderUsersTable();
    applyUsernameSuggestion();
  }

  async function postAction(payload) {
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    Object.entries(payload).forEach(([k, v]) => fd.append(k, String(v)));
    const res = await fetch(apiUrl(), { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Falha na operação');
    return data;
  }

  function openEdit(user) {
    el('usersEditId').value = user.id;
    el('usersEditName').value = user.name || '';
    el('usersEditLastName').value = user.last_name || '';
    el('usersEditUsername').value = user.username || '';
    el('usersEditEmail').value = user.email || '';
    el('usersEditRole').value = user.role || 'comercial';
    el('usersEditPassword').value = '';
    el('usersEditPasswordReveal')?.classList.add('hidden');
    el('usersEditLead').textContent = user.email
      ? `${user.display_name || user.name || user.username || 'Usuário'} · ${user.email}`
      : (user.display_name || user.email || '');
    el('usersEditSection')?.classList.remove('hidden');
    setEditingRow(user.id);
    updateEditAccessControls(user);
    window.OcPassword?.updateChecklist(el('usersEditPasswordRules'), '');
    el('usersAlert')?.classList.add('hidden');
    afterLayout(() => el('usersEditSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

  function closeEdit() {
    el('usersEditSection')?.classList.add('hidden');
    el('usersEditForm')?.reset();
    setEditingRow(null);
    renderUsersTable();
  }

  function resetCreateForm() {
    usernameManual = false;
    el('usersCreateForm')?.reset();
    el('usersCreatePasswordReveal')?.classList.add('hidden');
    if (el('usersCreatePassword')) el('usersCreatePassword').type = 'password';
    window.OcPassword?.updateChecklist(el('usersCreatePasswordRules'), '');
  }

  function bindPasswordFields() {
    const createRules = el('usersCreatePasswordRules');
    const editRules = el('usersEditPasswordRules');
    if (createRules) createRules.innerHTML = window.OcPassword?.checklistHtml() || '';
    if (editRules) editRules.innerHTML = window.OcPassword?.checklistHtml() || '';

    window.OcPassword?.bindPasswordField({
      input: el('usersCreatePassword'),
      toggleBtn: el('usersCreatePasswordToggle'),
      genBtn: el('usersCreatePasswordGen'),
      revealWrap: el('usersCreatePasswordReveal'),
      revealText: el('usersCreatePasswordRevealText'),
      copyBtn: el('usersCreatePasswordCopy'),
      checklistContainer: createRules,
    });

    window.OcPassword?.bindPasswordField({
      input: el('usersEditPassword'),
      toggleBtn: el('usersEditPasswordToggle'),
      genBtn: el('usersEditPasswordGen'),
      revealWrap: el('usersEditPasswordReveal'),
      revealText: el('usersEditPasswordRevealText'),
      copyBtn: el('usersEditPasswordCopy'),
      checklistContainer: editRules,
    });
  }

  function bindEvents() {
    if (initialized) return;
    initialized = true;
    bindPasswordFields();

    const iconWrap = document.querySelector('.users-panel-icon');
    if (iconWrap && window.OcIcons) {
      iconWrap.innerHTML = window.OcIcons.svg('users', 28);
    }

    el('usersModalClose')?.addEventListener('click', close);

    el('usersCreateName')?.addEventListener('input', applyUsernameSuggestion);
    el('usersCreateLastName')?.addEventListener('input', applyUsernameSuggestion);
    bindUsernameAvailability(el('usersCreateUsername'));
    bindUsernameAvailability(el('usersEditUsername'), () => {
      const id = Number(el('usersEditId')?.value || 0);
      return id > 0 ? id : undefined;
    });

    el('usersCreateForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const pwd = el('usersCreatePassword')?.value || '';
      if (!window.OcPassword?.isValid(pwd)) {
        return showAlert('A senha não atende aos requisitos de segurança.');
      }
      validateUsernameField(el('usersCreateUsername'));
      try {
        await postAction({
          action: 'create',
          name: el('usersCreateName')?.value.trim(),
          last_name: el('usersCreateLastName')?.value.trim(),
          username: el('usersCreateUsername')?.value.trim(),
          email: el('usersCreateEmail')?.value.trim(),
          password: pwd,
          role: el('usersCreateRole')?.value,
        });
        resetCreateForm();
        showAlert('Usuário criado. Envie a senha gerada ao usuário — ele deverá alterá-la no primeiro login.', true);
        await loadUsers();
      } catch (err) {
        showAlert(err.message);
      }
    });

    el('usersEditForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = el('usersEditId')?.value;
      const pwd = el('usersEditPassword')?.value.trim();
      if (pwd && !window.OcPassword?.isValid(pwd)) {
        return showAlert('A nova senha não atende aos requisitos de segurança.');
      }
      validateUsernameField(el('usersEditUsername'), Number(id || 0));
      try {
        const payload = {
          action: 'update',
          id,
          name: el('usersEditName')?.value.trim(),
          last_name: el('usersEditLastName')?.value.trim(),
          username: el('usersEditUsername')?.value.trim(),
          email: el('usersEditEmail')?.value.trim(),
          role: el('usersEditRole')?.value,
        };
        if (pwd) payload.password = pwd;
        await postAction(payload);
        showAlert('Usuário atualizado.', true);
        closeEdit();
        await loadUsers();
      } catch (err) {
        showAlert(err.message);
      }
    });

    el('usersEditCancel')?.addEventListener('click', closeEdit);

    el('usersEditAccessToggle')?.addEventListener('click', async () => {
      const userId = Number(el('usersEditId')?.value || 0);
      const action = el('usersEditAccessToggle')?.dataset.action;
      if (userId <= 0 || !action) return;
      await runAccessAction(action, userId);
    });

    el('usersListWrap')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action]');
      if (!btn || btn.disabled) return;
      const id = Number(btn.dataset.id);
      const action = btn.dataset.action;
      const user = usersCache.find((u) => u.id === id);
      if (!user) return;

      if (action === 'edit') {
        openEdit(user);
        return;
      }

      if (action === 'activate' || action === 'deactivate') {
        await runAccessAction(action, id);
        return;
      }

      const labels = {
        disable_totp: 'Desativar o 2FA deste usuário?',
      };
      if (!window.confirm(labels[action] || 'Confirmar ação?')) return;

      try {
        await postAction({ action, id });
        showAlert('Operação concluída.', true);
        await loadUsers();
      } catch (err) {
        showAlert(err.message);
      }
    });
  }

  function isFocusableOutsideModal(node, modal) {
    if (!(node instanceof HTMLElement)) return false;
    if (!document.contains(node)) return false;
    if (modal?.contains(node)) return false;
    if (node.id === 'usersModalClose') return false;
    if (node.closest('.hidden')) return false;
    return true;
  }

  function restoreFocusAfterModal() {
    const modal = el('usersModal');
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

  function open() {
    if (document.body.classList.contains('force-password-open')) return;
    const modal = el('usersModal');
    if (!modal) {
      window.location.href = window.OC_MAKER?.urls?.adminUsers || 'admin/users.php';
      return;
    }
    const active = document.activeElement;
    const toggle = el('userMenuToggle');
    modalReturnFocus = isFocusableOutsideModal(active, modal) ? active : toggle;
    bindEvents();
    closeEdit();
    resetCreateForm();
    el('usersAlert')?.classList.add('hidden');
    loadUsers().catch((err) => showAlert(err.message));
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('account-modal-open');
    afterLayout(() => el('usersModalClose')?.focus());
  }

  function close() {
    const modal = el('usersModal');
    if (!modal) return;
    closeEdit();
    restoreFocusAfterModal();
    if (document.activeElement instanceof HTMLElement && modal.contains(document.activeElement)) {
      el('userMenuToggle')?.focus({ preventScroll: true });
    }
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('account-modal-open');
    el('usersAlert')?.classList.add('hidden');
  }

  function init() {
    bindEvents();
    if (new URLSearchParams(window.location.search).get('users') === '1') {
      open();
      const url = new URL(window.location.href);
      url.searchParams.delete('users');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }
  }

  window.OcUsers = { open, close, init };
})();
