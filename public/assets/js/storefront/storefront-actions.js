const toastRegion = document.querySelector('[data-storefront-toasts]');
const toastDuration = 2800;
const toastTransitionDuration = 220;
let activeToast = null;
let toastAutoDismissTimer = null;
let toastRemovalTimer = null;
let toastReplacementTimer = null;
let toastRevision = 0;
const moneyFormatter = new Intl.NumberFormat('ro-RO', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const dismissToast = (toast) => {
  if (!toast || toast.classList.contains('is-leaving')) return;
  window.clearTimeout(toastAutoDismissTimer);
  window.clearTimeout(toastReplacementTimer);
  toast.classList.add('is-leaving');
  toastRemovalTimer = window.setTimeout(() => {
    toast.remove();
    if (activeToast === toast) activeToast = null;
  }, toastTransitionDuration);
};

const scheduleToastDismissal = (toast) => {
  window.clearTimeout(toastAutoDismissTimer);
  toastAutoDismissTimer = window.setTimeout(() => dismissToast(toast), toastDuration);
};

const updateToastContent = (toast, message, kind, details = {}) => {
  const visualKind = ['favorite', 'favorite-removed', 'cart'].includes(kind) ? kind : kind === 'error' ? 'error' : 'success';
  toast.classList.add('boutique-toast');
  toast.classList.remove('boutique-toast--favorite', 'boutique-toast--favorite-removed', 'boutique-toast--cart', 'boutique-toast--error', 'boutique-toast--success');
  toast.classList.add(`boutique-toast--${visualKind}`);
  toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');

  let productMedia = toast.querySelector('.boutique-toast-product-media');
  if (!productMedia) {
    productMedia = document.createElement('a');
    productMedia.className = 'boutique-toast-product-media';
    const productImage = document.createElement('img');
    productMedia.append(productImage);
    toast.prepend(productMedia);
  }

  const hasProductImage = kind === 'cart' && Boolean(details.productImage);
  productMedia.hidden = !hasProductImage;
  if (hasProductImage) {
    productMedia.href = details.productUrl || details.cartUrl || '/cos/';
    const productImage = productMedia.querySelector('img');
    productImage.src = details.productImage;
    productImage.alt = details.productName ? `Imagine pentru ${details.productName}` : 'Produs adăugat în coș';
  }

  let action = toast.querySelector('.boutique-toast-action');
  if (!action) {
    action = document.createElement('a');
    action.className = 'boutique-toast-action';
    toast.querySelector('.boutique-toast-close')?.before(action);
  }
  const hasAction = kind === 'cart';
  action.hidden = !hasAction;
  if (hasAction) {
    action.href = details.cartUrl || document.querySelector('.mobile-cart')?.href || '/cos/';
    action.textContent = 'Vezi coșul';
    action.setAttribute('aria-label', 'Vezi coșul după adăugarea produsului');
  }
  toast.classList.toggle('has-product-image', hasProductImage);
  toast.classList.toggle('has-action', hasAction);

  const icon = toast.querySelector('.boutique-toast-icon');
  const eyebrow = toast.querySelector('.boutique-toast-copy small');
  const text = toast.querySelector('.boutique-toast-copy strong');
  if (icon) icon.textContent = kind === 'error' ? '!' : '✓';
  if (eyebrow) eyebrow.textContent = kind === 'error' ? 'Ceva nu a mers' : kind === 'cart' ? 'Adăugat cu drag în coș' : 'La Măriuca Boutique';
  if (text) text.textContent = kind === 'cart' && details.productName ? details.productName : message;

  const progress = toast.querySelector('.boutique-toast-progress');
  if (progress) progress.replaceWith(progress.cloneNode(true));
};

const createToast = (message, kind, details = {}) => {
  const toast = document.createElement('div');

  const icon = document.createElement('span');
  icon.className = 'boutique-toast-icon';
  icon.setAttribute('aria-hidden', 'true');

  const copy = document.createElement('span');
  copy.className = 'boutique-toast-copy';
  const eyebrow = document.createElement('small');
  const text = document.createElement('strong');
  copy.append(eyebrow, text);

  const close = document.createElement('button');
  close.className = 'boutique-toast-close';
  close.type = 'button';
  close.setAttribute('aria-label', 'Închide mesajul');
  close.textContent = '×';

  const progress = document.createElement('span');
  progress.className = 'boutique-toast-progress';
  progress.setAttribute('aria-hidden', 'true');

  toast.append(icon, copy, close, progress);
  updateToastContent(toast, message, kind, details);
  close.addEventListener('click', () => dismissToast(toast));
  return toast;
};

const showToast = (message, kind = 'success', details = {}) => {
  if (!toastRegion) return;
  const revision = ++toastRevision;
  window.clearTimeout(toastAutoDismissTimer);
  window.clearTimeout(toastRemovalTimer);
  window.clearTimeout(toastReplacementTimer);

  const currentToast = activeToast?.isConnected ? activeToast : toastRegion.querySelector('.boutique-toast');
  if (!currentToast) {
    activeToast = createToast(message, kind, details);
    toastRegion.replaceChildren(activeToast);
    scheduleToastDismissal(activeToast);
    return;
  }

  activeToast = currentToast;
  currentToast.classList.remove('is-leaving', 'is-replaced');
  currentToast.classList.add('is-replacing');
  toastReplacementTimer = window.setTimeout(() => {
    if (revision !== toastRevision || !currentToast.isConnected) return;
    updateToastContent(currentToast, message, kind, details);
    currentToast.classList.remove('is-replacing');
    currentToast.classList.add('is-replaced');
    scheduleToastDismissal(currentToast);
  }, toastTransitionDuration);
};

const serverToasts = [...document.querySelectorAll('[data-server-toast]')];
if (serverToasts.length > 0) {
  activeToast = serverToasts[0];
  serverToasts.slice(1).forEach((toast) => toast.remove());
  activeToast.querySelector('.boutique-toast-close')?.addEventListener('click', () => dismissToast(activeToast));
  scheduleToastDismissal(activeToast);
}

const syncCounter = (wrappers, selector, count) => {
  wrappers.forEach((wrapper) => {
    let counter = wrapper.querySelector(selector);
    if (count <= 0 && selector === '[data-favorite-count]') {
      counter?.remove();
      return;
    }
    if (!counter) {
      counter = document.createElement('span');
      counter.className = 'count';
      counter.setAttribute(selector.slice(1, -1), '');
      wrapper.append(counter);
    }
    counter.textContent = String(count);
  });
};

const updateFavoritePanel = (count) => {
  const panel = document.querySelector('.mini-favorites-panel');
  if (!panel) return;
  panel.classList.toggle('mini-cart-empty', count === 0);
  panel.replaceChildren();

  const copy = document.createElement('span');
  copy.className = 'mini-cart-empty-copy';
  const heading = document.createElement('strong');
  const detail = document.createElement('small');
  heading.textContent = count > 0 ? `${count} ${count === 1 ? 'produs păstrat cu drag' : 'produse păstrate cu drag'}` : 'Favoritele tale așteaptă ceva drag';
  detail.textContent = count > 0 ? 'Le găsești pe toate în lista ta de favorite.' : 'Salvează aici micile comori pe care vrei să le regăsești ușor.';
  copy.append(heading, detail);

  const link = document.createElement('a');
  link.href = document.querySelector('.mobile-favorite')?.href || '/favorite/';
  link.textContent = count > 0 ? 'Vezi favoritele →' : 'Descoperă colecțiile →';
  if (count === 0) link.href = document.querySelector('.search-form')?.action || '/cautare/';
  panel.append(copy, link);
};

const updateFavoriteCount = (count) => {
  const wrappers = document.querySelectorAll('.favorite-link .utility-icon-wrap, .mobile-favorite .utility-icon-wrap');
  syncCounter(wrappers, '[data-favorite-count]', count);
  document.querySelector('.mobile-favorite')?.setAttribute('aria-label', `Favorite, ${count} produse`);
  updateFavoritePanel(count);
};

const updateCartPanel = (count, totalBani) => {
  const panel = document.querySelector('#desktop-mini-cart');
  if (!panel) return;
  panel.classList.remove('mini-cart-empty');
  panel.replaceChildren();

  const heading = document.createElement('strong');
  heading.textContent = `${count} ${count === 1 ? 'produs în coș' : 'produse în coș'}`;
  const total = document.createElement('span');
  total.textContent = `${moneyFormatter.format(totalBani / 100)} Lei`;
  const link = document.createElement('a');
  link.href = document.querySelector('.mobile-cart')?.href || '/cos/';
  link.textContent = 'Vezi coșul →';
  panel.append(heading, total, link);
};

const updateCartCount = (count, totalBani) => {
  document.querySelectorAll('[data-cart-count]').forEach((counter) => {
    counter.textContent = String(count);
  });
  document.querySelector('.mobile-cart')?.setAttribute('aria-label', `Coș, ${count} produse`);
  updateCartPanel(count, totalBani);
};

const updateFavoriteForms = (productId, state) => {
  document.querySelectorAll('[data-storefront-favorite]').forEach((form) => {
    if (form.querySelector('[name="product_id"]')?.value !== productId) return;
    const selected = state === 'added';
    form.classList.remove('is-favorite-added', 'is-removed');
    form.classList.toggle('is-selected', selected);
    window.requestAnimationFrame(() => {
      form.classList.add(selected ? 'is-favorite-added' : 'is-removed');
    });
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    const productName = form.dataset.productName || 'produsul';
    button.setAttribute('aria-label', selected ? `Elimină ${productName} din favorite` : `Adaugă ${productName} la favorite`);
    button.title = selected ? 'Elimină din favorite' : 'Adaugă la favorite';
    if (!button.closest('.boutique-product-favorite')) {
      button.textContent = selected ? 'Salvat la favorite' : 'Adaugă la favorite';
    }
    if (selected) {
      window.setTimeout(() => form.classList.remove('is-favorite-added'), 820);
    } else if (form.classList.contains('boutique-product-favorite')) {
      const clearRemovedState = () => form.classList.remove('is-removed');
      form.addEventListener('pointerleave', clearRemovedState, { once: true });
      form.addEventListener('focusout', clearRemovedState, { once: true });
    } else {
      window.setTimeout(() => form.classList.remove('is-removed'), 720);
    }
  });
};

const animateCartSuccess = (form, button) => {
  form.classList.add('is-cart-confirmed');
  const cardText = button?.querySelector('.boutique-product-cart-text');
  const originalText = cardText?.textContent || button?.textContent || '';
  if (cardText) cardText.textContent = 'Adăugat în coș';
  else if (button) button.textContent = 'Produs adăugat ✓';

  window.setTimeout(() => {
    form.classList.remove('is-cart-confirmed');
    if (cardText) cardText.textContent = originalText;
    else if (button) button.textContent = originalText;
  }, 1800);
};

const updateCartToggleForms = (variantId, state) => {
  const isAdded = state === 'added';

  document.querySelectorAll('[data-cart-toggle]').forEach((cartForm) => {
    const formVariantId = cartForm.querySelector('[name="variant_id"]')?.value || '';
    if (String(formVariantId) !== String(variantId)) return;

    const button = cartForm.querySelector('button[type="submit"]');
    const productName = cartForm.dataset.productName || 'produsul';
    cartForm.classList.toggle('is-added', isAdded);
    cartForm.dataset.cartState = isAdded ? 'added' : 'removed';
    cartForm.classList.remove('is-cart-added', 'is-cart-removed');

    if (button) {
      button.setAttribute('aria-pressed', isAdded ? 'true' : 'false');
      button.setAttribute('aria-label', isAdded ? `Scoate ${productName} din coș` : `Adaugă ${productName} în coș`);
      button.title = isAdded ? 'Scoate din coș' : 'Adaugă în coș';
    }

    window.requestAnimationFrame(() => {
      cartForm.classList.add(isAdded ? 'is-cart-added' : 'is-cart-removed');
    });
    window.setTimeout(() => cartForm.classList.remove('is-cart-added', 'is-cart-removed'), 760);
  });
};

document.addEventListener('submit', async (event) => {
  const form = event.target.closest?.('[data-storefront-favorite], [data-storefront-cart]');
  if (!form || form.dataset.requestPending === 'true') return;

  event.preventDefault();
  const button = event.submitter || form.querySelector('button[type="submit"]');
  form.dataset.requestPending = 'true';
  form.classList.add('is-submitting');
  if (button) button.disabled = true;

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'fetch',
      },
      body: new FormData(form),
      credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => ({ ok: false, message: 'Răspunsul magazinului nu a putut fi citit.' }));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Acțiunea nu a putut fi finalizată.');

    if (form.matches('[data-storefront-favorite]')) {
      updateFavoriteForms(form.querySelector('[name="product_id"]')?.value || '', payload.state);
      updateFavoriteCount(Number(payload.favorite_count || 0));
      showToast(payload.message, payload.state === 'removed' ? 'favorite-removed' : 'favorite');
    } else {
      updateCartCount(Number(payload.cart_count || 0), Number(payload.cart_total_bani || 0));
      if (form.matches('[data-cart-toggle]')) {
        const variantId = payload.variant_id || form.querySelector('[name="variant_id"]')?.value || '';
        updateCartToggleForms(variantId, payload.state);
      } else {
        animateCartSuccess(form, button);
      }
      showToast(payload.message, payload.state === 'removed' ? 'success' : 'cart', payload.state === 'removed' ? {} : {
          productName: form.dataset.productName || 'Produsul ales',
          productImage: form.dataset.productImage || '',
          productUrl: form.dataset.productUrl || '',
          cartUrl: form.dataset.cartUrl || document.querySelector('.mobile-cart')?.href || '/cos/',
        });
    }
  } catch (error) {
    showToast(error instanceof Error ? error.message : 'Acțiunea nu a putut fi finalizată.', 'error');
  } finally {
    delete form.dataset.requestPending;
    form.classList.remove('is-submitting');
    if (button) button.disabled = false;
  }
});
