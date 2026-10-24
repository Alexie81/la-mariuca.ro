const variantControl = document.querySelector('[data-variant-select]');

if (variantControl instanceof HTMLSelectElement || variantControl instanceof HTMLInputElement) {
  const form = variantControl.closest('form');
  const button = form?.querySelector('button[type="submit"]');
  const buttonLabel = button?.querySelector('[data-add-to-cart-label]');
  const price = document.querySelector('[data-product-price]');
  const comparePrice = document.querySelector('[data-product-compare-price]');
  const stock = document.querySelector('[data-product-stock]');
  const sku = document.querySelector('[data-product-sku]');
  const money = new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON' });

  const selectedVariant = () => (
    variantControl instanceof HTMLSelectElement
      ? variantControl.selectedOptions[0]
      : variantControl
  );

  const update = () => {
    const variant = selectedVariant();
    const available = Number(variant?.dataset.stock || 0);
    const priceBani = Number(variant?.dataset.price || 0);
    const comparePriceBani = Number(variant?.dataset.comparePrice || 0);
    const isAvailable = available > 0;

    if (button instanceof HTMLButtonElement) button.disabled = !isAvailable;
    if (buttonLabel instanceof HTMLElement) {
      buttonLabel.textContent = isAvailable ? 'Adaugă în coș' : 'Stoc epuizat';
    } else if (button instanceof HTMLButtonElement) {
      button.textContent = isAvailable ? 'Adaugă în coș' : 'Stoc epuizat';
    }

    if (price instanceof HTMLElement) price.textContent = money.format(priceBani / 100);
    if (comparePrice instanceof HTMLElement) {
      comparePrice.hidden = comparePriceBani <= priceBani;
      comparePrice.textContent = comparePriceBani > priceBani ? money.format(comparePriceBani / 100) : '';
    }
    if (sku instanceof HTMLElement) sku.textContent = variant?.dataset.sku || '';
    if (stock instanceof HTMLElement) {
      stock.classList.toggle('is-available', isAvailable);
      stock.classList.toggle('is-unavailable', !isAvailable);
      const stockText = stock.querySelector('span');
      if (stockText) stockText.textContent = isAvailable ? 'În stoc' : 'Stoc epuizat';
    }
  };

  variantControl.addEventListener('change', update);
  update();
}
