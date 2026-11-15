(() => {
  'use strict';
  const root = document.querySelector('[data-review-workspace]');
  if (!root || typeof HTMLDialogElement === 'undefined') return;
  const dialog = root.querySelector('[data-review-dialog]');
  const content = root.querySelector('[data-review-dialog-content]');
  const confirmation = root.querySelector('[data-review-confirm]');
  const loading = root.querySelector('[data-review-loading]');
  const pageError = root.querySelector('[data-review-page-error]');
  const standalone = root.querySelector('.rv-standalone');
  const filterToggle = root.querySelector('[data-review-filter-toggle]');
  const filterOptions = root.querySelector('[data-review-filter-options]');
  if (filterToggle && filterOptions) {
    const mobile = matchMedia('(max-width: 900px)');
    let expanded = Array.from(filterOptions.querySelectorAll('select')).some(select => select.value !== ({ sortare: 'priority', per_page: '10' }[select.name] || ''));
    const syncFilters = () => {
      filterToggle.hidden = !mobile.matches;
      filterOptions.hidden = mobile.matches && !expanded;
      filterToggle.setAttribute('aria-expanded', String(!filterOptions.hidden));
    };
    filterToggle.addEventListener('click', () => { expanded = !expanded; syncFilters(); });
    mobile.addEventListener('change', syncFilters); syncFilters();
  }
  let opener = null, request = null, baseline = '', saving = false, confirmResolve = null;
  const editor = () => (dialog.open ? content : standalone)?.querySelector('[data-review-content]');
  const responseField = () => editor()?.querySelector('[data-review-response]');
  const dirty = () => !!responseField() && responseField().value !== baseline;
  const syncLock = () => document.body.classList.toggle('rv-modal-open', dialog.open || confirmation.open);
  const showError = message => {
    const target = editor()?.querySelector('[data-review-error]') || pageError;
    target.textContent = message; target.hidden = false;
    target.scrollIntoView({ block: 'nearest' });
  };
  const ask = (title, copy, accept, identity = '', destructive = false) => new Promise(resolve => {
    if (confirmResolve) { resolve(false); return; }
    confirmResolve = resolve;
    confirmation.querySelector('#rv-confirm-title').textContent = title;
    confirmation.querySelector('#rv-confirm-copy').textContent = copy;
    const context = confirmation.querySelector('[data-review-confirm-identity]');
    context.textContent = identity; context.hidden = !identity;
    const button = confirmation.querySelector('[data-review-confirm-accept]');
    button.textContent = accept;
    button.classList.toggle('rv-button--reject', destructive);
    button.classList.toggle('rv-button--primary', !destructive);
    confirmation.showModal(); syncLock();
    const cancel = confirmation.querySelector('[data-review-confirm-cancel]');
    cancel.textContent = accept === 'Renunță la modificări' ? 'Continuă editarea' : 'Renunță';
    cancel.focus();
  });
  const finishConfirm = accepted => {
    const resolve = confirmResolve; confirmResolve = null;
    confirmation.close(); syncLock(); resolve?.(accepted);
  };
  confirmation.querySelector('[data-review-confirm-cancel]').addEventListener('click', () => finishConfirm(false));
  confirmation.querySelector('[data-review-confirm-accept]').addEventListener('click', () => finishConfirm(true));
  confirmation.addEventListener('cancel', event => { event.preventDefault(); finishConfirm(false); });
  const mayLeave = async () => !dirty() || await ask('Renunți la răspunsul nesalvat?', 'Textul introdus nu a fost publicat. Poți reveni și continua editarea.', 'Renunță la modificări');
  const close = async () => {
    if (saving || !(await mayLeave())) return;
    request?.abort(); baseline = ''; dialog.close(); content.replaceChildren(); syncLock(); opener?.focus();
  };
  const initialize = () => {
    baseline = responseField()?.value || '';
    const field = responseField();
    if (field) field.addEventListener('input', () => {
      editor().querySelector('[data-review-counter]').textContent = `${Array.from(field.value).length} / 1.500`;
    });
  };
  initialize();
  const open = async (href, trigger) => {
    if (saving || !(await mayLeave())) return;
    if (standalone) { baseline = responseField()?.value || ''; location.assign(href); return; }
    request?.abort(); const controller = new AbortController(); request = controller;
    loading.hidden = false; pageError.hidden = true;
    if (!dialog.open) opener = trigger;
    try {
      const result = await fetch(href, { headers: { 'X-Review-Modal': '1' }, credentials: 'same-origin', signal: controller.signal });
      const html = new DOMParser().parseFromString(await result.text(), 'text/html');
      const next = html.querySelector('[data-review-content]');
      if (request !== controller) return;
      if (!next || (!result.ok && result.status !== 404)) throw new Error('Recenzia nu a putut fi încărcată. Reîncarcă pagina și încearcă din nou.');
      content.replaceChildren(document.importNode(next, true));
      if (!dialog.open) dialog.showModal();
      syncLock(); initialize();
      content.querySelector('[data-review-close]')?.focus();
    } catch (error) {
      if (error.name !== 'AbortError' && request === controller) showError(error.message || 'Conexiunea a fost întreruptă. Încearcă din nou.');
    } finally { if (request === controller) loading.hidden = true; }
  };
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
  });
  root.addEventListener('click', async event => {
    const target = event.target.closest('a');
    if (!target || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (target.matches('[data-review-open]')) { event.preventDefault(); open(target.href, target); }
    else if (target.matches('[data-review-close]') && dialog.open) { event.preventDefault(); close(); }
    else if (target.closest('[data-review-content]') && dirty()) {
      event.preventDefault(); if (await mayLeave()) { baseline = responseField()?.value || ''; location.assign(target.href); }
    }
  });
  root.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-review-form]')) return;
    event.preventDefault();
    if (saving || confirmResolve || !form.reportValidity()) return;
    const data = new FormData(form);
    if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
    const action = data.get('action');
    const prompts = {
      approve: ['Publici această recenzie?', 'Recenzia va deveni vizibilă în pagina produsului.', 'Aprobă recenzia'],
      pending: ['Muți recenzia în așteptare?', 'Recenzia și răspunsul ei nu vor mai fi afișate până la o nouă aprobare.', 'Mută în așteptare'],
      reject: ['Respingi această recenzie?', 'Recenzia nu va mai fi publică. O poți aproba din nou ulterior.', 'Respinge recenzia'],
      remove: ['Retragi această recenzie?', 'Recenzia dispare din magazin și din registrul activ. Istoricul ei rămâne păstrat.', 'Retrage recenzia'],
      respond: ['Publici răspunsul magazinului?', 'Textul va apărea în pagina produsului, sub recenzia clientului.', 'Publică răspunsul']
    };
    if (!prompts[action]) return;
    const identity = `${editor().dataset.reviewAuthor} · ${editor().dataset.reviewProduct}`;
    if (!(await ask(...prompts[action], identity, ['reject', 'remove'].includes(action)))) return;
    saving = true;
    const buttons = Array.from(editor().querySelectorAll('button[type="submit"],button[name="action"]'));
    buttons.forEach(button => { button.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    const error = editor().querySelector('[data-review-error]'); if (error) error.hidden = true;
    try {
      const result = await fetch(form.getAttribute('action'), { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const payload = await result.json().catch(() => null);
      if (!result.ok || !payload?.ok) throw new Error(payload?.message || (result.status === 419 ? 'Sesiunea formularului a expirat. Copiază răspunsul și reîncarcă pagina.' : 'Salvarea nu a reușit. Textul tău este păstrat; încearcă din nou.'));
      baseline = responseField()?.value || '';
      location.assign(payload.redirect);
    } catch (error) { showError(error.message || 'Salvarea nu a reușit. Încearcă din nou.'); }
    finally { saving = false; buttons.forEach(button => { button.disabled = false; }); form.removeAttribute('aria-busy'); }
  });
  window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
})();
