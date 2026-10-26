document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
  if (!(toggle instanceof HTMLButtonElement)) {
    return;
  }

  const field = toggle.closest('.login-field')?.querySelector('input[type="password"], input[data-password-visible]');
  if (!(field instanceof HTMLInputElement)) {
    return;
  }

  toggle.addEventListener('click', () => {
    const shouldShow = field.type === 'password';
    field.type = shouldShow ? 'text' : 'password';
    field.toggleAttribute('data-password-visible', shouldShow);
    toggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
    toggle.setAttribute('aria-label', shouldShow ? 'Ascunde parola' : 'Arată parola');
    toggle.title = shouldShow ? 'Ascunde parola' : 'Arată parola';
    field.focus({ preventScroll: true });
  });
});
