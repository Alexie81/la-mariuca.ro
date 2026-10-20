const hero = document.querySelector('.hero');
const heroImage = hero?.querySelector('.hero-media img');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const coarsePointer = window.matchMedia('(pointer: coarse)');

if (hero && heroImage && !reducedMotion.matches) {
  let pointerX = 0;
  let pointerY = 0;
  let frameId = 0;

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  const render = () => {
    frameId = 0;
    const rect = hero.getBoundingClientRect();
    const isPhone = window.matchMedia('(max-width: 800px)').matches;
    const scrollLimit = isPhone ? 22 : 50;
    const scrollFactor = isPhone ? .14 : .2;
    const scrollOffset = clamp(window.scrollY * scrollFactor, 0, scrollLimit);

    hero.style.setProperty('--hero-parallax-scroll-y', `${scrollOffset.toFixed(2)}px`);
    hero.style.setProperty('--hero-parallax-x', `${pointerX.toFixed(2)}px`);
    hero.style.setProperty('--hero-parallax-pointer-y', `${pointerY.toFixed(2)}px`);
    hero.classList.toggle('is-parallax-visible', rect.bottom > 0 && rect.top < window.innerHeight);
  };

  const requestRender = () => {
    if (!frameId) frameId = window.requestAnimationFrame(render);
  };

  const resetPointer = () => {
    pointerX = 0;
    pointerY = 0;
    requestRender();
  };

  hero.classList.add('is-parallax-ready');
  requestRender();

  window.addEventListener('scroll', requestRender, { passive: true });
  window.addEventListener('resize', requestRender, { passive: true });

  if (!coarsePointer.matches) {
    hero.addEventListener('pointermove', (event) => {
      const rect = hero.getBoundingClientRect();
      const horizontal = (event.clientX - rect.left) / rect.width - .5;
      const vertical = (event.clientY - rect.top) / rect.height - .5;
      pointerX = clamp(horizontal * 36, -18, 18);
      pointerY = clamp(vertical * 16, -8, 8);
      requestRender();
    });
    hero.addEventListener('pointerleave', resetPointer);
  }
}
