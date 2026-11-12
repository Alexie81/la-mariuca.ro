(() => {
  'use strict';
  const all = (selector, root) => Array.from(root.querySelectorAll(selector));
  const field = (form, name) => form.elements.namedItem(name);
  const value = (form, name) => String(field(form, name)?.value || '').trim();
  const labels = ['Factura', 'Produsele', 'Recepția', 'Verificarea finală'];

  function show(form, step, focus = false) {
    if (!form?.matches('[data-nir-editor]')) return;
    step = Math.max(1, Math.min(4, Number(step) || 1));
    form.dataset.flowCurrent = String(step);
    all('[data-flow-step]', form).forEach(section => { section.hidden = Number(section.dataset.flowStep) !== step; });
    all('.nir-flow-nav [data-flow-go]', form).forEach(button => {
      const active = Number(button.dataset.flowGo) === step;
      button.classList.toggle('is-current', active);
      if (active) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current');
    });
    const back = form.querySelector('[data-flow-back]'), next = form.querySelector('[data-flow-next]');
    if (back) back.disabled = step === 1;
    if (next) next.hidden = step === 4;
    const confirm = form.querySelector('[data-flow-confirm]');
    if (confirm) confirm.hidden = step !== 4;
    const caption = form.querySelector('[data-flow-caption]');
    if (caption) caption.textContent = `Pasul ${step} din 4 · ${labels[step - 1]}`;
    const intro = form.querySelector('[data-flow-intro]'); if (intro) intro.hidden = step !== 1;
    const body = form.querySelector('.nir-form__body'); if (body) body.scrollTop = 0;
    refresh(form);
    if (focus) {
      const heading = form.querySelector(`[data-flow-step="${step}"] h3`);
      if (heading) { heading.tabIndex = -1; heading.focus({preventScroll:true}); }
    }
  }

  function reveal(element) {
    const form = element?.closest('[data-nir-editor]'); if (!form) return;
    const section = element.closest('[data-flow-step]');
    if (section) show(form, section.dataset.flowStep);
    let parent = element.parentElement;
    while (parent && parent !== form) { if (parent.tagName === 'DETAILS') { if (window.NirDisclosure) window.NirDisclosure.open(parent); else parent.open = true; } parent = parent.parentElement; }
  }

  function refresh(form) {
    if (!form?.matches('[data-nir-editor]')) return;
    const lines = all('[data-nir-line]', form);
    const selected = name => {
      const select = field(form, name);
      return select?.value ? select.selectedOptions?.[0]?.textContent.trim() || select.value : '';
    };
    const supplier = selected('supplier_id');
    const invoice = [value(form, 'invoice_series'), value(form, 'invoice_number')].filter(Boolean).join(' ');
    const destination = selected('location_id');
    const savedFiles = all('[data-nir-file-list] > span:not([data-local-file])', form).length;
    const fileCount = savedFiles + (form.querySelector('[data-nir-files]')?.files?.length || 0);
    const mapped = lines.filter(line => line.querySelector('[name$="[variant_id]"]')?.value || !line.querySelector('[data-line-tracked]')?.checked).length;
    const reviews = {
      invoice: supplier && invoice ? `${supplier} · ${invoice}` : 'Completează furnizorul și numărul facturii',
      products: `${lines.length} ${lines.length === 1 ? 'produs' : 'produse'} · ${mapped} ${mapped === 1 ? 'asociat' : 'asociate'}`,
      destination: destination || 'Alege locația de recepție',
      files: fileCount ? `${fileCount} ${fileCount === 1 ? 'fișier' : 'fișiere'}` : 'Fără fișiere atașate · opțional',
    };
    all('[data-flow-review]', form).forEach(node => { node.textContent = reviews[node.dataset.flowReview] || '—'; });
    const complete = [Boolean(supplier && invoice && value(form,'invoice_date')), lines.length > 0 && mapped === lines.length && lines.every(line => line.querySelector('[data-line-name]')?.value.trim()), Boolean(destination && value(form,'warehouse_id') && value(form,'nir_at') && value(form,'received_at')), false];
    all('.nir-flow-nav [data-flow-go]', form).forEach(button => button.classList.toggle('is-filled', Boolean(complete[Number(button.dataset.flowGo) - 1])));
  }

  function next(form) {
    if (form.getAttribute('aria-busy') === 'true') return;
    const step = Number(form.dataset.flowCurrent || 1);
    if (step === 2) {
      const unmatched = all('[data-nir-line]', form).find(line => line.querySelector('[data-line-tracked]')?.checked && !line.querySelector('[name$="[variant_id]"]')?.value);
      if (unmatched) {
        reveal(unmatched.querySelector('[data-nir-product-picker]'));
        const error = unmatched.querySelector('[data-line-error]');
        if (error) { error.textContent = 'Alege produsul din magazin, pentru a ști unde va intra cantitatea recepționată.'; error.hidden = false; }
        unmatched.querySelector('[data-nir-product-picker]')?.focus();
        return;
      }
    }
    const required = all(`[data-flow-step="${step}"] input[required], [data-flow-step="${step}"] select[required], [data-flow-step="${step}"] textarea[required]`, form);
    required.forEach(input => { if (input.dataset.flowValidation) { input.setCustomValidity?.(''); delete input.dataset.flowValidation; } });
    const invalid = required.find(input => !input.disabled && !input.checkValidity());
    if (invalid) {
      reveal(invalid);
      invalid.setCustomValidity?.(invalid.tagName === 'SELECT' ? 'Alege o opțiune pentru a continua.' : 'Completează corect acest câmp pentru a continua.');
      invalid.dataset.flowValidation = '1';
      invalid.reportValidity();
      return;
    }
    show(form, step + 1, true);
  }

  function init(form) {
    if (!form.matches('[data-nir-editor]') || form.dataset.flowReady) return;
    form.dataset.flowReady = '1';
    form.classList.add('is-guided');
    show(form, 1);
    let firstError;
    try {
      for (const key of JSON.parse(form.dataset.nirErrorFields || '[]')) {
        const input = field(form, key.replace(/^lines\.(\d+)\.(.+)$/, 'lines[$1][$2]'));
        if (input?.setAttribute) { input.setAttribute('aria-invalid', 'true'); firstError ||= input; }
      }
    } catch { /* The server's error summary remains available. */ }
    const invalid = firstError || form.querySelector('[aria-invalid="true"]'); if (invalid) reveal(invalid);
  }

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-flow-go], [data-flow-back], [data-flow-next]');
    const form = button?.closest('[data-nir-editor]'); if (!form || form.getAttribute('aria-busy') === 'true') return;
    event.preventDefault();
    if (button.hasAttribute('data-flow-next')) next(form);
    else show(form, button.dataset.flowGo || Number(form.dataset.flowCurrent) - 1, true);
  });
  document.addEventListener('invalid', event => {
    const form = event.target.closest('[data-nir-editor]');
    if (!form || form.dataset.flowInvalid) return;
    form.dataset.flowInvalid = '1'; reveal(event.target);
    queueMicrotask(() => { delete form.dataset.flowInvalid; });
  }, true);
  ['input','change'].forEach(type => document.addEventListener(type, event => {
    if (event.target.dataset?.flowValidation) { event.target.setCustomValidity(''); delete event.target.dataset.flowValidation; }
    const form = event.target.closest('[data-nir-editor]');
    if (form) queueMicrotask(() => refresh(form));
  }));
  window.NirFlow = {init, show, refresh, reveal};
})();
