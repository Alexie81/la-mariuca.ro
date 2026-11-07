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
