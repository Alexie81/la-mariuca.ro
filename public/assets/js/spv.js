(() => {
  const page = document.querySelector('[data-spv-live]');
  if (!page) return;
  page.querySelectorAll('[data-spv-rule]').forEach(rule => {
    const update = () => { rule.querySelector('[data-spv-schedule]').hidden = rule.querySelector('input[name$="_mode"]:checked')?.value !== 'scheduled'; };
    rule.addEventListener('change', update); update();
  });
  page.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
    if (!form.checkValidity()) return;
    form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
  }));
})();
