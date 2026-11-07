(() => {
  'use strict';
  const root = document.querySelector('[data-order-tracking]');
  if (!root) return;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  const motionButton = root.querySelector('[data-tracking-motion]');
  let pausedByUser = false;
  try { pausedByUser = localStorage.getItem('lamariuca-tracking-motion') === 'paused'; } catch (_) { /* Optional preference. */ }
  const motionEnabled = () => !reducedMotion.matches && !pausedByUser;
  const updateMotion = () => {
    const paused = !motionEnabled();
    root.classList.toggle('tracking--still', paused);
    if (motionButton) {
      motionButton.hidden = false;
      motionButton.setAttribute('aria-pressed', String(paused));
      motionButton.setAttribute('aria-label', reducedMotion.matches ? 'Animații oprite conform preferinței dispozitivului' : (paused ? 'Pornește animațiile' : 'Oprește animațiile'));
      motionButton.title = reducedMotion.matches ? 'Mișcare redusă conform preferinței dispozitivului' : (paused ? 'Pornește animațiile' : 'Oprește animațiile');
      motionButton.disabled = reducedMotion.matches;
      motionButton.querySelector('span').textContent = reducedMotion.matches ? 'Mișcare redusă' : (paused ? 'Animații oprite' : 'Animații active');
    }
  };
  motionButton?.addEventListener('click', () => {
    pausedByUser = !pausedByUser;
    try { localStorage.setItem('lamariuca-tracking-motion', pausedByUser ? 'paused' : 'active'); } catch (_) { /* Optional preference. */ }
    updateMotion();
  });
  reducedMotion.addEventListener('change', updateMotion);
  updateMotion();

  // Reveal only content that starts below the viewport. Nothing depends on JS to render.
  if ('IntersectionObserver' in window && motionEnabled()) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(({ target, isIntersecting }) => {
        if (!isIntersecting) return;
        target.classList.remove('tr-reveal-pending');
        target.classList.add('tr-reveal-visible');
        observer.unobserve(target);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -25px 0px' });
    root.querySelectorAll('[data-tracking-reveal], .tracking-card, .tracking-help').forEach((element) => {
      if (element.getBoundingClientRect().top >= window.innerHeight - 25) {
        element.classList.add('tr-reveal-pending');
      }
      observer.observe(element);
    });
  }

  // A small pointer-driven depth effect, without a permanent animation loop.
  root.querySelectorAll('[data-tracking-tilt]').forEach((scene) => {
    let frame = 0;
    const reset = () => {
      cancelAnimationFrame(frame);
      frame = 0;
      ['--tilt-x', '--tilt-y', '--pointer-x', '--pointer-y'].forEach((property) => scene.style.removeProperty(property));
    };
    scene.addEventListener('pointermove', (event) => {
      if (!motionEnabled() || !finePointer.matches || event.pointerType === 'touch') return;
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const bounds = scene.getBoundingClientRect();
        const x = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));
        const y = Math.min(1, Math.max(0, (event.clientY - bounds.top) / bounds.height));
        scene.style.setProperty('--tilt-x', `${(0.5 - y) * 5}deg`);
        scene.style.setProperty('--tilt-y', `${(x - 0.5) * 5}deg`);
        scene.style.setProperty('--pointer-x', `${x * 100}%`);
        scene.style.setProperty('--pointer-y', `${y * 100}%`);
        frame = 0;
      });
    });
    scene.addEventListener('pointerleave', reset);
    reducedMotion.addEventListener('change', reset);
    motionButton?.addEventListener('click', reset);
  });
  document.addEventListener('visibilitychange', () => root.classList.toggle('tracking--paused', document.hidden));
  root.querySelector('.tracking-hero__jump')?.addEventListener('click', (event) => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const journey = root.querySelector('#parcurs-comanda');
    if (!journey) return;
    event.preventDefault();
    journey.setAttribute('tabindex', '-1');
    journey.focus({ preventScroll: true });
    journey.scrollIntoView({ behavior: motionEnabled() ? 'smooth' : 'instant', block: 'start' });
  });

  const form = document.querySelector('[data-tracking-lookup]');
  form?.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    button.querySelector('span').textContent = 'Căutăm comanda…';
  });
  window.addEventListener('pageshow', () => {
    const button = form?.querySelector('button[type="submit"]');
    if (!button) return;
    button.classList.remove('is-loading');
    button.removeAttribute('aria-busy');
    button.querySelector('span').textContent = 'Urmărește comanda';
  });
})();
