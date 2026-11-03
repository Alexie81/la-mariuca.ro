const announcement = document.querySelector('[data-announcement]');
const track = announcement?.querySelector('[data-announcement-track]');
const group = track?.querySelector('[data-announcement-group]');
const content = group?.querySelector('[data-announcement-content]');

if (announcement && track && group && content) {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const copies = [];

  const measure = () => {
    const width = announcement.clientWidth;
    if (!width || reducedMotion.matches) return;
    const distance = content.scrollWidth + (width <= 560 ? 24 : 40);
    const pixelsPerSecond = width <= 560 ? 32 : 48;
    announcement.style.setProperty('--announcement-lap', `${distance}px`);
    announcement.style.setProperty('--announcement-duration', `${distance / pixelsPerSecond}s`);

    // Fill the entire ribbon, plus one tile beyond each edge during the loop.
    const requiredCopies = Math.ceil(width / distance) + 1;
    while (copies.length < requiredCopies) {
      const copy = group.cloneNode(true);
      copy.setAttribute('aria-hidden', 'true');
      copy.removeAttribute('data-announcement-group');
      copy.classList.add('site-announcement__group--copy');
      track.append(copy);
      copies.push(copy);
    }
    while (copies.length > requiredCopies) copies.pop().remove();
  };

  const updateMotion = () => {
    announcement.classList.toggle('is-moving', !reducedMotion.matches);
    if (!reducedMotion.matches) measure();
  };

  updateMotion();
  reducedMotion.addEventListener('change', updateMotion);
  const resizeObserver = new ResizeObserver(measure);
  resizeObserver.observe(announcement);
  resizeObserver.observe(content);
  document.fonts.ready.then(measure);
}
