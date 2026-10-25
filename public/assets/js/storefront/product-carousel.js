const carousels = document.querySelectorAll('[data-product-carousel]');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const gestureViewport = window.matchMedia('(max-width: 1024px)');
const interactiveSelector = 'a, button, input, select, textarea, label';

carousels.forEach((carousel) => {
  const viewport = carousel.querySelector('[data-carousel-viewport]');
  const track = carousel.querySelector('[data-carousel-track]');
  const previousButton = carousel.querySelector('[data-carousel-prev]');
  const nextButton = carousel.querySelector('[data-carousel-next]');
  const position = carousel.querySelector('[data-carousel-position]');
  const progress = carousel.querySelector('[data-carousel-progress]');
  const announcement = carousel.querySelector('[data-carousel-announcement]');
  const originals = [...track?.querySelectorAll(':scope > [data-carousel-slide]') || []];
  const total = originals.length;
  const itemLabel = carousel.dataset.itemLabel || 'Produsul';

  if (!viewport || !track || total < 2) {
    previousButton?.setAttribute('disabled', '');
    nextButton?.setAttribute('disabled', '');
    return;
  }

  let cloneCount = 1;
  let currentIndex = 0;
  let scrollTimer = null;
  let autoplayTimer = null;
  let resizeTimer = null;
  let interactionTimer = null;
  let isHovering = false;
  let hasFocus = false;
  let isPointerDown = false;
  let isJumping = false;
  let dragStartX = 0;
  let dragStartY = 0;
  let dragStartScroll = 0;
  let dragged = false;
  let pointerCardUrl = '';
  let interactionResumeAt = 0;
  let ignorePassivePause = false;

  const interactionPauseMs = 3000;

  const padded = (value) => String(value).padStart(2, '0');
  const slideNodes = () => [...track.children];
  const originalNode = (index) => slideNodes()[cloneCount + index];
  const slideLeft = (slide) => slide.offsetLeft - track.offsetLeft;

  const updateUi = (announce = false) => {
    const visibleIndex = currentIndex + 1;
    if (position) position.textContent = `${padded(visibleIndex)} / ${padded(total)}`;
    if (progress) progress.style.setProperty('--carousel-progress', String(visibleIndex / total));
    if (announce && announcement) announcement.textContent = `${itemLabel} ${visibleIndex} din ${total}`;
  };

  const jumpTo = (slide) => {
    isJumping = true;
    viewport.scrollTo({ left: slideLeft(slide), behavior: 'auto' });
    window.requestAnimationFrame(() => { isJumping = false; });
  };

  const scheduleAutoplay = () => {
    window.clearTimeout(autoplayTimer);
    if (reducedMotion.matches || document.hidden || isPointerDown) return;
    if ((isHovering || hasFocus) && !ignorePassivePause) return;
    const autoplayDelay = Math.max(1000, Number(carousel.dataset.autoplayMs || 1500));
    const interactionDelay = Math.max(0, interactionResumeAt - performance.now());
    const delay = interactionDelay > 0 ? interactionDelay : autoplayDelay;
    autoplayTimer = window.setTimeout(() => {
      interactionResumeAt = 0;
      goTo(currentIndex + 1, false);
    }, delay);
  };

  const pauseForInteraction = () => {
    interactionResumeAt = performance.now() + interactionPauseMs;
    ignorePassivePause = true;
    scheduleAutoplay();
  };

  const settle = (announce = false) => {
    if (isJumping) return;
    const nodes = slideNodes();
    const targetLeft = viewport.scrollLeft;
    let nearestIndex = 0;
    let nearestDistance = Number.POSITIVE_INFINITY;
    nodes.forEach((slide, index) => {
      const distance = Math.abs(slideLeft(slide) - targetLeft);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestIndex = index;
      }
    });

    if (nearestIndex < cloneCount) {
      currentIndex = total - cloneCount + nearestIndex;
      jumpTo(originalNode(currentIndex));
    } else if (nearestIndex >= cloneCount + total) {
      currentIndex = nearestIndex - cloneCount - total;
      jumpTo(originalNode(currentIndex));
    } else {
      currentIndex = nearestIndex - cloneCount;
    }

    updateUi(announce);
    scheduleAutoplay();
  };

  const goTo = (requestedIndex, announce = true) => {
    const nodes = slideNodes();
    let targetNode;
    if (requestedIndex < 0) {
      targetNode = nodes[cloneCount - 1];
    } else if (requestedIndex >= total) {
      targetNode = nodes[cloneCount + total];
    } else {
      targetNode = originalNode(requestedIndex);
    }
    viewport.scrollTo({ left: slideLeft(targetNode), behavior: reducedMotion.matches ? 'auto' : 'smooth' });
    if (announce) {
      window.clearTimeout(interactionTimer);
      interactionTimer = window.setTimeout(() => settle(true), reducedMotion.matches ? 0 : 360);
    }
  };

  const markClone = (slide) => {
    slide.classList.add('is-carousel-clone');
    slide.removeAttribute('data-carousel-slide');
    slide.setAttribute('aria-hidden', 'true');
    // Clones can be visible briefly while the infinite loop wraps. Keeping the
    // clone out of the tab order avoids duplicate keyboard stops without making
    // its links and buttons inert for pointer users during that interval.
    slide.querySelectorAll(interactiveSelector).forEach((control) => control.setAttribute('tabindex', '-1'));
    return slide;
  };

  const rebuild = () => {
    const retainedIndex = currentIndex;
    track.querySelectorAll(':scope > .is-carousel-clone').forEach((clone) => clone.remove());
    const first = originals[0];
    const second = originals[1];
    const stride = second ? Math.max(1, second.offsetLeft - first.offsetLeft) : Math.max(1, first.offsetWidth);
    cloneCount = Math.min(total, Math.max(1, Math.ceil(viewport.clientWidth / stride)));

    originals.slice(-cloneCount).map((slide) => markClone(slide.cloneNode(true))).reverse().forEach((clone) => track.prepend(clone));
    originals.slice(0, cloneCount).forEach((slide) => track.append(markClone(slide.cloneNode(true))));
    currentIndex = Math.min(retainedIndex, total - 1);
    jumpTo(originalNode(currentIndex));
    updateUi();
    scheduleAutoplay();
  };

  previousButton?.addEventListener('click', () => {
    pauseForInteraction();
    goTo(currentIndex - 1);
  });
  nextButton?.addEventListener('click', () => {
    pauseForInteraction();
    goTo(currentIndex + 1);
  });

  carousel.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    if (target.closest(interactiveSelector)) {
      pauseForInteraction();
      return;
    }

    const productCard = target.closest('.boutique-product-card[data-product-url]');
    const productUrl = productCard?.dataset.productUrl;
    if (!productCard || !productUrl || !carousel.contains(productCard)) return;

    pauseForInteraction();
    window.location.assign(productUrl);
  });

  viewport.addEventListener('scroll', () => {
    if (isJumping) return;
    window.clearTimeout(scrollTimer);
    scrollTimer = window.setTimeout(() => settle(isPointerDown), 120);
  }, { passive: true });

  viewport.addEventListener('pointerdown', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const isInteractiveTarget = target?.closest(interactiveSelector);

    if (isInteractiveTarget) {
      pauseForInteraction();
      return;
    }
    if (!gestureViewport.matches) return;

    if (event.pointerType === 'mouse' && event.button !== 0) return;

    isPointerDown = true;
    window.clearTimeout(autoplayTimer);
    dragStartX = event.clientX;
    dragStartY = event.clientY;
    dragged = false;
    pointerCardUrl = target?.closest('.boutique-product-card[data-product-url]')?.dataset.productUrl || '';
    if (event.pointerType !== 'mouse') return;
    dragStartScroll = viewport.scrollLeft;
    viewport.setPointerCapture(event.pointerId);
  });

  viewport.addEventListener('pointermove', (event) => {
    if (!isPointerDown) return;
    const distanceX = event.clientX - dragStartX;
    const distanceY = event.clientY - dragStartY;
    if (Math.abs(distanceX) > 10 || Math.abs(distanceY) > 10) {
      dragged = true;
      viewport.classList.add('is-dragging');
    }
    if (!dragged || event.pointerType !== 'mouse' || !viewport.hasPointerCapture(event.pointerId)) return;
    event.preventDefault();
    viewport.scrollLeft = dragStartScroll - distanceX;
  });

  const finishPointer = (event) => {
    if (!isPointerDown) return;
    const productUrl = dragged ? '' : pointerCardUrl;
    isPointerDown = false;
    pointerCardUrl = '';
    if (event.pointerType === 'mouse' && viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
    viewport.classList.remove('is-dragging');
    if (dragged) {
      viewport.addEventListener('click', (clickEvent) => {
        clickEvent.preventDefault();
        clickEvent.stopPropagation();
      }, { once: true, capture: true });
    }
    pauseForInteraction();
    window.clearTimeout(scrollTimer);
    scrollTimer = window.setTimeout(() => settle(true), 80);
    if (productUrl) {
      event.preventDefault();
      window.location.assign(productUrl);
    }
  };

  viewport.addEventListener('pointerup', finishPointer);
  viewport.addEventListener('pointercancel', finishPointer);
  carousel.addEventListener('pointerenter', () => {
    isHovering = true;
    ignorePassivePause = false;
    interactionResumeAt = 0;
    window.clearTimeout(autoplayTimer);
  });
  carousel.addEventListener('pointerleave', () => {
    isHovering = false;
    scheduleAutoplay();
  });
  carousel.addEventListener('focusin', () => {
    hasFocus = true;
    if (!ignorePassivePause) window.clearTimeout(autoplayTimer);
  });
  carousel.addEventListener('focusout', (event) => {
    if (carousel.contains(event.relatedTarget)) return;
    hasFocus = false;
    scheduleAutoplay();
  });

  document.addEventListener('visibilitychange', scheduleAutoplay);
  reducedMotion.addEventListener('change', scheduleAutoplay);
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(rebuild, 160);
  }, { passive: true });

  rebuild();
});
