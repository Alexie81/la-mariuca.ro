(() => {
  'use strict';
  const histories = document.querySelectorAll('[data-order-history]');
  if (!histories.length || !('IntersectionObserver' in window)) return;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (motion.matches) return;
  histories.forEach((history) => {
    history.classList.add('has-history-motion');
    const observer = new IntersectionObserver((entries) => {
      let sequence = 0;
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const delay = Math.min(sequence++, 4) * 85;
        entry.target.style.animationDelay = `${delay}ms`;
        entry.target.querySelectorAll('.order-history__entry, .order-history__marker').forEach((part) => { part.style.animationDelay = `${delay}ms`; });
        entry.target.classList.add('is-history-visible');
        observer.unobserve(entry.target);
      });
    }, {threshold: 0.08, rootMargin: '0px 0px -12px 0px'});
    history.querySelectorAll('[data-history-header], [data-history-event]').forEach((event) => observer.observe(event));
    motion.addEventListener('change', (event) => {
      if (!event.matches) return;
      observer.disconnect();
      history.classList.remove('has-history-motion');
    }, {once: true});
  });
})();
