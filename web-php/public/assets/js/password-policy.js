(function () {
  const RULES = [
    { id: 'length', label: 'Mínimo 8 caracteres' },
    { id: 'upper', label: 'Letra maiúscula' },
    { id: 'lower', label: 'Letra minúscula' },
    { id: 'digit', label: 'Número' },
    { id: 'special', label: 'Caractere especial' },
    { id: 'nospace', label: 'Sem espaços em branco' },
  ];

  function check(password) {
    const value = String(password || '');
    return {
      length: value.length >= 8,
      upper: /[A-Z]/.test(value),
      lower: /[a-z]/.test(value),
      digit: /\d/.test(value),
      special: /[^A-Za-z0-9\s]/.test(value),
      nospace: !/\s/.test(value),
    };
  }

  function isValid(password) {
    return Object.values(check(password)).every(Boolean);
  }

  function generate(length = 8) {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghjkmnpqrstuvwxyz';
    const digits = '23456789';
    const special = '!@#$%&*-_+=';
    const all = upper + lower + digits + special;
    const chars = [
      upper[Math.floor(Math.random() * upper.length)],
      lower[Math.floor(Math.random() * lower.length)],
      digits[Math.floor(Math.random() * digits.length)],
      special[Math.floor(Math.random() * special.length)],
    ];
    for (let i = chars.length; i < length; i++) {
      chars.push(all[Math.floor(Math.random() * all.length)]);
    }
    for (let i = chars.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
  }

  function checklistHtml() {
    return `<ul class="password-rules" aria-live="polite">
      ${RULES.map((r) => `<li class="password-rule password-rule--pending" data-rule="${r.id}"><span class="password-rule-icon" aria-hidden="true">○</span><span>${r.label}</span></li>`).join('')}
    </ul>`;
  }

  function updateChecklist(container, password) {
    if (!container) return false;
    const empty = !String(password || '').length;
    const results = check(password);
    container.querySelectorAll('.password-rule').forEach((item) => {
      const id = item.dataset.rule;
      const ok = !!results[id];
      item.classList.remove('password-rule--ok', 'password-rule--fail', 'password-rule--pending');
      const icon = item.querySelector('.password-rule-icon');
      if (empty) {
        item.classList.add('password-rule--pending');
        if (icon) icon.textContent = '○';
      } else if (ok) {
        item.classList.add('password-rule--ok');
        if (icon) icon.textContent = '✓';
      } else {
        item.classList.add('password-rule--fail');
        if (icon) icon.textContent = '✕';
      }
    });
    return isValid(password);
  }

  function bindChecklist(input, checklistContainer) {
    if (!input || !checklistContainer) return;
    const refresh = () => updateChecklist(checklistContainer, input.value);
    input.addEventListener('input', refresh);
    input.addEventListener('change', refresh);
    refresh();
  }

  function bindToggle(input, toggleBtn) {
    if (!input || !toggleBtn) return;
    let visible = false;
    const refreshIcon = () => {
      if (window.OcIcons) {
        toggleBtn.innerHTML = window.OcIcons.svg(visible ? 'eye-off' : 'eye', 18);
      }
      toggleBtn.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
    };
    refreshIcon();
    toggleBtn.addEventListener('click', () => {
      visible = !visible;
      input.type = visible ? 'text' : 'password';
      refreshIcon();
    });
  }

  async function copyText(text) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  function bindCopyButton(button, getText) {
    if (!button) return;
    if (window.OcIcons) button.innerHTML = window.OcIcons.svg('copy', 18);
    button.addEventListener('click', async () => {
      const text = typeof getText === 'function' ? getText() : '';
      if (!text) return;
      try {
        await copyText(text);
        const prev = button.title;
        button.title = 'Copiado!';
        setTimeout(() => { button.title = prev || 'Copiar senha'; }, 1500);
      } catch {
        /* ignore */
      }
    });
  }

  function bindPasswordField(options) {
    const {
      input,
      toggleBtn,
      genBtn,
      revealWrap,
      revealText,
      copyBtn,
      checklistContainer,
    } = options;
    if (checklistContainer) bindChecklist(input, checklistContainer);
    bindToggle(input, toggleBtn);
    if (genBtn) {
      genBtn.addEventListener('click', () => {
        const pwd = generate(8);
        input.value = pwd;
        input.type = 'password';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (revealWrap && revealText) {
          revealText.textContent = pwd;
          revealWrap.classList.remove('hidden');
        }
        if (toggleBtn && window.OcIcons) {
          toggleBtn.innerHTML = window.OcIcons.svg('eye', 18);
          toggleBtn.setAttribute('aria-label', 'Mostrar senha');
        }
      });
    }
    bindCopyButton(copyBtn, () => revealText?.textContent || input?.value || '');
  }

  function bindGenerator(button, input, revealEl) {
    if (!button || !input) return;
    button.addEventListener('click', () => {
      const pwd = generate(8);
      input.value = pwd;
      input.type = 'password';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      if (revealEl) {
        if (revealEl.classList?.contains('password-generated-row')) {
          const textEl = revealEl.querySelector('.password-generated');
          if (textEl) textEl.textContent = pwd;
          revealEl.classList.remove('hidden');
        } else {
          revealEl.textContent = pwd;
          revealEl.classList.remove('hidden');
        }
      }
    });
  }

  function bindMatch(input, confirmInput, matchEl) {
    if (!input || !confirmInput || !matchEl) return;
    const rule = matchEl.querySelector('.password-rule') || matchEl;
    const refresh = () => {
      const confirmVal = confirmInput.value;
      const empty = confirmVal === '';
      const ok = !empty && input.value === confirmVal;
      matchEl.classList.remove('password-match--ok', 'password-match--fail', 'password-match--pending');
      rule.classList.remove('password-rule--ok', 'password-rule--fail', 'password-rule--pending');
      const icon = matchEl.querySelector('.password-rule-icon');
      if (empty) {
        matchEl.classList.add('password-match--pending');
        rule.classList.add('password-rule--pending');
        if (icon) icon.textContent = '○';
      } else if (ok) {
        matchEl.classList.add('password-match--ok');
        rule.classList.add('password-rule--ok');
        if (icon) icon.textContent = '✓';
      } else {
        matchEl.classList.add('password-match--fail');
        rule.classList.add('password-rule--fail');
        if (icon) icon.textContent = '✕';
      }
    };
    input.addEventListener('input', refresh);
    confirmInput.addEventListener('input', refresh);
    refresh();
  }

  window.OcPassword = {
    RULES,
    check,
    isValid,
    generate,
    checklistHtml,
    updateChecklist,
    bindChecklist,
    bindGenerator,
    bindPasswordField,
    bindToggle,
    bindCopyButton,
    copyText,
    bindMatch,
  };
})();
