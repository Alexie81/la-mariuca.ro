(() => {
  'use strict';
  let active = null;
  document.querySelectorAll('[data-stock-select]').forEach((field, index) => {
    const select = field.querySelector('select');
    const label = field.querySelector('label').textContent.trim();
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'stock-select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    const caption = document.createElement('small');
    caption.textContent = label;
    const value = document.createElement('span');
    trigger.append(caption, value);
    const menu = document.createElement('div');
    menu.className = 'stock-select-menu';
    menu.id = `stock-select-options-${index}`;
    menu.setAttribute('role', 'listbox');
    menu.setAttribute('aria-label', label);
    menu.hidden = true;
    trigger.setAttribute('aria-controls', menu.id);
    const options = [...select.options].map(option => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'stock-select-option';
      button.setAttribute('role', 'option');
      button.tabIndex = -1;
      button.textContent = option.textContent;
      button.addEventListener('click', () => {
        const changed = select.value !== option.value;
        select.value = option.value;
        sync();
        close(true);
        if (changed) select.dispatchEvent(new Event('change', {bubbles: true}));
      });
      menu.append(button);
      return button;
    });
    function sync() {
      value.textContent = select.selectedOptions[0]?.textContent || '';
      trigger.setAttribute('aria-label', `${label}: ${value.textContent}`);
      options.forEach((option, i) => option.setAttribute('aria-selected', String(i === select.selectedIndex)));
    }
    function close(focus = false) {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      field.classList.remove('is-open');
      if (active?.menu === menu) active = null;
      if (focus) trigger.focus({preventScroll: true});
    }
    function open() {
      active?.close();
      menu.hidden = false;
      const rect = trigger.getBoundingClientRect();
      const width = Math.min(Math.max(rect.width, 190), document.documentElement.clientWidth - 24);
      menu.style.width = `${width}px`;
      menu.style.left = `${Math.max(12, Math.min(rect.left, document.documentElement.clientWidth - width - 12))}px`;
      const below = innerHeight - rect.bottom - 12;
      const above = rect.top - 12;
      const upward = below < menu.scrollHeight && above > below;
      menu.style.maxHeight = `${Math.max(60, Math.min(280, upward ? above - 6 : below - 6))}px`;
      menu.style.top = `${upward ? Math.max(12, rect.top - menu.offsetHeight - 6) : rect.bottom + 6}px`;
      field.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      active = {menu, trigger, close};
      options[Math.max(0, select.selectedIndex)].focus({preventScroll: true});
    }
    trigger.addEventListener('click', () => menu.hidden ? open() : close(true));
    trigger.addEventListener('keydown', event => {
      if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        open();
        if (event.key === 'Home') options[0].focus();
        if (event.key === 'End') options[options.length - 1].focus();
      }
    });
    menu.addEventListener('keydown', event => {
      const current = options.indexOf(document.activeElement);
      let next = current;
      if (event.key === 'ArrowDown') next = (current + 1) % options.length;
      else if (event.key === 'ArrowUp') next = (current - 1 + options.length) % options.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = options.length - 1;
      else if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
      else if (event.key === 'Tab') { close(true); return; }
      else if (event.key.length === 1 && event.key !== ' ') {
        const match = options.find(option => option.textContent.toLocaleLowerCase('ro').startsWith(event.key.toLocaleLowerCase('ro')));
        if (match) { event.preventDefault(); match.focus(); }
        return;
      } else return;
      event.preventDefault();
      options[next].focus();
    });
    select.addEventListener('change', sync);
    document.body.append(menu);
    field.append(trigger);
    select.hidden = true;
    field.classList.add('is-enhanced');
    sync();
  });
  document.addEventListener('pointerdown', event => {
    if (active && !active.menu.contains(event.target) && !active.trigger.contains(event.target)) active.close();
  });
  document.addEventListener('focusin', event => {
    if (active && !active.menu.contains(event.target) && !active.trigger.contains(event.target)) active.close();
  });
  document.addEventListener('scroll', event => {
    if (active && !active.menu.contains(event.target)) active.close();
  }, true);
  window.addEventListener('resize', () => active?.close());
  document.querySelectorAll('[data-stock-page-size]').forEach(form => {
    form.querySelector('[data-stock-page-size-apply]').hidden = true;
    form.querySelector('select').addEventListener('change', () => form.requestSubmit());
  });
})();
