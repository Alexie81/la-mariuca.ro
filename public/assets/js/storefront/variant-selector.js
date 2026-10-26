document.querySelectorAll('[data-variant-select]').forEach((variantControl) => {
  if (!(variantControl instanceof HTMLSelectElement || variantControl instanceof HTMLInputElement)) return;

  const form = variantControl.closest('form');
  if (!(form instanceof HTMLFormElement)) return;

  const button = form.querySelector('button[type="submit"]');
  const buttonLabel = button?.querySelector('[data-add-to-cart-label]');
  const price = document.querySelector('[data-product-price]');
  const comparePrice = document.querySelector('[data-product-compare-price]');
  const stock = document.querySelector('[data-product-stock]');
  const sku = document.querySelector('[data-product-sku]');
  const selectionHint = form.querySelector('[data-product-selection-hint]');
  const optionControls = [...form.querySelectorAll('[data-variant-option]')]
    .filter((control) => control instanceof HTMLSelectElement);
  const requiredProductOptions = [...form.querySelectorAll('[data-product-option][required]')]
    .filter((control) => control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement);
  const money = new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON' });

  const variantOptions = variantControl instanceof HTMLSelectElement
    ? [...variantControl.options].filter((option) => option.value !== '')
    : [variantControl];
  const variantRecords = variantOptions.map((option) => {
    let options = {};
    try {
      options = JSON.parse(option.dataset.options || '{}');
    } catch {
      options = {};
    }
    return { element: option, options };
  });
  const canEnhanceGroups = optionControls.length > 0 && variantRecords.length > 0
    && variantRecords.every(({ options }) => optionControls.every((control) => (
      Object.prototype.hasOwnProperty.call(options, control.dataset.optionName || '')
    )));

  if (canEnhanceGroups) {
    form.classList.add('is-variant-enhanced');
    const fallbackField = variantControl.closest('.product-variant-fallback');
    fallbackField?.setAttribute('aria-hidden', 'true');
    if (variantControl instanceof HTMLSelectElement) {
      variantControl.required = false;
      variantControl.tabIndex = -1;
    }
  }

  const selectedVariant = () => {
    if (variantControl instanceof HTMLSelectElement) {
      return variantControl.value === '' ? null : variantControl.selectedOptions[0];
    }
    return variantControl.value === '' ? null : variantControl;
  };

  const requiredOptionsAreComplete = () => requiredProductOptions.every((control) => {
    if (control instanceof HTMLInputElement && control.type === 'checkbox') return control.checked;
    return control.value.trim() !== '' && control.checkValidity();
  });

  const setHint = (message) => {
    if (!(selectionHint instanceof HTMLElement)) return;
    selectionHint.textContent = message;
    selectionHint.hidden = message === '';
  };

  const updateGroupAvailability = () => {
    if (!canEnhanceGroups) return;
    optionControls.forEach((currentControl) => {
      [...currentControl.options].forEach((candidate) => {
        if (candidate.value === '') return;
        const compatible = variantRecords.some(({ options }) => optionControls.every((control) => {
          const expected = control === currentControl ? candidate.value : control.value;
          return expected === '' || String(options[control.dataset.optionName || ''] ?? '') === expected;
        }));
        candidate.disabled = !compatible;
      });
    });
  };

  const selectFirstAvailableOptions = () => {
    if (!canEnhanceGroups) return;
    let changed = false;
    let passes = 0;
    do {
      changed = false;
      updateGroupAvailability();
      optionControls.forEach((control) => {
        if (control.value !== '') return;
        const availableOptions = [...control.options].filter((option) => option.value !== '' && !option.disabled);
        if (availableOptions.length === 0) return;
        control.value = availableOptions[0].value;
        changed = true;
      });
      passes += 1;
    } while (changed && passes <= optionControls.length);
  };

  const resolveGroupedVariant = () => {
    if (!canEnhanceGroups || !(variantControl instanceof HTMLSelectElement)) return;
    selectFirstAvailableOptions();
    updateGroupAvailability();
    const allSelected = optionControls.every((control) => control.value !== '');
    const match = allSelected
      ? variantRecords.find(({ options }) => optionControls.every((control) => (
        String(options[control.dataset.optionName || ''] ?? '') === control.value
      )))
      : null;
    variantControl.value = match?.element.value || '';
  };

  const update = () => {
    const variant = selectedVariant();
    const hasVariantSelection = variant !== null;
    const available = Number(variant?.dataset.stock || 0);
    const priceBani = Number(variant?.dataset.price || 0);
    const comparePriceBani = Number(variant?.dataset.comparePrice || 0);
    const isInCart = variant?.dataset.inCart === '1';
    const productOptionsComplete = requiredOptionsAreComplete();
    const isAvailable = hasVariantSelection && available > 0;
    const isReady = isInCart || (isAvailable && productOptionsComplete);

    form.dataset.cartReady = isReady ? 'true' : 'false';
    form.dataset.cartState = isInCart ? 'added' : 'removed';
    form.classList.toggle('is-added', isInCart);
    if (button instanceof HTMLButtonElement) {
      button.disabled = !isReady;
      const productName = form.dataset.productName || 'produsul';
      button.title = isInCart ? 'Scoate din coș' : (isReady ? 'Adaugă în coș' : 'Completează opțiunile produsului');
      button.setAttribute('aria-label', isInCart ? `Scoate ${productName} din coș` : `Adaugă ${productName} în coș`);
      button.setAttribute('aria-pressed', isInCart ? 'true' : 'false');
      button.setAttribute('aria-disabled', isReady ? 'false' : 'true');
    }
    if (buttonLabel instanceof HTMLElement) {
      buttonLabel.textContent = isInCart ? 'În coș' : (hasVariantSelection && !isAvailable ? 'Stoc epuizat' : 'Adaugă în coș');
    } else if (button instanceof HTMLButtonElement) {
      button.textContent = isInCart ? 'În coș' : (hasVariantSelection && !isAvailable ? 'Stoc epuizat' : 'Adaugă în coș');
    }

    optionControls.forEach((control) => control.setAttribute('aria-invalid', control.value === '' ? 'true' : 'false'));
    requiredProductOptions.forEach((control) => control.setAttribute('aria-invalid', control.checkValidity() ? 'false' : 'true'));

    if (isInCart) {
      setHint('');
    } else if (!hasVariantSelection) {
      setHint(optionControls.length > 0 ? 'Selectează toate opțiunile pentru a activa adăugarea în coș.' : 'Selectează o variantă pentru a continua.');
    } else if (!isAvailable) {
      setHint('Varianta aleasă nu este momentan în stoc.');
    } else if (!productOptionsComplete) {
      setHint('Completează opțiunile obligatorii pentru a continua.');
    } else {
      setHint('');
    }

    if (variant && price instanceof HTMLElement) price.textContent = money.format(priceBani / 100);
    if (variant && comparePrice instanceof HTMLElement) {
      comparePrice.hidden = comparePriceBani <= priceBani;
      comparePrice.textContent = comparePriceBani > priceBani ? money.format(comparePriceBani / 100) : '';
    }
    if (variant && sku instanceof HTMLElement) sku.textContent = variant.dataset.sku || '';
    if (variant && stock instanceof HTMLElement) {
      stock.classList.toggle('is-available', isAvailable);
      stock.classList.toggle('is-unavailable', !isAvailable);
      const stockText = stock.querySelector('span');
      if (stockText) stockText.textContent = isAvailable ? 'În stoc' : 'Stoc epuizat';
    }
  };

  optionControls.forEach((control) => control.addEventListener('change', () => {
    resolveGroupedVariant();
    update();
  }));
  requiredProductOptions.forEach((control) => {
    control.addEventListener('input', update);
    control.addEventListener('change', update);
  });
  variantControl.addEventListener('change', update);
  form.addEventListener('submit', (event) => {
    resolveGroupedVariant();
    update();
    if (form.dataset.cartReady === 'true') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const firstIncomplete = optionControls.find((control) => control.value === '')
      || requiredProductOptions.find((control) => !control.checkValidity());
    firstIncomplete?.focus();
  });

  resolveGroupedVariant();
  update();
});
