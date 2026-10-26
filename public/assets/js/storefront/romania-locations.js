let locationsPromise;

const normalize = (value) => String(value || '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/[șş]/gi, 's')
  .replace(/[țţ]/gi, 't')
  .replace(/[—-]/g, ' ')
  .replace(/[^a-z0-9]+/gi, ' ')
  .trim()
  .toLowerCase();

const fetchLocations = (source) => {
  if (!locationsPromise) {
    locationsPromise = fetch(source, { credentials: 'same-origin' })
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      })
      .then((payload) => payload.counties || {});
  }
  return locationsPromise;
};

const findOfficial = (options, value) => {
  const key = normalize(value);
  if (!key) return '';
  const exact = options.find((option) => normalize(option) === key);
  if (exact) return exact;
  if (key === 'municipiul bucuresti') return options.find((option) => normalize(option) === 'bucuresti') || '';
  const sector = key.match(/^(?:bucuresti )?sector(?:ul)? ([1-6])$/);
  return sector ? options.find((option) => normalize(option) === `bucuresti sector ${sector[1]}`) || '' : '';
};

const createOption = (value, selected, choose) => {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `ro-location-option${value === selected ? ' is-selected' : ''}`;
  button.setAttribute('role', 'option');
  button.setAttribute('aria-selected', value === selected ? 'true' : 'false');
  button.textContent = value;
  button.addEventListener('click', () => choose(value));
  return button;
};

const closeField = (field) => {
  const dropdown = field.querySelector('[data-ro-location-dropdown]');
  const input = field.querySelector('[data-ro-location-input]');
  field.classList.remove('is-open');
  if (dropdown) dropdown.hidden = true;
  input?.setAttribute('aria-expanded', 'false');
};

export const initializeRomanianLocationSelectors = (root = document) => {
  root.querySelectorAll('[data-ro-location-picker]').forEach(async (picker) => {
    if (picker.dataset.initialized === 'true') return;
    picker.dataset.initialized = 'true';
    const countyField = picker.querySelector('[data-ro-location-field="county"]');
    const localityField = picker.querySelector('[data-ro-location-field="locality"]');
    if (!countyField || !localityField) return;

    try {
      const counties = await fetchLocations(picker.dataset.source);
      const countyNames = Object.keys(counties);
      const countyInput = countyField.querySelector('[data-ro-location-input]');
      const localityInput = localityField.querySelector('[data-ro-location-input]');
      const initialCounty = findOfficial(countyNames, countyInput.value);
      if (initialCounty) countyInput.value = initialCounty;
      const initialLocality = initialCounty ? findOfficial(counties[initialCounty] || [], localityInput.value) : '';
      if (initialLocality) localityInput.value = initialLocality;

      picker.classList.add('is-enhanced');
      countyInput.readOnly = true;
      localityInput.readOnly = true;

      const closeAll = (except = null) => {
        picker.querySelectorAll('[data-ro-location-field]').forEach((field) => {
          if (field !== except) closeField(field);
        });
      };

      const setupField = (field, getOptions, onChoose) => {
        const input = field.querySelector('[data-ro-location-input]');
        const toggle = field.querySelector('[data-ro-location-toggle]');
        const dropdown = field.querySelector('[data-ro-location-dropdown]');
        const search = field.querySelector('[data-ro-location-search]');
        const optionList = field.querySelector('[data-ro-location-options]');
        const status = field.querySelector('[data-ro-location-status]');

        const render = () => {
          const options = getOptions();
          const query = normalize(search.value);
          const matches = query ? options.filter((option) => normalize(option).includes(query)) : options;
          const visible = matches.slice(0, 100);
          optionList.replaceChildren();
          visible.forEach((value) => optionList.append(createOption(value, input.value, (selected) => {
            input.value = selected;
            field.classList.remove('is-invalid');
            input.dispatchEvent(new Event('change', { bubbles: true }));
            onChoose(selected);
            closeField(field);
          })));
          if (visible.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'ro-location-empty';
            empty.textContent = 'Nu am găsit nicio potrivire.';
            optionList.append(empty);
          }
          status.textContent = matches.length > visible.length
            ? `${matches.length} rezultate · continuă să scrii pentru a restrânge lista`
            : `${matches.length} ${matches.length === 1 ? 'rezultat' : 'rezultate'}`;
        };

        const open = () => {
          if (field.classList.contains('is-disabled')) return;
          closeAll(field);
          field.classList.add('is-open');
          dropdown.hidden = false;
          input.setAttribute('aria-expanded', 'true');
          search.value = '';
          render();
          window.requestAnimationFrame(() => search.focus());
        };
        toggle.addEventListener('click', () => field.classList.contains('is-open') ? closeField(field) : open());
        input.addEventListener('click', open);
        input.addEventListener('keydown', (event) => {
          if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            open();
          }
        });
        search.addEventListener('input', render);
        search.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            closeField(field);
            input.focus();
          } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            optionList.querySelector('button')?.focus();
          }
        });
        optionList.addEventListener('keydown', (event) => {
          const buttons = [...optionList.querySelectorAll('button')];
          const index = buttons.indexOf(document.activeElement);
          if (event.key === 'ArrowDown' && buttons[index + 1]) {
            event.preventDefault();
            buttons[index + 1].focus();
          } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            (buttons[index - 1] || search).focus();
          } else if (event.key === 'Escape') {
            closeField(field);
            input.focus();
          }
        });
      };

      const updateLocalityState = () => {
        const hasCounty = Object.hasOwn(counties, countyInput.value);
        localityField.classList.toggle('is-disabled', !hasCounty);
        localityInput.placeholder = hasCounty ? 'Alege localitatea' : 'Alege întâi județul';
      };
      setupField(countyField, () => countyNames, (selected) => {
        if (selected !== initialCounty || !findOfficial(counties[selected] || [], localityInput.value)) localityInput.value = '';
        updateLocalityState();
      });
      setupField(localityField, () => counties[countyInput.value] || [], () => {});
      countyInput.addEventListener('change', updateLocalityState);
      updateLocalityState();
      if (countyInput.value && !initialCounty) countyField.classList.add('is-invalid');
      if (localityInput.value && !initialLocality) localityField.classList.add('is-invalid');

      document.addEventListener('click', (event) => {
        if (!picker.contains(event.target)) closeAll();
      });
    } catch (error) {
      picker.dataset.locationError = 'true';
    }
  });
};
