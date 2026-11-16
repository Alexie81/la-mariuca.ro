(() => {
  if (!document.querySelector('[data-spv-document]')) return;
  let busy = false, timer = null, delay = 15000, stopped = false;
  const panel = () => document.querySelector('[data-spv-document]');
  const pollingForm = () => panel()?.querySelector('[data-spv-poll]');
  const syncDocumentActions = () => {
    const current = panel();
    if (current?.dataset.spvCanDelete === '0') document.querySelectorAll('[data-invoice-delete]').forEach(action => action.remove());
    if (current?.dataset.spvLocked === '1') {
      const notice = document.querySelector('[data-invoice-fiscal-lock]');
      if (notice) notice.textContent = 'Document blocat pentru modificări după transmiterea fiscală.';
    }
  };
  const feedback = (message, failed = false) => {
    const target = panel()?.querySelector('[data-spv-feedback]');
    if (!target) return;
    target.textContent = message;
    target.hidden = false;
    target.classList.toggle('is-error', failed);
  };
  const schedule = () => {
    clearTimeout(timer);
    if (stopped || !pollingForm()) return;
    timer = setTimeout(() => {
      if (document.hidden) { schedule(); return; }
      const form = pollingForm();
      if (form) submit(form, true);
    }, delay);
  };
  const submit = async (form, automatic = false) => {
    const button = form.querySelector('button[type="submit"]');
    if (busy || button?.disabled || (automatic && !form.matches('[data-spv-poll]'))) return;
    clearTimeout(timer); busy = true;
    const body = new FormData(form), controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 55000);
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    if (!automatic) feedback(form.dataset.spvOperation === 'verifica' ? 'Verificăm răspunsul ANAF…' : 'Trimitem factura către ANAF…');
    try {
      const response = await fetch(form.action, {method: 'POST', body, signal: controller.signal, credentials: 'same-origin', headers: {Accept: 'application/json'}});
      if (response.redirected || [401, 403, 419].includes(response.status)) {
        stopped = true;
        throw new Error('Sesiunea nu mai permite verificarea. Reîncarcă pagina și autentifică-te din nou.');
      }
      if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Răspunsul nu a putut fi citit. Verifică starea facturii înainte de o nouă trimitere.');
      const result = await response.json();
      if (typeof result.html === 'string') {
        const next = new DOMParser().parseFromString(result.html, 'text/html').querySelector('[data-spv-document]');
        if (next) { panel()?.replaceWith(next); syncDocumentActions(); }
      }
      if (result.state === null) stopped = true;
      feedback(automatic && result.ok && result.state === 'submitted' ? 'ANAF încă procesează factura. Verificarea continuă automat.' : result.message, !result.ok);
      delay = result.ok ? 15000 : Math.min(delay * 2, 60000);
    } catch (error) {
      feedback(error.name === 'AbortError' ? 'Verificarea durează mai mult. Reîncarcă pagina pentru starea actuală; nu relua încărcarea fără verificare.' : error.message, true);
      delay = Math.min(delay * 2, 60000);
    } finally {
      clearTimeout(timeout); busy = false;
      if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
      schedule();
    }
  };
  document.addEventListener('submit', event => {
    const form = event.target.closest('[data-spv-invoice-action]');
    if (!form) return;
    event.preventDefault(); submit(form);
  });
  schedule();
})();
