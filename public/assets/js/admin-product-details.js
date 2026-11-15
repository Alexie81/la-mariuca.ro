(() => {
  'use strict';

  const renumber = (section) => {
    section.querySelectorAll('[data-product-repeatable-row]').forEach((row, index) => {
      row.querySelectorAll('[name]').forEach((field) => {
        field.name = field.name.replace(/\[\d+\]/, `[${index}]`);
      });
    });
  };

  const changed = (section) => {
    renumber(section);
    const form = section.closest('form');
    form?.dispatchEvent(new Event('input', { bubbles: true }));
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-product-repeatable-add],[data-product-repeatable-remove],[data-product-repeatable-up],[data-product-repeatable-down]');
    if (!button) return;
    const section = button.closest('[data-product-repeatable]');
    const rows = section?.querySelector('[data-product-repeatable-rows]');
    if (!section || !rows) return;

    if (button.matches('[data-product-repeatable-add]')) {
      const template = section.querySelector('[data-product-repeatable-template]');
      if (!template) return;
      const wrapper = document.createElement('div');
      wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(rows.children.length)).trim();
      const row = wrapper.firstElementChild;
      if (!row) return;
      rows.append(row);
      changed(section);
      row.querySelector('input,textarea')?.focus();
      return;
    }

    const row = button.closest('[data-product-repeatable-row]');
    if (!row) return;
    if (button.matches('[data-product-repeatable-remove]')) {
      if (rows.children.length === 1) {
        row.querySelectorAll('input,textarea').forEach((field) => { field.value = ''; });
      } else {
        row.remove();
      }
    } else if (button.matches('[data-product-repeatable-up]') && row.previousElementSibling) {
      rows.insertBefore(row, row.previousElementSibling);
    } else if (button.matches('[data-product-repeatable-down]') && row.nextElementSibling) {
      rows.insertBefore(row.nextElementSibling, row);
    }
    changed(section);
  });
})();
