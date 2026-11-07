(() => {
  'use strict';
  const root = document.querySelector('[data-order-tracking]');
  if (!root) return;
  const dialog = root.querySelector('[data-tracking-cancel-dialog]');
  const opener = root.querySelector('[data-tracking-cancel-open]');
  const form = root.querySelector('[data-tracking-cancel-form]');
  if (!form) return;
  const reason = form.querySelector('[name="reason"]');
  const submit = form.querySelector('[data-cancel-submit]');
  const error = form.querySelector('[data-cancel-error]');
  const started = performance.now();
  const remainingMs = Number(form.dataset.cancelRemaining) * 1000;
  let submitting = false;
  let expired = false;
  const showError = (message) => { error.textContent = message; error.hidden = false; };
  const checkExpiry = () => {
    if (!submit || performance.now() - started < remainingMs) return;
    expired = true;
    submit.disabled = true;
    if (opener) opener.hidden = true;
    showError('Au trecut 5 zile de la plasare. Perioada în care poți solicita anularea s-a încheiat.');
  };
  if (submit) {
    setTimeout(checkExpiry, Math.max(0, remainingMs));
    document.addEventListener('visibilitychange', checkExpiry);
    window.addEventListener('pageshow', checkExpiry);
  }
  if (dialog && typeof dialog.showModal === 'function') {
    let previousOverflow = '';
    opener?.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      checkExpiry();
      if (expired) return;
      event.preventDefault();
      previousOverflow = document.documentElement.style.overflow;
      document.documentElement.style.overflow = 'hidden';
      dialog.showModal();
    });
    form.querySelectorAll('[data-cancel-close]').forEach((link) => link.addEventListener('click', (event) => {
      event.preventDefault();
      if (!submitting) dialog.close();
    }));
    dialog.addEventListener('cancel', (event) => { if (submitting) event.preventDefault(); });
    dialog.addEventListener('close', () => {
      document.documentElement.style.overflow = previousOverflow;
      if (opener && !opener.hidden) opener.focus({preventScroll:true});
    });
  }
  const counter = form.querySelector('[data-cancel-count]');
  reason?.addEventListener('input', () => {
    if (counter) counter.textContent = `${Array.from(reason.value).length} / 1.000`;
    reason.removeAttribute('aria-invalid');
  });
  form.addEventListener('submit', async (event) => {
    if (!window.fetch || !submit) return;
    event.preventDefault();
    checkExpiry();
    if (submitting || expired || !form.reportValidity()) return;
    submitting = true;
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    submit.querySelector('span').textContent = 'Se trimite…';
    error.hidden = true;
    try {
      const response = await fetch(form.getAttribute('action'), {
        method:'POST', credentials:'same-origin', headers:{Accept:'application/json'}, body:new FormData(form),
      });
      const data = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
      if (!response.ok || !data?.ok) {
        showError(data?.message || 'Sesiunea s-a schimbat. Reîncarcă pagina și încearcă din nou.');
        error.scrollIntoView({block:'nearest'});
        return;
      }
      const destination = new URL(data.redirect, window.location.href);
      if (destination.pathname === window.location.pathname && destination.search === window.location.search) {
        window.location.hash = destination.hash;
        window.location.reload();
      } else {
        window.location.assign(destination.href);
      }
    } catch (_) {
      showError('Nu am putut confirma trimiterea. Verifică conexiunea și încearcă din nou; aceeași cerere nu va fi înregistrată de două ori.');
    } finally {
      submitting = false;
      submit.disabled = expired;
      submit.removeAttribute('aria-busy');
      submit.querySelector('span').textContent = 'Trimite cererea';
    }
  });
})();
