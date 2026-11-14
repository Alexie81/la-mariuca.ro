(() => {
  'use strict';
  const page = document.querySelector('[data-spv-preview]');
  if (!page) return;

  // This is intentionally a local design demonstration: no requests or persistence.
  page.querySelectorAll('[data-spv-rule]').forEach(rule => {
    const schedule = rule.querySelector('[data-spv-schedule]');
    const description = rule.querySelector('[data-spv-mode-description]');
    const sync = () => {
      const mode = rule.querySelector('input[name$="-mode"]:checked')?.value;
      const day = rule.querySelector('input[name$="-day"]:checked')?.value;
      const scheduled = mode === 'scheduled';
      schedule.hidden = !scheduled;
      description.hidden = scheduled;
      description.querySelector('p').textContent = mode === 'manual'
        ? 'Trimiți documentul din pagina facturii, atunci când alegi tu.'
        : 'Factura intră în coada de transmitere imediat după emitere.';
      rule.querySelector('[data-spv-days-summary]').textContent = `${day} ${day === '1' ? 'zi lucrătoare' : 'zile lucrătoare'}`;
    };
    rule.addEventListener('change', sync);
    sync();
  });

  const caption = page.querySelector('[data-spv-env-caption] > span');
  page.querySelectorAll('input[name="spv-environment"]').forEach(input => {
    input.addEventListener('change', () => {
      const production = input.value === 'production';
      caption.replaceChildren();
      const strong = document.createElement('strong');
      strong.textContent = production ? 'Mod Producție: ' : 'Mod Test ANAF: ';
      const note = document.createElement('em');
      note.textContent = ' Exemplu vizual, fără transmitere.';
      caption.append(strong, production
        ? 'documentele acceptate sunt transmise oficial în RO e-Factura.'
        : 'verifici documentele în mediul de test, fără transmitere oficială.', note);
    });
  });

  const dialog = page.querySelector('[data-spv-dialog]');
  if (typeof dialog.showModal !== 'function') return;
  const messages = {
    test: 'Testarea conexiunii ANAF va fi disponibilă când conectăm serviciul. Starea și datele afișate acum sunt demonstrative.',
    disconnect: 'Aici vei putea deconecta contul ANAF. În această previzualizare nu există o conexiune activă și nu se modifică niciun cont.',
    save: 'Salvarea regulilor și recalcularea cozii vor fi disponibile după integrarea ANAF. Alegerile de acum rămân doar în această previzualizare.'
  };
  page.querySelectorAll('[data-spv-demo-action]').forEach(button => {
    button.disabled = false;
    button.setAttribute('aria-haspopup', 'dialog');
    button.addEventListener('click', () => {
      dialog.querySelector('#spv-dialog-copy').textContent = messages[button.dataset.spvDemoAction];
      dialog.showModal();
    });
  });
  dialog.querySelectorAll('[data-spv-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  });
})();
