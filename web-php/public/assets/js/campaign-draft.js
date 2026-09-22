(function () {
  const PREFIX = 'oc_maker_draft_';

  function storageKey(type, documentId, subKey) {
    return PREFIX + type + '_' + documentId + (subKey ? '_' + subKey : '');
  }

  window.OcCampaignDraft = {
    save(type, documentId, data, subKey) {
      if (!documentId || !type) return;
      try {
        localStorage.setItem(storageKey(type, documentId, subKey), JSON.stringify({
          savedAt: Date.now(),
          data,
        }));
      } catch {
        /* quota exceeded — ignore */
      }
    },

    load(type, documentId, subKey) {
      try {
        const raw = localStorage.getItem(storageKey(type, documentId, subKey));
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
      } catch {
        return null;
      }
    },

    clear(type, documentId, subKey) {
      localStorage.removeItem(storageKey(type, documentId, subKey));
    },

    restoreIfConfirmed(type, documentId, subKey) {
      const draft = this.load(type, documentId, subKey);
      if (!draft?.data) return null;
      const when = draft.savedAt ? new Date(draft.savedAt).toLocaleString('pt-BR') : 'sessão anterior';
      if (!window.confirm(`Encontramos um rascunho local (${when}). Deseja restaurar?`)) {
        return null;
      }
      return draft.data;
    },

    scheduleSave(type, documentId, getData, subKey, delayMs = 2000) {
      const timerKey = storageKey(type, documentId, subKey) + '_timer';
      if (window[timerKey]) clearTimeout(window[timerKey]);
      window[timerKey] = setTimeout(() => {
        this.save(type, documentId, getData(), subKey);
      }, delayMs);
    },
  };
})();
