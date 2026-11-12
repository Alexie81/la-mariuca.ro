(() => {
  'use strict';
  const baselines = new WeakMap();
  const fileIds = new WeakMap();
  let nextFileId = 0;
  const dialog = document.querySelector('[data-nir-unsaved-dialog]');
  let pending = null;

  // Compare submitted data, including programmatic product/import changes and files.
  // Navigation, expanded sections and searches do not make a form dirty.
  function snapshot(form) {
    return JSON.stringify(Array.from(form.elements).filter(field => field.name && !field.disabled &&
      !['submit', 'button', 'reset'].includes(field.type) && field.name !== '_csrf').map(field => {
      if (field.type === 'file') return [field.name, Array.from(field.files || []).map(file => {
        if (!fileIds.has(file)) fileIds.set(file, ++nextFileId);
        return [fileIds.get(file), file.name, file.size, file.lastModified];
      })];
      if (['checkbox', 'radio'].includes(field.type)) return [field.name, field.checked ? field.value : null];
      if (field.multiple && field.options) return [field.name, Array.from(field.options).filter(option => option.selected).map(option => option.value)];
      return [field.name, field.value];
    }));
  }
  function track(form) { if (form && !baselines.has(form)) baselines.set(form, snapshot(form)); }
  function isDirty(form) { return !!form && baselines.has(form) && baselines.get(form) !== snapshot(form); }
  function markSaved(form) { if (form) baselines.set(form, snapshot(form)); }
  function requestClose(form, discard) {
    if (!isDirty(form)) return true;
    if (pending) return false;
    if (!dialog?.showModal) {
      if (!window.confirm('Sigur vrei să renunți la modificările nesalvate?')) return false;
      markSaved(form); return true;
    }
    pending = {form, discard, focus: document.activeElement};
    dialog.showModal();
    dialog.querySelector('[data-nir-unsaved-keep]').focus({preventScroll: true});
    return false;
  }
  function resolve(discard) {
    const action = pending;
    if (!action) return;
    pending = null; dialog.close();
    if (discard) { markSaved(action.form); action.discard(); }
    else if (action.focus?.isConnected) action.focus.focus({preventScroll: true});
  }
  dialog?.querySelector('[data-nir-unsaved-keep]')?.addEventListener('click', () => resolve(false));
  dialog?.querySelector('[data-nir-unsaved-discard]')?.addEventListener('click', () => resolve(true));
  dialog?.addEventListener('cancel', event => { event.preventDefault(); event.stopPropagation(); resolve(false); });
  window.addEventListener('beforeunload', event => {
    if (Array.from(document.querySelectorAll('[data-nir-form]')).some(isDirty)) {
      event.preventDefault(); event.returnValue = '';
    }
  });
  window.NirUnsaved = {track, isDirty, markSaved, requestClose};
})();
