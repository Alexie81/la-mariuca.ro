(() => {
  'use strict';
  const form = document.querySelector('[data-tracking-lookup]');
  form?.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    button.querySelector('span').textContent = 'Căutăm comanda…';
  });
  window.addEventListener('pageshow', () => {
    const button = form?.querySelector('button[type="submit"]');
    if (!button) return;
    button.classList.remove('is-loading');
    button.removeAttribute('aria-busy');
    button.querySelector('span').textContent = 'Urmărește comanda';
  });
})();
