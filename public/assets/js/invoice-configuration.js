(() => {
  'use strict';
  const page = document.querySelector('[data-invoice-config]');
  if (!page) return;
  const content = page.querySelector('[data-inv-content]');
  const dialog = page.querySelector('[data-inv-dialog]');
  const dialogContent = page.querySelector('[data-inv-dialog-content]');
  const discard = page.querySelector('[data-inv-discard]');
  const toast = page.querySelector('[data-inv-toast]');
  let busy = false, modalBaseline = '', defaultsBaseline = '', opener = null, discardAction = null, toastTimer, openRequest;
  const signature = (form) => form ? JSON.stringify([...new FormData(form)].filter(([key]) => key !== '_csrf')) : '';
  const defaultsForm = () => content.querySelector('[data-inv-defaults]');
  const modalForm = () => dialogContent.querySelector('form');
  const defaultsDirty = () => signature(defaultsForm()) !== defaultsBaseline;
  const modalDirty = () => dialog.open && signature(modalForm()) !== modalBaseline;
  const showToast = (message) => {
    clearTimeout(toastTimer);
    toast.querySelector('span').textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, 3300);
  };
  const showError = (form, message) => {
    const target = form?.querySelector('[data-inv-error]') || defaultsForm().querySelector('[data-inv-error]');
    target.textContent = message; target.hidden = false; target.focus();
  };
  function initialize() {
    defaultsBaseline = signature(defaultsForm());
  }
  function updateDefaults() {
    const form = defaultsForm(), note = form.querySelector('[data-inv-dirty-note]');
    form.querySelector('[data-inv-counter]').textContent = `${form.elements.default_notes.value.length} / 3000`;
    note.textContent = defaultsDirty() ? 'Modificări nesalvate' : 'Se aplică facturilor noi';
    note.classList.toggle('is-dirty', defaultsDirty());
  }
  const guard = (dirty, action) => {
    if (!dirty) { action(); return; }
    discardAction = action;
    discard.showModal();
    discard.querySelector('[data-inv-discard-no]').focus();
  };
  async function closeModal() {
    if (!dialog.open) return;
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
      dialog.classList.add('is-closing');
      await new Promise(resolve => setTimeout(resolve, 160));
    }
    dialog.close(); dialog.classList.remove('is-closing'); dialogContent.replaceChildren(); modalBaseline = '';
    document.documentElement.style.overflow = '';
    const focusTarget = opener?.isConnected ? opener : content.querySelector('[data-inv-open]');
    focusTarget?.focus({ preventScroll: true });
  }
  async function openModal(link) {
    openRequest?.abort();
    openRequest = new AbortController();
    opener = link;
    link.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(link.href, { signal: openRequest.signal, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const html = await response.text();
      if (!response.ok || !html.includes('data-inv-form')) throw new Error('Formularul nu a putut fi deschis. Reîncarcă pagina și încearcă din nou.');
      dialogContent.innerHTML = html;
      modalBaseline = signature(modalForm());
      dialog.showModal();
      document.documentElement.style.overflow = 'hidden';
      const focusTarget = dialogContent.querySelector('input:not([type="hidden"]), select') || dialogContent.querySelector('[data-inv-close]');
      focusTarget?.focus({ preventScroll: true });
    } catch (error) {
      if (error.name !== 'AbortError') showError(defaultsForm(), error.message);
    } finally { link.removeAttribute('aria-busy'); }
  }
  async function save(form) {
    if (busy || !form.reportValidity()) return;
    busy = true;
    const body = new FormData(form);
    const controls = [...form.elements].filter(el => !el.disabled);
    const errorBox = form.querySelector('[data-inv-error]');
    if (errorBox) errorBox.hidden = true;
    form.setAttribute('aria-busy', 'true');
    controls.forEach(el => { el.disabled = true; });
    try {
      const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) throw new Error(payload?.error || 'Salvarea nu a reușit. Verifică conexiunea și încearcă din nou.');
      content.innerHTML = payload.html;
      initialize();
      await closeModal();
      showToast(payload.message);
    } catch (error) {
      showError(form, error.message);
    } finally {
      controls.forEach(el => { el.disabled = false; });
      form.removeAttribute('aria-busy'); busy = false;
    }
  }
  page.addEventListener('click', event => {
    const link = event.target.closest('[data-inv-open]');
    if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      event.preventDefault(); if (busy) return;
      guard(defaultsDirty(), () => { defaultsForm().reset(); initialize(); updateDefaults(); openModal(link); });
    }
    if (event.target.closest('[data-inv-close]')) {
      event.preventDefault(); if (!busy) guard(modalDirty(), closeModal);
    }
    if (event.target.closest('[data-inv-discard-no]')) { discard.close(); discardAction = null; }
    if (event.target.closest('[data-inv-discard-yes]')) { const action = discardAction; discardAction = null; discard.close(); action?.(); }
  });
  page.addEventListener('input', event => { if (event.target.closest('[data-inv-defaults]')) updateDefaults(); });
  page.addEventListener('submit', event => {
    const form = event.target.closest('[data-inv-defaults],[data-inv-form],[data-inv-use]');
    if (!form) return;
    event.preventDefault(); if (busy) return;
    if (form.matches('[data-inv-use]')) {
      guard(defaultsDirty(), () => { defaultsForm().reset(); initialize(); updateDefaults(); save(form); });
    } else save(form);
  });
  dialog.addEventListener('cancel', event => { event.preventDefault(); if (!busy) guard(modalDirty(), closeModal); });
  dialog.addEventListener('click', event => {
    const bounds = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) && !busy) guard(modalDirty(), closeModal);
  });
  discard.addEventListener('cancel', () => { discardAction = null; });
  window.addEventListener('beforeunload', event => {
    if (!busy && (defaultsDirty() || modalDirty())) { event.preventDefault(); event.returnValue = ''; }
  });
  initialize();
})();
