import { initializeRomanianLocationSelectors } from './romania-locations.js?v=20261026-locations2';

initializeRomanianLocationSelectors();

const accountPage = document.querySelector('.account-page');

if (accountPage) {
  const navigationLinks = [...accountPage.querySelectorAll('.account-nav a[href^="#"]')];
  const sections = navigationLinks
    .map((link) => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);

  const activateLink = (sectionId) => {
    navigationLinks.forEach((link) => {
      const active = link.getAttribute('href') === `#${sectionId}`;
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  };

  let navigationFrame = 0;
  const updateNavigation = () => {
    navigationFrame = 0;
    const marker = Math.min(220, window.innerHeight * .34);
    let current = sections[0];
    sections.forEach((section) => {
      if (section.getBoundingClientRect().top <= marker) current = section;
    });
    if (current) activateLink(current.id);
  };
  window.addEventListener('scroll', () => {
    if (!navigationFrame) navigationFrame = window.requestAnimationFrame(updateNavigation);
  }, { passive: true });
  updateNavigation();

  accountPage.querySelectorAll('[data-account-delete-address]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Sigur vrei să ștergi această adresă?')) event.preventDefault();
    });
  });

  const addressEditors = [...accountPage.querySelectorAll('.account-address-actions > details')];
  const syncAddressModalState = () => {
    const mobileModalOpen = window.matchMedia('(max-width: 760px)').matches && addressEditors.some((editor) => editor.open);
    document.documentElement.classList.toggle('account-address-modal-open', mobileModalOpen);
    accountPage.querySelectorAll('.account-panel').forEach((panel) => {
      panel.classList.toggle('has-open-address-editor', Boolean(panel.querySelector('.account-address-actions > details[open]')));
    });
    accountPage.querySelectorAll('.account-address-card').forEach((card) => {
      card.classList.toggle('has-open-address-editor', Boolean(card.querySelector('.account-address-actions > details[open]')));
    });
  };
  addressEditors.forEach((editor) => {
    editor.addEventListener('toggle', () => {
      if (editor.open) {
        addressEditors.forEach((other) => {
          if (other !== editor) other.open = false;
        });
        window.requestAnimationFrame(() => editor.querySelector('input:not([type="hidden"])')?.focus());
      }
      syncAddressModalState();
    });
  });
  accountPage.querySelectorAll('[data-account-address-close]').forEach((button) => {
    button.addEventListener('click', () => {
      const editor = button.closest('details');
      if (editor) editor.open = false;
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    addressEditors.forEach((editor) => { editor.open = false; });
    syncAddressModalState();
  });
  window.addEventListener('resize', syncAddressModalState, { passive: true });
  syncAddressModalState();
}
