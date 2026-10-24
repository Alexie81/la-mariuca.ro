const bindSwipe = (element, onPrevious, onNext) => {
  if (!(element instanceof HTMLElement)) return;

  let startX = null;
  let startY = null;

  element.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse') return;
    startX = event.clientX;
    startY = event.clientY;
  });

  element.addEventListener('pointerup', (event) => {
    if (startX === null || startY === null) return;
    const deltaX = event.clientX - startX;
    const deltaY = event.clientY - startY;
    startX = null;
    startY = null;
    if (Math.abs(deltaX) < 42 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
    if (deltaX > 0) onPrevious();
    else onNext();
  });

  element.addEventListener('pointercancel', () => {
    startX = null;
    startY = null;
  });
};

document.querySelectorAll('[data-product-gallery]').forEach((gallery) => {
  const mainImage = gallery.querySelector('[data-product-gallery-main]');
  const thumbnails = Array.from(gallery.querySelectorAll('[data-product-gallery-thumbnail]'));
  const dots = Array.from(gallery.querySelectorAll('[data-gallery-dot]'));
  const stage = gallery.querySelector('[data-gallery-stage]');
  const lightbox = gallery.querySelector('[data-gallery-lightbox]');
  const lightboxImage = gallery.querySelector('[data-gallery-lightbox-image]');
  const lightboxStage = gallery.querySelector('[data-gallery-lightbox-stage]');
  const lightboxCount = gallery.querySelector('[data-gallery-current]');
  const zoomLevel = gallery.querySelector('[data-gallery-zoom-level]');
  const thumbnailRail = gallery.querySelector('[data-gallery-thumbnails]');
  const thumbnailRailShell = thumbnailRail?.closest('.product-gallery-rail');
  const railPrevious = gallery.querySelector('[data-gallery-rail-previous]');
  const railNext = gallery.querySelector('[data-gallery-rail-next]');

  if (!(mainImage instanceof HTMLImageElement)) return;

  const images = thumbnails.length > 0
    ? thumbnails.map((thumbnail) => ({
        source: thumbnail.dataset.gallerySrc || '',
        alt: thumbnail.dataset.galleryAlt || '',
      }))
    : [{ source: mainImage.currentSrc || mainImage.src, alt: mainImage.alt }];

  let currentIndex = 0;
  let zoom = 1;
  let changeTimer = null;
  let closeTimer = null;

  const updateRailControls = () => {
    if (!(thumbnailRail instanceof HTMLElement) || !(thumbnailRailShell instanceof HTMLElement)) return;
    const maximumScroll = Math.max(0, thumbnailRail.scrollHeight - thumbnailRail.clientHeight);
    const hasOverflow = maximumScroll > 2;
    const canScrollPrevious = hasOverflow && thumbnailRail.scrollTop > 2;
    const canScrollNext = hasOverflow && thumbnailRail.scrollTop < maximumScroll - 2;

    thumbnailRailShell.classList.toggle('has-scroll-previous', canScrollPrevious);
    thumbnailRailShell.classList.toggle('has-scroll-next', canScrollNext);
    if (railPrevious instanceof HTMLButtonElement) railPrevious.disabled = !canScrollPrevious;
    if (railNext instanceof HTMLButtonElement) railNext.disabled = !canScrollNext;
  };

  const normalizedIndex = (index) => (index + images.length) % images.length;

  const updateZoom = (nextZoom) => {
    zoom = Math.max(1, Math.min(2, nextZoom));
    if (lightboxStage instanceof HTMLElement) {
      lightboxStage.style.setProperty('--gallery-zoom', String(zoom));
    }
    if (zoomLevel instanceof HTMLOutputElement) {
      zoomLevel.value = `${Math.round(zoom * 100)}%`;
    } else if (zoomLevel instanceof HTMLElement) {
      zoomLevel.textContent = `${Math.round(zoom * 100)}%`;
    }
  };

  const showImage = (requestedIndex, animate = true) => {
    const nextIndex = normalizedIndex(requestedIndex);
    const image = images[nextIndex];
    if (!image?.source) return;

    currentIndex = nextIndex;
    if (animate && mainImage.getAttribute('src') !== image.source) {
      mainImage.classList.add('is-changing');
      window.clearTimeout(changeTimer);
      changeTimer = window.setTimeout(() => mainImage.classList.remove('is-changing'), 230);
    }
    mainImage.src = image.source;
    mainImage.alt = image.alt;

    thumbnails.forEach((thumbnail, index) => {
      const active = index === currentIndex;
      thumbnail.classList.toggle('is-active', active);
      thumbnail.setAttribute('aria-pressed', active ? 'true' : 'false');
      if (active && thumbnailRail instanceof HTMLElement) {
        const top = thumbnail.offsetTop - thumbnailRail.offsetTop - (thumbnailRail.clientHeight - thumbnail.clientHeight) / 2;
        thumbnailRail.scrollTo({ top, behavior: animate ? 'smooth' : 'auto' });
      }
    });

    dots.forEach((dot, index) => {
      const active = index === currentIndex;
      dot.classList.toggle('is-active', active);
      dot.setAttribute('aria-current', active ? 'true' : 'false');
    });

    if (lightboxImage instanceof HTMLImageElement) {
      lightboxImage.src = image.source;
      lightboxImage.alt = image.alt;
    }
    if (lightboxCount instanceof HTMLElement) lightboxCount.textContent = String(currentIndex + 1);
    updateZoom(1);
  };

  const showPrevious = () => showImage(currentIndex - 1);
  const showNext = () => showImage(currentIndex + 1);

  thumbnails.forEach((thumbnail, index) => thumbnail.addEventListener('click', () => showImage(index)));
  dots.forEach((dot, index) => dot.addEventListener('click', () => showImage(index)));
  gallery.querySelector('[data-gallery-previous]')?.addEventListener('click', showPrevious);
  gallery.querySelector('[data-gallery-next]')?.addEventListener('click', showNext);
  gallery.querySelector('[data-gallery-lightbox-previous]')?.addEventListener('click', showPrevious);
  gallery.querySelector('[data-gallery-lightbox-next]')?.addEventListener('click', showNext);

  railPrevious?.addEventListener('click', () => {
    thumbnailRail?.scrollBy({ top: -150, behavior: 'smooth' });
  });
  railNext?.addEventListener('click', () => {
    thumbnailRail?.scrollBy({ top: 150, behavior: 'smooth' });
  });

  thumbnailRail?.addEventListener('scroll', updateRailControls, { passive: true });
  if (thumbnailRail instanceof HTMLElement && 'ResizeObserver' in window) {
    new ResizeObserver(updateRailControls).observe(thumbnailRail);
  } else {
    window.addEventListener('resize', updateRailControls, { passive: true });
  }

  bindSwipe(stage, showPrevious, showNext);
  bindSwipe(lightboxStage, showPrevious, showNext);

  const openLightbox = () => {
    if (!(lightbox instanceof HTMLDialogElement)) return;
    window.clearTimeout(closeTimer);
    lightbox.classList.remove('is-closing');
    showImage(currentIndex, false);
    if (!lightbox.open) lightbox.showModal();
    document.documentElement.classList.add('has-product-lightbox');
  };

  const closeLightbox = () => {
    if (!(lightbox instanceof HTMLDialogElement) || !lightbox.open || lightbox.classList.contains('is-closing')) return;
    lightbox.classList.add('is-closing');
    closeTimer = window.setTimeout(() => {
      lightbox.close();
      lightbox.classList.remove('is-closing');
      document.documentElement.classList.remove('has-product-lightbox');
      updateZoom(1);
    }, 190);
  };

  gallery.querySelector('[data-gallery-open]')?.addEventListener('click', openLightbox);
  gallery.querySelector('[data-gallery-close]')?.addEventListener('click', closeLightbox);
  gallery.querySelector('[data-gallery-zoom-out]')?.addEventListener('click', () => updateZoom(zoom - 0.25));
  gallery.querySelector('[data-gallery-zoom-in]')?.addEventListener('click', () => updateZoom(zoom + 0.25));

  lightbox?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeLightbox();
  });
  lightbox?.addEventListener('click', (event) => {
    if (event.target === lightbox) closeLightbox();
  });
  lightbox?.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') showPrevious();
    if (event.key === 'ArrowRight') showNext();
  });

  showImage(0, false);
  updateRailControls();
});

document.querySelectorAll('[data-product-quantity]').forEach((control) => {
  const input = control.querySelector('input[type="number"]');
  if (!(input instanceof HTMLInputElement)) return;

  const adjust = (amount) => {
    const minimum = Number(input.min || 1);
    const maximum = Number(input.max || 20);
    const current = Number(input.value || minimum);
    input.value = String(Math.max(minimum, Math.min(maximum, current + amount)));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  control.querySelector('[data-quantity-decrease]')?.addEventListener('click', () => adjust(-1));
  control.querySelector('[data-quantity-increase]')?.addEventListener('click', () => adjust(1));
});
