const formatMoney = (bani) => {
  const absolute = Math.abs(Number(bani) || 0);
  const whole = Math.floor(absolute / 100).toLocaleString('ro-RO');
  const fraction = String(absolute % 100).padStart(2, '0');
  return `${Number(bani) < 0 ? '−' : ''}${whole},${fraction} Lei`;
};

const pulse = (element) => {
  if (!(element instanceof HTMLElement)) return;
  element.classList.remove('is-cart-value-updated');
  void element.offsetWidth;
  element.classList.add('is-cart-value-updated');
};

const updateText = (selector, value) => {
  const element = document.querySelector(selector);
  if (!(element instanceof HTMLElement)) return;
  element.textContent = value;
  pulse(element);
};

const updateCartSummary = (payload) => {
  const count = Number(payload.cart_count || 0);
  const itemId = String(payload.item_id || '');

  document.querySelectorAll('[data-cart-count]').forEach((counter) => {
    counter.textContent = String(count);
  });

  const mobileCart = document.querySelector('.mobile-cart');
  mobileCart?.setAttribute('aria-label', `Coș, ${count} ${count === 1 ? 'produs' : 'produse'}`);

  const pageCount = document.querySelector('[data-cart-page-count]');
  pageCount?.setAttribute('aria-label', `${count} ${count === 1 ? 'produs' : 'produse'} în coș`);
  updateText('[data-cart-page-count-value]', String(count));
  updateText('[data-cart-page-count-label]', count === 1 ? 'produs' : 'produse');
  updateText(`[data-cart-line-total="${CSS.escape(itemId)}"]`, formatMoney(payload.line_total_bani));
  updateText('[data-cart-subtotal]', formatMoney(payload.subtotal_bani));
  updateText('[data-cart-total]', formatMoney(payload.total_bani));

  const discount = document.querySelector('[data-cart-discount]');
  if (discount instanceof HTMLElement) {
    discount.textContent = `−${formatMoney(Math.abs(Number(payload.discount_bani || 0)))}`;
    pulse(discount);
  }

  const miniTitle = document.querySelector('#desktop-mini-cart .mini-panel-title strong');
  if (miniTitle instanceof HTMLElement) {
    miniTitle.textContent = `${count} ${count === 1 ? 'produs pregătit' : 'produse pregătite'}`;
  }
  updateText('#desktop-mini-cart .mini-panel-total strong', formatMoney(payload.total_bani));
};

document.querySelectorAll('[data-cart-quantity-form]').forEach((form) => {
  if (!(form instanceof HTMLFormElement)) return;

  const output = form.querySelector('[data-cart-quantity-value]');
  const decrease = form.querySelector('[data-cart-quantity-decrease]');
  const increase = form.querySelector('[data-cart-quantity-increase]');
  const status = form.querySelector('[data-cart-quantity-status]');
  const maximum = Math.max(1, Number(form.dataset.maxQuantity || 20));

  if (!(output instanceof HTMLOutputElement) || !(decrease instanceof HTMLButtonElement) || !(increase instanceof HTMLButtonElement)) return;

  const syncControls = (quantity) => {
    output.value = String(quantity);
    output.textContent = String(quantity);
    decrease.value = String(Math.max(1, quantity - 1));
    increase.value = String(Math.min(maximum, quantity + 1));
    decrease.disabled = quantity <= 1;
    increase.disabled = quantity >= maximum;
  };

  form.addEventListener('submit', async (event) => {
    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement) || submitter.name !== 'quantity') return;

    event.preventDefault();
    const requestedQuantity = Number(submitter.value);
    if (!Number.isFinite(requestedQuantity)) return;

    const currentQuantity = Number(output.value || output.textContent || 1);
    form.classList.add('is-updating');
    form.setAttribute('aria-busy', 'true');
    decrease.disabled = true;
    increase.disabled = true;
    if (status instanceof HTMLElement) status.textContent = '';

    try {
      const body = new FormData(form);
      body.set('quantity', String(requestedQuantity));
      const response = await fetch(form.action, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || payload.ok !== true) throw new Error(payload.message || 'Cantitatea nu a putut fi actualizată.');

      syncControls(Number(payload.item_quantity));
      updateCartSummary(payload);
      pulse(output);
    } catch (error) {
      syncControls(currentQuantity);
      if (status instanceof HTMLElement) {
        status.textContent = error instanceof Error ? error.message : 'Cantitatea nu a putut fi actualizată.';
      }
    } finally {
      form.classList.remove('is-updating');
      form.removeAttribute('aria-busy');
    }
  });
});
