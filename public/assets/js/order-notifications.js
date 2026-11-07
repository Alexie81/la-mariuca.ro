(() => {
  'use strict';

  const dialog = document.querySelector('[data-order-notifications-dialog]');
  const content = dialog?.querySelector('[data-order-notifications-content]');
  const initialMarkup = content?.innerHTML || '';
  const pageForm = document.querySelector('.order-notification-page [data-order-notifications-form]');
  if (!dialog && !pageForm) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let request = null;
  let trigger = null;
  let closeTimer = null;
  let saving = false;
  let toastTimer = null;
  let toast = null;

  const rows = (form) => [...form.querySelectorAll('[data-order-notifications-row]')];
  const inputFor = (row) => row.querySelector('[data-order-notifications-email]');
  const updateCount = (form) => {
    const allRows = rows(form);
    const count = allRows.filter((row) => inputFor(row).value.trim()).length;
    form.querySelector('[data-order-notifications-count]').textContent = `${count} / 10`;
    const add = form.querySelector('[data-order-notifications-add]');
    add.disabled = allRows.every((row) => !row.hidden) || saving;
    allRows.filter((row) => !row.hidden).forEach((row, index) => {
      row.querySelector('[data-order-notifications-label]').textContent = `Email ${index + 1}`;
      row.querySelector('[data-order-notifications-remove]').setAttribute('aria-label', `Elimină adresa de email ${index + 1}`);
    });
  };

  const initializeForm = (form) => {
    if (!form || form.dataset.enhanced) return;
    form.dataset.enhanced = 'true';
    const allRows = rows(form);
    let emptyShown = false;
    allRows.forEach((row) => {
      const empty = !inputFor(row).value.trim();
      row.hidden = empty && emptyShown;
      if (empty) emptyShown = true;
      row.querySelector('[data-order-notifications-remove]').hidden = false;
    });
    form.querySelector('[data-order-notifications-add]').hidden = false;
    updateCount(form);
  };

  const close = (onClosed = null) => {
    if (!dialog?.open || saving || dialog.classList.contains('is-closing')) return;
    request?.abort();
    request = null;
    dialog.classList.add('is-closing');
    dialog.classList.remove('is-visible');
    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(() => {
      dialog.close();
      dialog.classList.remove('is-closing');
      document.documentElement.classList.remove('has-order-notifications');
      content.innerHTML = initialMarkup;
      const visibleTrigger = trigger?.getClientRects().length ? trigger : [...document.querySelectorAll('[data-order-notifications-open]')].find((item) => item.getClientRects().length);
      visibleTrigger?.focus({preventScroll: true});
      trigger = null;
      onClosed?.();
    }, reducedMotion.matches ? 0 : 200);
  };

  const dismissToast = () => {
    window.clearTimeout(toastTimer);
    if (!toast) return;
    const oldToast = toast;
    oldToast.classList.remove('is-visible');
    toast = null;
    window.setTimeout(() => oldToast.remove(), reducedMotion.matches ? 0 : 220);
  };

  const showSuccess = (message) => {
    dismissToast();
    toast = document.createElement('div');
    toast.className = 'order-notification-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = '<span class="order-notification-toast__mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span><div><strong>Setări salvate</strong><p></p></div><button type="button" aria-label="Închide confirmarea"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg></button>';
    toast.querySelector('p').textContent = message || 'Lista adreselor pentru comenzile noi a fost actualizată.';
    toast.querySelector('button').addEventListener('click', dismissToast);
    document.body.append(toast);
    requestAnimationFrame(() => toast?.classList.add('is-visible'));
    toastTimer = window.setTimeout(dismissToast, 6000);
  };

  const open = async (button) => {
    if (!dialog || !content) return;
    request?.abort();
    const controller = new AbortController();
    request = controller;
    trigger = button;
    window.clearTimeout(closeTimer);
    dialog.classList.remove('is-closing');
    content.innerHTML = initialMarkup;
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('has-order-notifications');
    requestAnimationFrame(() => {
      if (!controller.signal.aborted && dialog.open) dialog.classList.add('is-visible');
    });
    try {
      const response = await fetch(button.dataset.modalUrl, {headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
      if (!response.ok || response.redirected) throw new Error('Setările nu au putut fi încărcate. Reîncearcă sau deschide pagina completă.');
      const markup = await response.text();
      if (controller.signal.aborted) return;
      content.innerHTML = markup;
      const form = content.querySelector('[data-order-notifications-form]');
      if (!form) throw new Error('Sesiunea a expirat. Deschide pagina setărilor pentru a te reconecta.');
      initializeForm(form);
      // Keep the header in view and avoid opening the phone keyboard automatically.
      dialog.querySelector('[data-order-notifications-close]')?.focus({preventScroll: true});
    } catch (error) {
      if (controller.signal.aborted) return;
      content.innerHTML = '<div class="order-notification-loading"><h2 id="order-notifications-title">Setările nu sunt disponibile</h2><p></p><a>Deschide pagina setărilor</a></div>';
      content.querySelector('p').textContent = error.message || 'A apărut o problemă temporară.';
      content.querySelector('a').href = button.href;
    }
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-order-notifications-open]');
    if (button && dialog && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
      event.preventDefault();
      open(button);
      return;
    }
    const cancel = event.target.closest('[data-order-notifications-close]');
    if (cancel && dialog?.contains(cancel)) {
      event.preventDefault();
      close();
      return;
    }
    const form = event.target.closest('[data-order-notifications-form]');
    if (!form || saving) return;
    if (event.target.closest('[data-order-notifications-add]')) {
      const row = rows(form).find((item) => item.hidden);
      if (!row) return;
      row.hidden = false;
      updateCount(form);
      inputFor(row).focus();
      return;
    }
    const remove = event.target.closest('[data-order-notifications-remove]');
    if (remove) {
      const row = remove.closest('[data-order-notifications-row]');
      inputFor(row).value = '';
      // Removing the original duplicate also clears the remaining row's stale error.
      form.querySelectorAll('[data-order-notifications-email]').forEach((field) => field.setCustomValidity(''));
      row.hidden = true;
      const remaining = rows(form).filter((item) => !item.hidden);
      if (!remaining.length) row.hidden = false;
      updateCount(form);
      form.querySelector('[data-order-notifications-add]').focus();
      form.classList.remove('is-saved');
      form.querySelector('[data-order-notifications-save-label]').textContent = 'Salvează setările';
    }
  });

  document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-order-notifications-email]');
    if (!input) return;
    const form = input.form;
    form.querySelectorAll('[data-order-notifications-email]').forEach((item) => item.setCustomValidity(''));
    form.classList.remove('is-saved');
    form.querySelector('[data-order-notifications-save-label]').textContent = 'Salvează setările';
    form.querySelector('[data-order-notifications-feedback]').hidden = true;
    updateCount(form);
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-order-notifications-form]');
    if (!form) return;
    event.preventDefault();
    if (saving) return;
    const fields = [...form.querySelectorAll('[data-order-notifications-email]')];
    const seen = new Set();
    fields.forEach((field) => {
      field.setCustomValidity('');
      field.value = field.value.trim();
      const value = field.value.toLowerCase();
      if (value && seen.has(value)) field.setCustomValidity('Această adresă este deja în listă. Păstreaz-o o singură dată.');
      if (value) seen.add(value);
    });
    if (!form.reportValidity()) return;
    const feedback = form.querySelector('[data-order-notifications-feedback]');
    const label = form.querySelector('[data-order-notifications-save-label]');
    const data = new FormData(form);
    saving = true;
    form.setAttribute('aria-busy', 'true');
    fields.forEach((field) => { field.readOnly = true; });
    form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
    dialog?.querySelector('button[data-order-notifications-close]')?.setAttribute('disabled', '');
    feedback.hidden = true;
    label.textContent = 'Se salvează…';
    let result = null;
    try {
      const response = await fetch(form.action, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}, body: data});
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.');
      result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || result.message || 'Setările nu au putut fi salvate.');
    } catch (error) {
      result = null;
      feedback.textContent = error.message || 'A apărut o problemă temporară. Încearcă din nou.';
      feedback.classList.add('is-error');
      feedback.hidden = false;
      feedback.scrollIntoView({block: 'nearest', behavior: reducedMotion.matches ? 'instant' : 'smooth'});
    } finally {
      saving = false;
      form.removeAttribute('aria-busy');
      fields.forEach((field) => { field.readOnly = false; });
      form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
      dialog?.querySelector('button[data-order-notifications-close]')?.removeAttribute('disabled');
      label.textContent = 'Salvează setările';
      updateCount(form);
    }
    if (result) {
      if (dialog?.contains(form)) close(() => showSuccess(result.message));
      else showSuccess(result.message);
    }
  });

  dialog?.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
  dialog?.addEventListener('click', (event) => { if (event.target === dialog) close(); });
  initializeForm(pageForm);
})();
