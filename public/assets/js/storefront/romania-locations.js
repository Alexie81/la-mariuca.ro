const datasets = new Map();
const pickers = new WeakMap();

const normalize = (value) => String(value || '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/[șş]/gi, 's')
  .replace(/[țţ]/gi, 't')
  .replace(/[^a-z0-9]+/gi, ' ')
  .trim()
  .toLowerCase();

const fetchLocations = (source) => {
  if (!datasets.has(source)) {
    datasets.set(source, fetch(source, { credentials: 'same-origin' })
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then((payload) => {
        if (!payload.counties || !Object.keys(payload.counties).length) throw new Error('Missing locations');
        return payload.counties;
      })
      .catch((error) => { datasets.delete(source); throw error; }));
  }
  return datasets.get(source);
};

const findOfficial = (options, value) => {
  const key = normalize(value);
  if (!key) return '';
  const exact = options.find((option) => normalize(option) === key);
  if (exact) return exact;
  if (key === 'municipiul bucuresti') return options.find((option) => normalize(option) === 'bucuresti') || '';
  const sector = key.match(/^(?:bucuresti )?sector(?:ul)?\s*([1-6])$/);
  return sector ? options.find((option) => normalize(option) === `bucuresti sector ${sector[1]}`) || '' : '';
};

const initializePicker = (picker) => {
  if (pickers.has(picker)) return pickers.get(picker);
  const initialization = (async () => {
    const countyField = picker.querySelector('[data-ro-location-field="county"]');
    const localityField = picker.querySelector('[data-ro-location-field="locality"]');
    if (!countyField || !localityField) return null;
    const countyInput = countyField.querySelector('[data-ro-location-input]');
    const localityInput = localityField.querySelector('[data-ro-location-input]');
    const counties = await fetchLocations(picker.dataset.source);
    const countyNames = Object.keys(counties);
    const fields = [];
    let previousCounty = '';
    let synchronizing = false;

    const closeAll = (except = null) => fields.forEach((field) => {
      if (field.element !== except) field.close();
    });

    const updateLocalityState = () => {
      const available = Object.hasOwn(counties, countyInput.value);
      localityField.classList.toggle('is-disabled', !available);
      localityInput.placeholder = available ? 'Alege localitatea' : 'Alege întâi județul';
      localityInput.setAttribute('aria-disabled', String(!available));
      localityField.querySelector('[data-ro-location-toggle]').disabled = !available;
      if (!available) fields.find((field) => field.element === localityField)?.close();
    };

    const markValidity = () => {
      const countyValid = Boolean(findOfficial(countyNames, countyInput.value));
      const cityValid = Boolean(findOfficial(counties[countyInput.value] || [], localityInput.value));
      [[countyField, countyInput, countyValid], [localityField, localityInput, cityValid]].forEach(([field, input, valid]) => {
        const invalid = Boolean(input.value) && !valid;
        field.classList.toggle('is-invalid', invalid);
        input.setAttribute('aria-invalid', String(invalid));
      });
      return countyValid && cityValid;
    };

    const setLocation = ({ county = '', city = '' }, notify = true) => {
      synchronizing = true;
      countyInput.value = findOfficial(countyNames, county) || county;
      localityInput.value = findOfficial(counties[countyInput.value] || [], city) || city;
      previousCounty = countyInput.value;
      updateLocalityState();
      const valid = markValidity();
      closeAll();
      if (notify) {
        countyInput.dispatchEvent(new Event('change', { bubbles: true }));
        localityInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
      synchronizing = false;
      return valid;
    };

    const setupField = (element, getOptions) => {
      const input = element.querySelector('[data-ro-location-input]');
      const toggle = element.querySelector('[data-ro-location-toggle]');
      const dropdown = element.querySelector('[data-ro-location-dropdown]');
      const control = element.querySelector('.ro-location-control');
      const search = element.querySelector('[data-ro-location-search]');
      const optionList = element.querySelector('[data-ro-location-options]');
      const status = element.querySelector('[data-ro-location-status]');
      const closeButton = element.querySelector('[data-ro-location-close]');
      let visible = [];

      const close = (restoreFocus = false) => {
        element.classList.remove('is-open');
        dropdown.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) input.focus({ preventScroll: true });
      };
      const choose = (value) => {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close(true);
      };
      const render = () => {
        const options = getOptions();
        const query = normalize(search.value);
        const matches = query ? options.filter((option) => normalize(option).includes(query)) : options;
        // Exact matches precede prefixes and substring matches without changing the official labels.
        if (query) matches.sort((a, b) => Number(normalize(b) === query) - Number(normalize(a) === query)
          || Number(normalize(b).startsWith(query)) - Number(normalize(a).startsWith(query)));
        visible = matches.slice(0, 100);
        optionList.replaceChildren();
        visible.forEach((value, index) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.tabIndex = -1;
          button.id = `${optionList.id}-${index}`;
          button.className = `ro-location-option${value === input.value ? ' is-selected' : ''}`;
          button.setAttribute('role', 'option');
          button.setAttribute('aria-selected', String(value === input.value));
          button.textContent = value;
          button.addEventListener('click', () => choose(value));
          optionList.append(button);
        });
        if (!visible.length) {
          const empty = document.createElement('p');
          empty.className = 'ro-location-empty';
          empty.textContent = 'Nicio potrivire. Încearcă un alt nume.';
          optionList.append(empty);
        }
        status.textContent = matches.length > visible.length
          ? `${matches.length} rezultate · scrie pentru a restrânge lista`
          : `${matches.length} ${matches.length === 1 ? 'rezultat' : 'rezultate'}`;
        optionList.scrollTop = 0;
      };
      const open = () => {
        if (element.classList.contains('is-disabled')) {
          fields.find((field) => field.element === countyField)?.open();
          return;
        }
        closeAll(element);
        element.classList.add('is-open');
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-expanded', 'true');
        search.value = '';
        render();
        if (!window.matchMedia('(max-width: 760px)').matches) {
          const headerBottom = Math.max(0, document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0);
          const initialRect = control.getBoundingClientRect();
          if (initialRect.top < headerBottom + 18) window.scrollBy({ top: initialRect.top - headerBottom - 24, behavior: 'auto' });
          const controlRect = control.getBoundingClientRect();
          const preferredHeight = Math.min(410, dropdown.scrollHeight);
          const spaceBelow = Math.max(0, window.innerHeight - controlRect.bottom - 14);
          const spaceAbove = Math.max(0, controlRect.top - headerBottom - 14);
          const opensUp = spaceBelow < preferredHeight && spaceAbove > spaceBelow;
          element.classList.toggle('opens-up', opensUp);
          dropdown.style.setProperty('--ro-location-space', `${Math.max(170, opensUp ? spaceAbove : spaceBelow)}px`);
        }
        search.focus({ preventScroll: true });
      };

      toggle.addEventListener('click', () => element.classList.contains('is-open') ? close(true) : open());
      closeButton?.addEventListener('click', () => close(true));
      input.addEventListener('click', open);
      input.addEventListener('keydown', (event) => {
        if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
          event.preventDefault();
          open();
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault();
          open();
          search.value = event.key;
          render();
        }
      });
      search.addEventListener('input', render);
      search.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          const options = optionList.querySelectorAll('button');
          options[event.key === 'ArrowUp' ? options.length - 1 : 0]?.focus();
        } else if (event.key === 'Enter') {
          event.preventDefault();
          if (visible.length) choose(visible[0]);
        }
      });
      optionList.addEventListener('keydown', (event) => {
        const buttons = [...optionList.querySelectorAll('button')];
        const index = buttons.indexOf(document.activeElement);
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          buttons[Math.min(index + 1, buttons.length - 1)]?.focus();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          (buttons[index - 1] || search).focus();
        } else if (event.key === 'Home' || event.key === 'End') {
          event.preventDefault();
          buttons[event.key === 'Home' ? 0 : buttons.length - 1]?.focus();
        } else if (event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault();
          search.focus();
          search.value += event.key;
          render();
        }
      });
      dropdown.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          close(true);
        } else if (event.key === 'Tab' && window.matchMedia('(max-width: 760px)').matches) {
          event.preventDefault();
          (document.activeElement === search ? closeButton : search)?.focus();
        }
      });
      element.addEventListener('focusout', (event) => {
        // A pointer selection moves focus from search to an option before click.
        // During focusout activeElement can still be body; keep the option alive.
        if (event.relatedTarget instanceof Node && element.contains(event.relatedTarget)) return;
        window.requestAnimationFrame(() => {
          if (element.classList.contains('is-open') && !element.contains(document.activeElement)) close();
        });
      });
      return { element, close, open };
    };

    fields.push(setupField(countyField, () => countyNames));
    fields.push(setupField(localityField, () => counties[countyInput.value] || []));
    countyInput.addEventListener('change', () => {
      if (synchronizing) return;
      countyInput.value = findOfficial(countyNames, countyInput.value) || countyInput.value;
      if (countyInput.value !== previousCounty) {
        localityInput.value = '';
        localityInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
      previousCounty = countyInput.value;
      updateLocalityState();
      markValidity();
    });
    localityInput.addEventListener('change', () => {
      if (synchronizing) return;
      localityInput.value = findOfficial(counties[countyInput.value] || [], localityInput.value) || localityInput.value;
      markValidity();
    });
    picker.closest('form')?.addEventListener('submit', (event) => {
      if (markValidity()) return;
      event.preventDefault();
      const invalid = !findOfficial(countyNames, countyInput.value) ? fields[0] : fields[1];
      invalid.element.classList.add('is-invalid');
      invalid.element.querySelector('[data-ro-location-input]').setAttribute('aria-invalid', 'true');
      invalid.open();
    });
    picker.closest('form')?.addEventListener('reset', () => queueMicrotask(() => setLocation({ county: countyInput.value, city: localityInput.value }, false)));
    document.addEventListener('click', (event) => {
      const opened = fields.find((field) => field.element.classList.contains('is-open'));
      if (!opened) return;
      const control = opened.element.querySelector('.ro-location-control');
      const dropdown = opened.element.querySelector('[data-ro-location-dropdown]');
      if (!control.contains(event.target) && !dropdown.contains(event.target)) opened.close();
    });
    const desktopMedia = window.matchMedia('(min-width: 761px)');
    desktopMedia.addEventListener('change', () => closeAll());
    picker.classList.add('is-enhanced');
    countyInput.readOnly = true;
    localityInput.readOnly = true;
    setLocation({ county: countyInput.value, city: localityInput.value }, false);
    picker.dataset.initialized = 'true';
    return { setLocation };
  })().catch(() => {
    picker.dataset.locationError = 'true';
    pickers.delete(picker);
    return null;
  });
  pickers.set(picker, initialization);
  return initialization;
};

export const initializeRomanianLocationSelectors = (root = document) => Promise.all(
  [...(root.matches?.('[data-ro-location-picker]') ? [root] : []), ...root.querySelectorAll('[data-ro-location-picker]')].map(initializePicker),
);

// Set the pair together so changing the county cannot erase the newly assigned locality.
export const setRomanianLocation = async (picker, location) => {
  if (!picker) return false;
  const controller = await initializePicker(picker);
  if (controller) return controller.setLocation(location);
  const county = picker.querySelector('[name="county"]');
  const city = picker.querySelector('[name="city"]');
  if (county) county.value = location.county || '';
  if (city) city.value = location.city || '';
  return false;
};
