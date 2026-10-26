import { initializeRomanianLocationSelectors } from './romania-locations.js';

initializeRomanianLocationSelectors();

const addressSelector = document.querySelector('[data-checkout-saved-address]');

if (addressSelector instanceof HTMLSelectElement) {
  const form = addressSelector.closest('form');
  const assign = (name, value) => {
    const field = form?.elements.namedItem(name);
    if (field instanceof HTMLInputElement) {
      field.value = value || '';
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  addressSelector.addEventListener('change', () => {
    const option = addressSelector.selectedOptions[0];
    if (!option || option.value === '') {
      ['address', 'city', 'county', 'postal_code'].forEach((name) => assign(name, ''));
      form?.elements.namedItem('address')?.focus();
      return;
    }
    assign('first_name', option.dataset.firstName);
    assign('last_name', option.dataset.lastName);
    assign('phone', option.dataset.phone);
    assign('address', option.dataset.address);
    assign('city', option.dataset.city);
    assign('county', option.dataset.county);
    assign('postal_code', option.dataset.postalCode);
  });
}
