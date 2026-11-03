import { initializeRomanianLocationSelectors, setRomanianLocation } from './romania-locations.js?v=20261103-locations4';

initializeRomanianLocationSelectors();
const form = document.querySelector('[data-checkout-form]');
if (form instanceof HTMLFormElement) {
  const picker = form.querySelector('[data-ro-location-picker]');
  const addressSelector = form.querySelector('[data-checkout-saved-address]');
  const field = (name) => form.elements.namedItem(name);
  const assign = (name, value) => { const input = field(name); if (input instanceof HTMLInputElement) input.value = value || ''; };
  const message = (node, text, type = '') => { if (node) { node.textContent = text; node.classList.toggle('is-error', type === 'error'); node.classList.toggle('is-success', type === 'success'); } };
  const money = (bani) => `${new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(bani) / 100)} Lei`;
  const updateTotals = () => {
    const selected = form.querySelector('[name="shipping_method_id"]:checked');
    if (!selected) return;
    const shipping = document.querySelector('[data-checkout-shipping]');
    const name = document.querySelector('[data-checkout-shipping-name]');
    const total = document.querySelector('[data-checkout-total]');
    if (shipping) shipping.textContent = Number(selected.dataset.shippingBani) === 0 ? 'Gratuit' : money(selected.dataset.shippingBani);
    if (name) name.textContent = selected.dataset.shippingName;
    if (total) total.textContent = money(selected.dataset.totalBani);
  };
  form.querySelectorAll('[name="shipping_method_id"]').forEach((radio) => radio.addEventListener('change', updateTotals));
  updateTotals();

  const postal = form.querySelector('[data-postal-lookup]');
  const postalInput = field('postal_code');
  const postalButton = postal?.querySelector('[data-postal-search]');
  const postalStatus = postal?.querySelector('[data-postal-status]');
  const postalResults = postal?.querySelector('[data-postal-results]');
  let lookupController;
  let lookupTimer;
  let lookupVersion = 0;
  let lastSearched = '';
  const cancelLookup = () => {
    lookupVersion++;
    clearTimeout(lookupTimer);
    lookupController?.abort();
    lastSearched = '';
    if (postalResults) { postalResults.replaceChildren(); postalResults.hidden = true; }
    if (postalButton) postalButton.disabled = false;
  };
  const canAutofill = () => !addressSelector?.value && ['county', 'city', 'address'].every((name) => !field(name)?.value.trim());
  const applyMatch = async (match) => {
    const code = postalInput.value.trim();
    await initializeRomanianLocationSelectors();
    if (postalInput.value.trim() !== code) return;
    if (!canAutofill()) {
      message(postalStatus, 'Ai deja o adresă completată. Am păstrat-o; verifică dacă se potrivește cu acest cod poștal.');
      return;
    }
    const valid = await setRomanianLocation(picker, { county: match.county, city: match.city });
    if (!valid) { message(postalStatus, 'Alege manual județul și localitatea pentru acest cod poștal.'); return; }
    postalResults.hidden = true;
    message(postalStatus, `${match.city}, ${match.county} — completate. Mai adaugă strada și numărul.`, 'success');
  };
  const lookup = async (explicit = false) => {
    const code = postalInput.value.trim();
    if (!/^\d{6}$/.test(code)) { if (explicit) message(postalStatus, 'Pentru căutare, introdu 6 cifre. Poți continua și fără cod poștal.', 'error'); return; }
    if (!explicit && code === lastSearched) return;
    cancelLookup();
    const version = lookupVersion;
    const initiallyEmpty = canAutofill();
    lookupController = new AbortController();
    const timeout = setTimeout(() => lookupController?.abort(), 8000);
    lastSearched = code;
    postalButton.disabled = true;
    message(postalStatus, 'Căutăm localitatea pentru acest cod poștal…');
    try {
      const url = new URL(postal.dataset.endpoint, window.location.origin); url.searchParams.set('cod', code);
      const response = await fetch(url, { credentials: 'same-origin', signal: lookupController.signal, headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('lookup');
      const data = await response.json();
      if (version !== lookupVersion || postalInput.value.trim() !== code) return;
      const matches = Array.isArray(data.matches) ? data.matches : [];
      if (!matches.length) { message(postalStatus, 'Nu am găsit o localitate pentru acest cod. Poți completa adresa manual.'); return; }
      if (!initiallyEmpty || !canAutofill()) {
        const same = matches.some((match) => match.county === field('county').value && match.city === field('city').value);
        message(postalStatus, same ? 'Codul poștal corespunde localității alese.' : 'Codul poștal indică: ' + matches.map((match) => `${match.city}, ${match.county}`).join(' / ') + '. Am păstrat adresa completată.', same ? 'success' : '');
        return;
      }
      if (matches.length === 1) { await applyMatch(matches[0]); return; }
      message(postalStatus, 'Acest cod este folosit în mai multe localități. Alege destinația ta:');
      matches.forEach((match) => {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = `${match.city} · ${match.county}`;
        button.addEventListener('click', () => applyMatch(match)); postalResults.append(button);
      });
      postalResults.hidden = false;
    } catch (error) {
      if (version === lookupVersion) { lastSearched = ''; message(postalStatus, 'Căutarea nu este disponibilă acum. Poți alege manual județul și localitatea.'); }
    } finally {
      clearTimeout(timeout);
      if (version === lookupVersion) postalButton.disabled = false;
    }
  };
  if (postalButton && postalInput) {
    postalButton.hidden = false;
    postalButton.addEventListener('click', () => lookup(true));
    postalInput.addEventListener('input', () => { cancelLookup(); message(postalStatus, 'Județul și localitatea se pot alege direct, fără cod poștal.'); if (/^\d{6}$/.test(postalInput.value.trim())) lookupTimer = setTimeout(() => lookup(), 400); });
    postalInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); lookup(true); } });
  }

  const savePanel = form.querySelector('[data-checkout-save-address]');
  const saveButton = savePanel?.querySelector('[data-address-save-button]');
  const saveStatus = savePanel?.querySelector('[data-address-save-status]');
  const showSavePanel = () => { if (savePanel) savePanel.hidden = Boolean(addressSelector?.value); };
  showSavePanel();
  if (addressSelector instanceof HTMLSelectElement) {
    addressSelector.addEventListener('change', async () => {
      cancelLookup();
      message(postalStatus, 'Județul și localitatea se pot alege direct, fără cod poștal.');
      message(saveStatus, '');
      const option = addressSelector.selectedOptions[0];
      if (!option || option.value === '') {
        assign('address', ''); assign('postal_code', '');
        await setRomanianLocation(picker, { county: '', city: '' });
        showSavePanel(); field('county')?.focus(); return;
      }
      assign('first_name', option.dataset.firstName); assign('last_name', option.dataset.lastName); assign('phone', option.dataset.phone);
      assign('address', option.dataset.address); assign('postal_code', option.dataset.postalCode);
      await setRomanianLocation(picker, { county: option.dataset.county, city: option.dataset.city });
      showSavePanel();
    });
  }
  if (savePanel && saveButton) {
    ['address', 'county', 'city', 'postal_code', 'first_name', 'last_name', 'phone'].forEach((name) => ['input', 'change'].forEach((eventName) => field(name)?.addEventListener(eventName, () => { savePanel.hidden = false; saveButton.hidden = false; message(saveStatus, ''); })));
    saveButton.addEventListener('click', async () => {
      const names = ['first_name', 'last_name', 'phone', 'address', 'county', 'city', 'postal_code'];
      for (const name of names) {
        const input = field(name);
        if ((input.required && !input.value.trim()) || !input.checkValidity()) { message(saveStatus, 'Completează datele de contact și adresa înainte de salvare.', 'error'); input.focus(); input.reportValidity(); return; }
      }
      const data = new URLSearchParams();
      [...names, '_csrf'].forEach((name) => data.set(name, field(name).value));
      data.set('label', savePanel.querySelector('[data-address-label]').value);
      saveButton.disabled = true; message(saveStatus, 'Salvăm adresa în cont…');
      try {
        const response = await fetch(savePanel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data });
        if (response.status === 419) throw new Error('Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.');
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Nu am putut salva adresa. Încearcă din nou puțin mai târziu.');
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Adresa nu a putut fi salvată. Încearcă din nou.');
        const saved = result.address;
        const option = document.createElement('option'); option.value = String(saved.id); option.textContent = `${saved.label} · ${saved.details.city}`;
        Object.entries({ firstName: saved.details.first_name, lastName: saved.details.last_name, phone: saved.details.phone, address: saved.details.address, county: saved.details.county, city: saved.details.city, postalCode: saved.details.postal_code }).forEach(([key, value]) => { option.dataset[key] = value || ''; });
        addressSelector.append(option); addressSelector.value = option.value;
        message(saveStatus, result.message, 'success');
        saveButton.hidden = true;
        addressSelector.addEventListener('change', () => { saveButton.hidden = false; }, { once: true });
      } catch (error) { message(saveStatus, error.message || 'Nu am putut salva adresa. Încearcă din nou.', 'error'); }
      finally { saveButton.disabled = false; }
    });
  }
}
