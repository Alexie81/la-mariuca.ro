(() => {
  'use strict';

  const workspace = document.querySelector('[data-admin-order-workspace]');
  if (!workspace) return;

  const dialog = workspace.querySelector('[data-order-dialog]');
  const dialogContent = workspace.querySelector('[data-order-dialog-content]');
  const lightbox = workspace.querySelector('[data-order-lightbox]');
  const lightboxImage = lightbox?.querySelector('[data-order-lightbox-image]');
  const lightboxCaption = lightbox?.querySelector('[data-order-lightbox-caption]');
  const loadingMarkup = dialogContent?.innerHTML || '';
  let modalRequest = null;
  let modalTrigger = null;

  const closeDialog = () => {
    if (!dialog?.open) return;
    modalRequest?.abort();
    modalRequest = null;
    dialog.classList.remove('is-visible');
    window.setTimeout(() => {
      if (dialog.open) dialog.close();
      dialog.classList.remove('is-compact', 'is-email');
      if (dialogContent) dialogContent.innerHTML = loadingMarkup;
      modalTrigger?.focus({ preventScroll: true });
      modalTrigger = null;
    }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
  };

  const initializeModal = (root) => {
    const returnForm = root.querySelector('[data-order-return-form]');
    const syncRefundAmount = () => {
      const mode = returnForm?.querySelector('[data-refund-mode]:checked')?.value;
      const field = returnForm?.querySelector('[data-refund-amount]');
      if (!field) return;
      field.hidden = mode !== 'partial';
      field.querySelector('input')?.toggleAttribute('required', mode === 'partial');
    };
    returnForm?.querySelectorAll('[data-refund-mode]').forEach((radio) => radio.addEventListener('change', syncRefundAmount));
    returnForm?.querySelector('[data-order-return-all]')?.addEventListener('click', (event) => {
      const button = event.currentTarget;
      const selects = [...returnForm.querySelectorAll('[data-return-quantity]')];
      const allSelected = selects.every((select) => select.selectedIndex === select.options.length - 1);
      selects.forEach((select) => { select.selectedIndex = allSelected ? 0 : select.options.length - 1; });
      button.textContent = allSelected ? 'Selectează toate cantitățile' : 'Golește selecția';
    });
    syncRefundAmount();
  };

  const loadModal = async (trigger) => {
    if (!dialog || !dialogContent) return;
    modalTrigger = trigger;
    dialogContent.innerHTML = loadingMarkup;
    dialog.showModal();
    requestAnimationFrame(() => dialog.classList.add('is-visible'));
    modalRequest?.abort();
    modalRequest = new AbortController();
    try {
      const response = await fetch(trigger.dataset.modalUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: modalRequest.signal,
      });
      if (!response.ok) throw new Error('Opțiunile nu au putut fi încărcate.');
      dialogContent.innerHTML = await response.text();
      dialog.classList.toggle('is-compact', !dialogContent.querySelector('[data-order-return-form]'));
      dialog.classList.toggle('is-email', Boolean(dialogContent.querySelector('form[action*="/email/"]')));
      initializeModal(dialogContent);
      requestAnimationFrame(() => dialogContent.querySelector('input:not([type="hidden"]),select,textarea,button')?.focus({ preventScroll: true }));
    } catch (error) {
      if (error.name === 'AbortError') return;
      dialogContent.innerHTML = `<div class="order-modal-empty"><span>!</span><h3>Nu am putut deschide fereastra</h3><p>${error.message || 'A apărut o problemă temporară.'}</p></div>`;
    } finally {
      modalRequest = null;
    }
  };

  workspace.addEventListener('click', (event) => {
    const modalOpen = event.target.closest('[data-order-modal-open]');
    if (modalOpen && dialog) {
      event.preventDefault();
      loadModal(modalOpen);
      return;
    }
    if (event.target.closest('[data-order-dialog-close]')) {
      event.preventDefault();
      closeDialog();
      return;
    }
    const imageOpen = event.target.closest('[data-order-image-open]');
    if (imageOpen && lightbox && lightboxImage) {
      lightboxImage.src = imageOpen.dataset.imageSrc || '';
      lightboxImage.alt = imageOpen.dataset.imageAlt || '';
      if (lightboxCaption) lightboxCaption.textContent = imageOpen.dataset.imageAlt || '';
      lightbox.showModal();
      return;
    }
    if (event.target.closest('[data-order-lightbox-close]')) lightbox?.close();
  });

  dialog?.addEventListener('cancel', (event) => { event.preventDefault(); closeDialog(); });
  dialog?.addEventListener('click', (event) => { if (event.target === dialog) closeDialog(); });
  lightbox?.addEventListener('click', (event) => { if (event.target === lightbox) lightbox.close(); });

  dialogContent?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-order-async-form]');
    if (!form || !window.fetch) return;
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    const feedback = form.querySelector('[data-order-form-feedback]');
    if (submit) submit.disabled = true;
    if (feedback) feedback.hidden = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Modificarea nu a putut fi salvată.');
      if (feedback) {
        feedback.textContent = payload.message;
        feedback.classList.add('is-success');
        feedback.hidden = false;
      }
      window.setTimeout(() => { window.location.href = payload.redirect || window.location.href; }, 350);
    } catch (error) {
      if (feedback) {
        feedback.textContent = error.message || 'A apărut o problemă temporară.';
        feedback.classList.remove('is-success');
        feedback.hidden = false;
      }
      if (submit) submit.disabled = false;
    }
  });
})();
