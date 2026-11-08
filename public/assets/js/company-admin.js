(() => {
  'use strict';
  const dialog = document.querySelector('[data-company-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const content = dialog.querySelector('[data-company-dialog-content]');
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let opener = null;
  let loading = null;
  let saving = false;
  let closing = false;
  let previewUrl = null;
  let previewReader = null;
  let originalStamp = '';
  let previousOverflow = '';
  let toastTimer = null;

  function localUrl(value) {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin) throw new Error('Adresa solicitată nu este disponibilă.');
    return url;
  }

  function releasePreview() {
    if (previewReader?.readyState === FileReader.LOADING) previewReader.abort();
    previewReader = null;
    previewUrl = null;
  }

  function closeModal() {
    if (!dialog.open || saving || closing) return;
    closing = true;
    loading?.abort();
    dialog.classList.add('is-closing');
    window.setTimeout(() => {
      dialog.close();
      dialog.classList.remove('is-closing', 'is-loading');
      document.body.style.overflow = previousOverflow;
      document.documentElement.classList.remove('has-company-dialog');
      releasePreview();
      closing = false;
      const target = opener?.isConnected ? opener : document.querySelector('[data-company-page] h1');
      if (target) { if (!target.matches('a,button,input')) target.tabIndex = -1; target.focus({preventScroll:true}); }
    }, motion.matches ? 0 : 180);
  }

  function loadingMarkup() {
    content.innerHTML = '<div class="company-dialog__loading" role="status"><span aria-hidden="true"></span><strong>Pregătim formularul…</strong><p>Doar un moment.</p></div>';
  }

  function showErrors(form, message, errors = {}) {
    form.querySelectorAll('[data-field-error]').forEach((node) => { node.textContent = ''; node.hidden = true; });
    form.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
    const summary = form.querySelector('[data-company-errors]');
    if (summary) { summary.textContent = message || 'Verifică datele evidențiate.'; summary.hidden = false; summary.tabIndex = -1; }
    let first = null;
    Object.entries(errors).forEach(([name, text]) => {
      const field = form.querySelector(`[name="${CSS.escape(name)}"]`);
      const hint = form.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
      if (hint) { hint.textContent = String(text); hint.hidden = false; }
      if (field) { field.setAttribute('aria-invalid', 'true'); first ||= field; }
    });
    (first || summary)?.focus();
  }

  function initializeForm() {
    const form = content.querySelector('[data-company-form]');
    if (!form) return;
    syncVat(form);
    originalStamp = form.querySelector('[data-company-stamp-preview]')?.getAttribute('src') || '';
    const target = form.querySelector('[aria-invalid="true"]') || form.querySelector('input:not([type="hidden"]):not([type="file"]), textarea, select');
    // Keep the top of a long form visible; focusing a field must not jump past the heading.
    (target || form.querySelector('[data-company-modal-close]'))?.focus({preventScroll:true});
  }

  function syncVat(form) {
    const payer = form.querySelector('[name="is_vat_payer"]');
    const rate = form.querySelector('[name="vat_rate"]');
    if (!payer || !rate) return;
    rate.disabled = !payer.checked;
    if (!payer.checked) {
      rate.removeAttribute('aria-invalid');
      const error = form.querySelector('[data-field-error="vat_rate"]');
      if (error) { error.hidden = true; error.textContent = ''; }
    }
  }

  async function openModal(trigger) {
    if (saving || closing) return;
    opener = trigger;
    loading?.abort();
    loading = new AbortController();
    releasePreview();
    loadingMarkup();
    dialog.classList.add('is-loading');
    if (!dialog.open) {
      previousOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      document.documentElement.classList.add('has-company-dialog');
      dialog.showModal();
    }
    try {
      const response = await fetch(localUrl(trigger.dataset.modalUrl), {
        headers: {'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', signal:loading.signal,
      });
      if (!response.ok) throw new Error('Formularul nu a putut fi încărcat. Încearcă din nou.');
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      if (!parsed.querySelector('[data-company-form]')) throw new Error('Sesiunea poate fi expirată. Deschide formularul pentru a continua.');
      content.innerHTML = html;
      dialog.classList.remove('is-loading');
      initializeForm();
    } catch (failure) {
      if (failure.name === 'AbortError') return;
      dialog.classList.remove('is-loading');
      content.innerHTML = '<div class="company-dialog__loading" role="alert"><strong>Nu am putut deschide formularul</strong><p></p><a>Deschide formularul</a><button type="button" data-company-modal-close>Închide</button></div>';
      content.querySelector('p').textContent = failure.message || 'Verifică legătura la internet și încearcă din nou.';
      content.querySelector('a').href = trigger.href;
      content.querySelector('button').focus();
    }
  }

  function toast(message) {
    const node = document.querySelector('[data-company-toast]');
    if (!node) return;
    clearTimeout(toastTimer);
    const label = node.querySelector('[data-company-toast-message]');
    if (label) label.textContent = message;
    else node.textContent = message;
    node.hidden = false;
    node.classList.remove('is-leaving');
    node.classList.add('is-visible');
    toastTimer = window.setTimeout(() => {
      node.classList.add('is-leaving');
      window.setTimeout(() => { node.hidden = true; node.classList.remove('is-visible', 'is-leaving'); }, motion.matches ? 0 : 220);
    }, 3200);
  }

  async function refreshPage(destination) {
    const url = localUrl(destination);
    const response = await fetch(url, {credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    if (!response.ok) throw new Error('Datele au fost salvate, dar pagina nu s-a putut actualiza.');
    const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
    const replacement = parsed.querySelector('[data-company-page]');
    const current = document.querySelector('[data-company-page]');
    if (!replacement || !current) throw new Error('Reîncarcă pagina pentru a vedea datele salvate.');
    current.replaceWith(replacement);
    history.replaceState(null, '', url.pathname + url.search + url.hash);
  }

  async function saveForm(form) {
    if (saving || !form.reportValidity()) return;
    const body = new FormData(form);
    const controls = [...form.querySelectorAll('input, select, textarea, button')];
    const disabled = controls.map((control) => control.disabled);
    const closeButtons = [...dialog.querySelectorAll('[data-company-modal-close]')];
    saving = true;
    form.setAttribute('aria-busy', 'true');
    controls.forEach((control) => { control.disabled = true; });
    closeButtons.forEach((control) => { control.setAttribute('aria-disabled', 'true'); });
    const summary = form.querySelector('[data-company-errors]');
    if (summary) summary.hidden = true;
    let payload = null;
    try {
      const response = await fetch(localUrl(form.action), {
        method:'POST',body,credentials:'same-origin',
        headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
      });
      payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.success) {
        const error = new Error(payload?.message || 'Salvarea nu a putut fi confirmată. Verifică datele și încearcă din nou.');
        error.fields = payload?.errors || {};
        throw error;
      }
      try {
        await refreshPage(payload.redirect_url);
      } catch (_) {
        // The write succeeded. Navigate rather than inviting a duplicate submission.
        try { sessionStorage.setItem('company-saved-message', payload.message || 'Modificările au fost salvate.'); } catch (_) { /* Navigation still works when browser storage is unavailable. */ }
        location.assign(localUrl(payload.redirect_url));
        return;
      }
      saving = false;
      closeModal();
      toast(payload.message || 'Modificările au fost salvate.');
    } catch (failure) {
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      showErrors(form, failure instanceof TypeError ? 'Conexiunea a fost întreruptă. Datele introduse sunt păstrate.' : failure.message, failure.fields || {});
    } finally {
      saving = false;
      form.removeAttribute('aria-busy');
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      closeButtons.forEach((control) => { control.removeAttribute('aria-disabled'); });
    }
  }

  function stampPreview(form) {
    const input = form.querySelector('[data-company-stamp-input]');
    const image = form.querySelector('[data-company-stamp-preview]');
    const empty = form.querySelector('[data-company-stamp-empty]');
    const remove = form.querySelector('[data-company-stamp-remove]');
    releasePreview();
    const file = input?.files?.[0];
    if (file && (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024)) {
      input.value = '';
      showErrors(form, 'Alege o imagine PNG, JPG sau WebP de cel mult 2 MB.', {stamp_file:'Imaginea selectată nu are formatul sau dimensiunea acceptată.'});
    } else if (file) {
      if (remove) remove.checked = false;
      // Data URLs match the application's existing image policy; blob URLs do not.
      const reader = new FileReader();
      previewReader = reader;
      reader.addEventListener('load', () => {
        if (!form.isConnected || !dialog.open || input.files?.[0] !== file) return;
        previewUrl = typeof reader.result === 'string' ? reader.result : '';
        if (image && previewUrl) { image.src = previewUrl; image.hidden = false; }
        if (empty) empty.hidden = Boolean(previewUrl);
        const filename = form.querySelector('[data-company-stamp-filename]');
        if (filename) filename.textContent = file.name;
      });
      reader.readAsDataURL(file);
    }
    const src = previewUrl || (remove?.checked ? '' : originalStamp);
    const filename = form.querySelector('[data-company-stamp-filename]');
    if (filename) filename.textContent = previewUrl ? file.name : '';
    if (image) { image.hidden = !src; if (src) image.src = src; else image.removeAttribute('src'); }
    if (empty) empty.hidden = Boolean(src);
  }

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const trigger = event.target.closest('[data-company-modal-open]');
    if (trigger && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
      event.preventDefault(); void openModal(trigger); return;
    }
    if (event.target.closest('[data-company-modal-close]') && dialog.open) { event.preventDefault(); closeModal(); }
  });
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); closeModal(); });
  dialog.addEventListener('click', (event) => { if (event.target === dialog) { const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeModal(); } });
  dialog.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-company-form]');
    if (!form) return;
    event.preventDefault(); void saveForm(form);
  });
  dialog.addEventListener('change', (event) => {
    const form = event.target.closest('[data-company-form]');
    if (!form) return;
    if (event.target.name === 'is_vat_payer') syncVat(form);
    if (event.target.matches('[data-company-stamp-input]')) stampPreview(form);
    if (event.target.matches('[data-company-stamp-remove]')) {
      if (event.target.checked) { const input = form.querySelector('[data-company-stamp-input]'); if (input) input.value = ''; }
      stampPreview(form);
    }
  });
  dialog.addEventListener('input', (event) => {
    if (!event.target.name) return;
    event.target.removeAttribute('aria-invalid');
    const hint = content.querySelector(`[data-field-error="${CSS.escape(event.target.name)}"]`);
    if (hint) { hint.textContent = ''; hint.hidden = true; }
  });
  try {
    const savedMessage = sessionStorage.getItem('company-saved-message');
    if (savedMessage) { sessionStorage.removeItem('company-saved-message'); toast(savedMessage); }
  } catch (_) { /* Storage is optional; all forms remain usable. */ }
})();
