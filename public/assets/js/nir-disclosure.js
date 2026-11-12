(() => {
  'use strict';
  const running = new WeakMap();
  const selector = 'details[data-nir-disclosure], details.nir-item-inspection';
  function settle(details, expanded) {
    const state = running.get(details);
    running.delete(details);
    if (state) state.animation.cancel();
    details.open = expanded;
    details.style.height = '';
    details.style.overflow = '';
    details.removeAttribute('data-disclosure-closing');
  }
  function toggle(details) {
    const summary = details.querySelector(':scope > summary');
    if (!summary) return;
    const state = running.get(details);
    const expand = state ? !state.expand : !details.open;
    const from = details.getBoundingClientRect().height;
    if (state) { running.delete(details); state.animation.cancel(); }
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || !details.animate) { settle(details, expand); return; }
    details.style.height = '';
    details.open = false;
    const closed = details.getBoundingClientRect().height;
    details.open = true;
    const opened = details.getBoundingClientRect().height;
    details.style.overflow = 'hidden';
    details.toggleAttribute('data-disclosure-closing', !expand);
    const animation = details.animate([{height: `${from}px`}, {height: `${expand ? opened : closed}px`}], {
      duration: 290, easing: 'cubic-bezier(.22,.72,.25,1)', fill: 'both'
    });
    running.set(details, {animation, expand});
    animation.onfinish = () => { if (running.get(details)?.animation === animation) settle(details, expand); };
  }
  document.addEventListener('click', event => {
    const summary = event.target.closest('summary');
    if (!summary || !summary.parentElement.matches(selector) || event.target.closest('button,a,input,select')) return;
    event.preventDefault();
    toggle(summary.parentElement);
  });
  window.NirDisclosure = {open: details => settle(details, true)};
})();
