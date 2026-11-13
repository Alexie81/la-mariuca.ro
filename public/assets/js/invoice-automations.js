(() => {
  'use strict';
  const form = document.querySelector('[data-invoice-automations]');
  if (!form) return;
  const fields = ['card_issue', 'card_email', 'cod_issue', 'cod_email'];
  const inputs = Object.fromEntries(fields.map(name => [name, form.elements.namedItem(name)]));
  const error = form.querySelector('[data-ia-error]');
  const errorText = form.querySelector('[data-ia-error-text]');
  const reload = form.querySelector('[data-ia-reload]');
  const title = form.querySelector('[data-ia-save-title]');
  const copy = form.querySelector('[data-ia-save-copy]');
  let confirmed = Object.fromEntries(fields.map(name => [name, inputs[name].checked]));
  let desired = {...confirmed};
  let revision = Number(form.elements.namedItem('revision').value);
  let saving = false;
  let uncertain = false;
  const same = (a, b) => fields.every(name => a[name] === b[name]);
  const normalize = value => ({card_issue: Boolean(value.card_issue), card_email: Boolean(value.card_issue && value.card_email), cod_issue: Boolean(value.cod_issue), cod_email: Boolean(value.cod_issue && value.cod_email)});

  function paint() {
    for (const name of fields) {
      const locked = name.endsWith('_email') && !desired[name.replace('_email', '_issue')];
      inputs[name].checked = desired[name];
      inputs[name].disabled = uncertain || locked;
      form.querySelector(`[data-ia-row="${name}"]`).classList.toggle('is-locked', locked);
      form.querySelector(`[data-ia-state="${name}"]`).textContent = locked ? 'Activează mai întâi emiterea' : desired[name] ? 'Selectată' : 'Oprită';
    }
    form.querySelector('[data-ia-count]').textContent = fields.filter(name => desired[name]).length;
    form.elements.namedItem('revision').value = String(revision);
    form.setAttribute('aria-busy', String(saving));
    title.textContent = uncertain ? 'Salvarea nu a putut fi verificată' : saving ? 'Se salvează…' : 'Salvare automată';
    copy.textContent = 'Fără buton de salvare și fără reîncărcarea paginii.';
    reload.hidden = !uncertain;
  }
  function accept(state) {
    confirmed = normalize(state);
    revision = Number(state.revision);
    fields.forEach(name => { inputs[name].defaultChecked = confirmed[name]; });
  }
  async function request(options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(form.action, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}, ...options, signal:controller.signal});
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        const failure = new Error(payload?.error || 'Salvarea nu a putut fi confirmată.');
        failure.settings = payload?.settings;
        throw failure;
      }
      return payload.settings;
    } finally { clearTimeout(timer); }
  }
  async function saveLatest() {
    if (saving || uncertain) return;
    saving = true; paint();
    try {
      while (!same(desired, confirmed)) {
        const submitted = {...desired};
        const body = new FormData(form);
        fields.forEach(name => body.set(name, submitted[name] ? '1' : '0'));
        body.set('revision', String(revision));
        accept(await request({method:'POST', body}));
        if (same(desired, submitted)) desired = {...confirmed};
        paint();
      }
    } catch (failure) {
      if (failure.settings) { accept(failure.settings); }
      else {
        // A timeout may happen after the server committed. Re-read rather than claim a rollback.
        try { accept(await request()); } catch { uncertain = true; }
      }
      desired = {...confirmed};
      errorText.textContent = uncertain ? 'Nu putem verifica ultimele setări salvate. Reîncarcă setările când conexiunea revine.' : `${failure.message || 'Conexiunea a fost întreruptă.'} Sunt afișate setările de pe server.`;
      error.hidden = false;
    } finally { saving = false; paint(); }
  }
  form.addEventListener('change', event => {
    if (!fields.includes(event.target.name)) return;
    desired = normalize(Object.fromEntries(fields.map(name => [name, inputs[name].checked])));
    error.hidden = true; paint(); void saveLatest();
  });
  form.addEventListener('submit', event => { event.preventDefault(); void saveLatest(); });
  reload.addEventListener('click', async () => {
    reload.disabled = true;
    try { accept(await request()); desired = {...confirmed}; uncertain = false; error.hidden = true; }
    catch { errorText.textContent = 'Setările nu pot fi încărcate acum. Verifică legătura și încearcă din nou.'; }
    finally { reload.disabled = false; paint(); }
  });
  window.addEventListener('beforeunload', event => {
    if (saving || uncertain || !same(desired, confirmed)) { event.preventDefault(); event.returnValue = ''; }
  });
  form.querySelector('[data-ia-manual-save]').hidden = true;
  title.setAttribute('aria-live', 'polite');
  paint();
})();
