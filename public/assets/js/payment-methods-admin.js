(() => {
  'use strict';

  const form = document.querySelector('[data-payment-methods-form]');
  if (!form) return;

  const cod = form.elements.namedItem('cod_enabled');
  const stripe = form.elements.namedItem('stripe_enabled');
  const stripeConfigured = form.dataset.stripeConfigured === 'true';
  const error = form.querySelector('[data-payment-error]');
  const warning = form.querySelector('[data-payment-none]');
  const availability = document.querySelector('[data-payment-availability]');
  const count = document.querySelector('[data-payment-count]');
  let confirmed = readState();
  let desired = { ...confirmed };
  let saving = false;

  function readState() {
    return { cod_enabled: cod.checked, stripe_enabled: stripe.checked };
  }

  function sameState(first, second) {
    return first.cod_enabled === second.cod_enabled && first.stripe_enabled === second.stripe_enabled;
  }

  function update() {
    cod.checked = desired.cod_enabled;
    stripe.checked = desired.stripe_enabled;
    const available = Number(desired.cod_enabled) + Number(desired.stripe_enabled && stripeConfigured);
    count.textContent = `${available} ${available === 1 ? 'metodă vizibilă' : 'metode vizibile'}`;
    availability.classList.toggle('is-empty', available === 0);
    warning.hidden = available > 0;
  }

  async function saveLatest() {
    if (saving) return;
    saving = true;
    form.setAttribute('aria-busy', 'true');

    try {
      // One request at a time; a later toggle replaces the queued intent.
      while (!sameState(desired, confirmed)) {
        const submitted = { ...desired };
        const body = new FormData(form);
        body.set('cod_enabled', submitted.cod_enabled ? '1' : '0');
        body.set('stripe_enabled', submitted.stripe_enabled ? '1' : '0');

        const response = await fetch(form.action, {
          method: 'POST',
          body,
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) {
          throw new Error(payload?.error || payload?.message || 'Setările nu au putut fi salvate.');
        }

        const saved = payload.payment_methods || submitted;
        confirmed = {
          cod_enabled: Boolean(saved.cod_enabled),
          stripe_enabled: Boolean(saved.stripe_enabled)
        };
        cod.defaultChecked = confirmed.cod_enabled;
        stripe.defaultChecked = confirmed.stripe_enabled;
        if (sameState(desired, submitted)) desired = { ...confirmed };
        update();
      }
    } catch (failure) {
      desired = { ...confirmed };
      update();
      const message = failure instanceof TypeError
        ? 'Conexiunea a fost întreruptă.' : failure instanceof Error && failure.message
        ? failure.message : 'Salvarea nu a putut fi confirmată.';
      error.textContent = `${message} Am revenit la ultimele setări confirmate. Încearcă din nou.`;
      error.hidden = false;
    } finally {
      saving = false;
      form.removeAttribute('aria-busy');
    }
  }

  function applyChange() {
    desired = readState();
    error.hidden = true;
    update();
    void saveLatest();
  }

  form.addEventListener('change', (event) => {
    if (event.target === cod || event.target === stripe) applyChange();
  });
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    applyChange();
  });

  update();
})();

(() => {
  const dialog = document.querySelector('[data-payment-fee-dialog]');
  const register = document.querySelector('[data-payment-fee-register]');
  if (!(dialog instanceof HTMLDialogElement) || !register) return;
  const content = dialog.querySelector('[data-payment-fee-content]');
  const toast = document.querySelector('[data-payment-fee-toast]');
  let loading = false;
  let saving = false;
  let toastTimer;
  let opener;
  const announce = (message, error = false) => {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.toggle('is-error', error);
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, error ? 5000 : 2400);
  };
  const close = () => {
    if (saving) return;
    dialog.classList.add('is-closing');
    setTimeout(() => { dialog.close(); dialog.classList.remove('is-closing'); }, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 160);
  };
  register.addEventListener('click', async (event) => {
    const link = event.target.closest('[data-payment-fee-open]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (loading) return;
    loading = true;
    link.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error('Formularul nu este disponibil. Reîncarcă pagina și încearcă din nou.');
      content.innerHTML = await response.text();
      if (!content.querySelector('[data-payment-fee-form]')) throw new Error('Sesiunea a expirat. Reîncarcă pagina pentru autentificare.');
      opener = link;
      dialog.showModal();
      document.body.classList.add('payment-fee-modal-open');
    } catch (error) { announce(error.message || 'Formularul nu a putut fi încărcat.', true); }
    finally { loading = false; link.removeAttribute('aria-busy'); }
  });
  dialog.addEventListener('close', () => {
    document.body.classList.remove('payment-fee-modal-open');
    const focusTarget = opener?.isConnected ? opener : register.querySelector('[data-payment-fee-open]');
    focusTarget?.focus({ preventScroll: true });
  });
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
  dialog.addEventListener('click', (event) => {
    if (event.target.closest('[data-payment-fee-close]')) { event.preventDefault(); close(); }
    if (event.target === dialog) close();
  });
  dialog.addEventListener('input', () => {
    const name = dialog.querySelector('[name="name"]');
    const price = dialog.querySelector('[name="price"]');
    if (!name || !price) return;
    dialog.querySelector('[data-fee-preview-name]').textContent = name.value.trim() || 'Denumirea costului';
    const amount = Number(price.value.replace(',', '.'));
    dialog.querySelector('[data-fee-preview-price]').textContent = `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number.isFinite(amount) ? amount : 0)} Lei`;
  });
  dialog.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-payment-fee-form]');
    if (!form) return;
    event.preventDefault();
    if (saving || !form.reportValidity()) return;
    saving = true;
    const submit = form.querySelector('[type="submit"]');
    const error = form.querySelector('[data-payment-fee-error]');
    const body = new FormData(form);
    submit.disabled = true;
    error.hidden = true;
    form.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.ok) throw new Error(result?.error || 'Salvarea nu a putut fi confirmată. Încearcă din nou.');
      register.innerHTML = result.html;
      saving = false;
      close();
      announce(result.message);
    } catch (failure) {
      error.textContent = failure.message || 'Conexiunea a fost întreruptă. Încearcă din nou.';
      error.hidden = false;
      error.focus();
    } finally { saving = false; submit.disabled = false; form.removeAttribute('aria-busy'); }
  });
})();
