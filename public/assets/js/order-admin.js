(() => {
  'use strict';

  const workspace = document.querySelector('[data-admin-order-workspace], .order-action-page');
  if (!workspace) return;

  workspace.querySelectorAll('[data-order-copy]').forEach((button) => { button.hidden = false; });

  const dialog = workspace.querySelector('[data-order-dialog]');
  const dialogContent = workspace.querySelector('[data-order-dialog-content]');
  const lightbox = workspace.querySelector('[data-order-lightbox]');
  const lightboxImage = lightbox?.querySelector('[data-order-lightbox-image]');
  const lightboxCaption = lightbox?.querySelector('[data-order-lightbox-caption]');
  const lightboxClose = lightbox?.querySelector('[data-order-lightbox-close]');
  const loadingMarkup = dialogContent?.innerHTML || '';
  let modalRequest = null;
  let modalTrigger = null;
  let lightboxTrigger = null;
  let lightboxCloseTimer = null;
  let copySequence = 0;
  let copyToast = null;
  let copyToastTimer = null;
  let copyToastRemovalTimer = null;

  const clearCopyToast = () => {
    window.clearTimeout(copyToastTimer);
    window.clearTimeout(copyToastRemovalTimer);
    copyToast?.remove();
    copyToast = null;
  };

  const showCopyToast = (message, failed = false) => {
    clearCopyToast();
    const toast = document.createElement('div');
    toast.className = `order-copy-toast${failed ? ' is-error' : ''}`;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = '<span class="order-copy-toast__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span><span class="order-copy-toast__text"></span>';
    toast.querySelector('.order-copy-toast__text').textContent = message;
    if (failed) toast.querySelector('path').setAttribute('d', 'M12 7v6M12 17h.01');
    document.body.append(toast);
    copyToast = toast;
    // Start the upward exit after 1.7 seconds; its animation lasts another 300 ms.
    copyToastTimer = window.setTimeout(() => {
      if (copyToast !== toast) return;
      toast.classList.add('is-leaving');
      copyToastRemovalTimer = window.setTimeout(() => {
        toast.remove();
        if (copyToast === toast) copyToast = null;
      }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 300);
    }, failed ? 3500 : 1700);
  };

  const fallbackCopy = (value) => {
    const focused = document.activeElement;
    const selection = window.getSelection();
    const ranges = selection ? [...Array(selection.rangeCount)].map((_, index) => selection.getRangeAt(index).cloneRange()) : [];
    const inputSelection = focused && typeof focused.selectionStart === 'number'
      ? [focused.selectionStart, focused.selectionEnd, focused.selectionDirection] : null;
    const scroll = {left: window.scrollX, top: window.scrollY};
    const field = document.createElement('textarea');
    field.value = value;
    field.readOnly = true;
    field.tabIndex = -1;
    field.setAttribute('aria-hidden', 'true');
    field.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:1px;padding:0;border:0;opacity:0;font-size:16px;pointer-events:none;';
    document.body.append(field);
    try {
      field.focus({preventScroll: true});
      field.select();
      field.setSelectionRange(0, value.length);
      return document.execCommand('copy');
    } finally {
      field.remove();
      if (focused?.isConnected && typeof focused.focus === 'function') focused.focus({preventScroll: true});
      if (selection) {
        selection.removeAllRanges();
        ranges.forEach((range) => selection.addRange(range));
      }
      if (inputSelection && typeof focused?.setSelectionRange === 'function') {
        focused.setSelectionRange(...inputSelection);
      }
      window.scrollTo(scroll.left, scroll.top);
    }
  };

  const copyAddressValue = async (button) => {
    if (button.disabled) return;
    const value = button.dataset.copyValue || '';
    if (!value.trim()) return;
    const sequence = ++copySequence;
    const wasDisabled = button.disabled;
    const wasFocused = document.activeElement === button;
    clearCopyToast();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
      let copied = false;
      if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
          await navigator.clipboard.writeText(value);
          copied = true;
        } catch {
          // Older browsers and denied clipboard permissions may still allow a user-initiated copy.
        }
      }
      if (sequence !== copySequence) return;
      if (!copied) copied = fallbackCopy(value);
      if (!copied) throw new Error('Clipboard unavailable');
      showCopyToast(button.dataset.copyMessage || 'Copiat cu succes');
    } catch {
      if (sequence === copySequence) {
        showCopyToast('Copierea nu a reușit. Selectează textul și copiază-l manual.', true);
      }
    } finally {
      button.disabled = wasDisabled;
      button.removeAttribute('aria-busy');
      if (wasFocused && document.activeElement === document.body && button.isConnected) button.focus({preventScroll: true});
    }
  };

  const closeLightbox = () => {
    if (!lightbox?.open || lightbox.classList.contains('is-closing')) return;
    lightbox.classList.add('is-closing');
    lightbox.classList.remove('is-visible');
    window.clearTimeout(lightboxCloseTimer);
    lightboxCloseTimer = window.setTimeout(() => {
      if (lightbox.open) lightbox.close();
      lightbox.classList.remove('is-closing');
      lightboxTrigger?.focus({ preventScroll: true });
      lightboxTrigger = null;
      lightboxCloseTimer = null;
    }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 240);
  };

  const closeDialog = () => {
    if (!dialog?.open) return;
    modalRequest?.abort();
    modalRequest = null;
    dialog.classList.remove('is-visible');
    window.setTimeout(() => {
      if (dialog.open) dialog.close();
      dialog.classList.remove('is-compact', 'is-email', 'is-cancellation');
      if (dialogContent) dialogContent.innerHTML = loadingMarkup;
      modalTrigger?.focus({ preventScroll: true });
      modalTrigger = null;
    }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
  };

  const initializeModal = (root) => {
    const cancellationReason = root.querySelector('[data-cancellation-reason]');
    const updateCancellationCount = () => {
      const count = root.querySelector('[data-cancellation-count]');
      if (count && cancellationReason) count.textContent = `${[...cancellationReason.value].length} / 1.000`;
    };
    cancellationReason?.addEventListener('input', updateCancellationCount);
    updateCancellationCount();
    const statusRadios = root.querySelectorAll('[data-order-action-radio]');
    const syncStatusAction = () => {
      const selected = root.querySelector('[data-order-action-radio]:checked');
      const submit = selected?.form.querySelector('[type="submit"]');
      if (!submit) return;
      submit.querySelector('span').textContent = selected.closest('.order-choice').querySelector('strong').textContent;
      submit.classList.toggle('is-destructive', selected.value === 'cancel');
    };
    statusRadios.forEach((radio) => radio.addEventListener('change', syncStatusAction));
    syncStatusAction();
    const returnForm = root.querySelector('[data-order-return-form]');
    const updateReturnSummary = () => {
      const summary = returnForm?.querySelector('[data-return-summary]');
      if (!summary) return;
      const quantity = [...returnForm.querySelectorAll('[data-return-quantity]')].reduce((sum, select) => sum + Number(select.value), 0);
      summary.textContent = quantity ? `${quantity} ${quantity === 1 ? 'bucată selectată' : 'bucăți selectate'}` : 'Niciun produs selectat';
    };
    const syncRefundAmount = () => {
      const mode = returnForm?.querySelector('[data-refund-mode]:checked')?.value;
      const field = returnForm?.querySelector('[data-refund-amount]');
      if (!field) return;
      field.hidden = mode !== 'partial';
      field.querySelector('input')?.toggleAttribute('required', mode === 'partial');
    };
    returnForm?.querySelectorAll('[data-refund-mode]').forEach((radio) => radio.addEventListener('change', syncRefundAmount));
    returnForm?.querySelectorAll('[data-return-quantity]').forEach((select) => select.addEventListener('change', updateReturnSummary));
    returnForm?.querySelector('[data-order-return-all]')?.addEventListener('click', (event) => {
      const button = event.currentTarget;
      const selects = [...returnForm.querySelectorAll('[data-return-quantity]')];
      const allSelected = selects.every((select) => select.selectedIndex === select.options.length - 1);
      selects.forEach((select) => { select.selectedIndex = allSelected ? 0 : select.options.length - 1; });
      button.textContent = allSelected ? 'Selectează toate cantitățile' : 'Golește selecția';
      updateReturnSummary();
    });
    syncRefundAmount();
    updateReturnSummary();
  };

  if (!dialog) initializeModal(workspace);

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
      dialog.classList.toggle('is-cancellation', Boolean(dialogContent.querySelector('[data-order-cancellation-form]')));
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
    const copyButton = event.target.closest('[data-order-copy]');
    if (copyButton) {
      event.preventDefault();
      copyAddressValue(copyButton);
      return;
    }
    const modalOpen = event.target.closest('[data-order-modal-open]');
    if (modalOpen && dialog) {
      event.preventDefault();
      loadModal(modalOpen);
      return;
    }
    if (event.target.closest('[data-order-dialog-close]') && dialog) {
      event.preventDefault();
      closeDialog();
      return;
    }
    const imageOpen = event.target.closest('[data-order-image-open]');
    if (imageOpen && lightbox && lightboxImage) {
      window.clearTimeout(lightboxCloseTimer);
      lightboxCloseTimer = null;
      lightbox.classList.remove('is-visible', 'is-closing');
      lightboxTrigger = imageOpen;
      lightboxImage.src = imageOpen.dataset.imageSrc || '';
      lightboxImage.alt = imageOpen.dataset.imageAlt || '';
      if (lightboxCaption) lightboxCaption.textContent = imageOpen.dataset.imageAlt || '';
      lightbox.showModal();
      window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        lightbox.classList.add('is-visible');
        lightboxClose?.focus({ preventScroll: true });
      }));
      return;
    }
    if (event.target.closest('[data-order-lightbox-close]')) closeLightbox();
  });

  dialog?.addEventListener('cancel', (event) => { event.preventDefault(); closeDialog(); });
  dialog?.addEventListener('click', (event) => { if (event.target === dialog) closeDialog(); });
  lightbox?.addEventListener('cancel', (event) => { event.preventDefault(); closeLightbox(); });
  lightbox?.addEventListener('click', (event) => { if (event.target === lightbox) closeLightbox(); });

  dialogContent?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-order-async-form]');
    if (!form || !window.fetch) return;
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    const feedback = form.querySelector('[data-order-form-feedback]');
    if (submit) submit.disabled = true;
    if (feedback) feedback.hidden = true;
    try {
      const response = await fetch(form.getAttribute('action'), {
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
        feedback.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
      window.setTimeout(() => { window.location.href = payload.redirect || window.location.href; }, 350);
    } catch (error) {
      if (feedback) {
        feedback.textContent = error.message || 'A apărut o problemă temporară.';
        feedback.classList.remove('is-success');
        feedback.hidden = false;
        feedback.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
      if (submit) submit.disabled = false;
    }
  });
})();
