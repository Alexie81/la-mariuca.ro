const announcement = document.querySelector('[data-announcement]');
const viewport = announcement?.querySelector('.site-announcement__inner');
const track = announcement?.querySelector('[data-announcement-track]');
const group = track?.querySelector('[data-announcement-group]');
const content = group?.querySelector('[data-announcement-content]');

if (announcement && viewport && track && group && content) {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const copy = group.cloneNode(true);
  copy.setAttribute('aria-hidden', 'true');
  copy.removeAttribute('data-announcement-group');
  copy.classList.add('site-announcement__group--copy');
  track.append(copy);

  const measure = () => {
    const width = viewport.clientWidth;
    if (!width) return;
    const distance = Math.max(width, content.scrollWidth + 56);
    const pixelsPerSecond = width <= 560 ? 32 : 48;
    announcement.style.setProperty('--announcement-lap', `${distance}px`);
    announcement.style.setProperty('--announcement-duration', `${distance / pixelsPerSecond}s`);
  };

  const updateMotion = () => {
    announcement.classList.toggle('is-moving', !reducedMotion.matches);
    if (!reducedMotion.matches) measure();
  };

  updateMotion();
  reducedMotion.addEventListener('change', updateMotion);
  const resizeObserver = new ResizeObserver(measure);
  resizeObserver.observe(viewport);
  resizeObserver.observe(content);
  document.fonts.ready.then(measure);
}
