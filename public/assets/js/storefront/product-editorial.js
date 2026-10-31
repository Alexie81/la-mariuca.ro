const editorial = document.querySelector('[data-product-editorial]');

if (editorial) {
  const navShell = editorial.querySelector('[data-editorial-nav-shell]');
  const links = [...editorial.querySelectorAll('[data-editorial-link]')];
  const sections = [...editorial.querySelectorAll('[data-editorial-section]')];
  const siteHeader = document.querySelector('.site-header');
  const description = editorial.querySelector('[data-product-description-body]');
  const descriptionToggle = editorial.querySelector('[data-product-description-toggle]');
  const descriptionToggleLabel = editorial.querySelector('[data-description-toggle-label]');
  const reviewScore = editorial.querySelector('[data-review-score]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let scrollFrame = 0;
  let descriptionFrame = 0;
  let activeLinkLocked = false;

  const setHeaderOffset = () => {
    const headerHeight = siteHeader ? Math.ceil(siteHeader.getBoundingClientRect().height) : 0;
    editorial.style.setProperty('--product-tabs-offset', `${headerHeight}px`);
  };

  const setActiveLink = (id) => {
    links.forEach((link) => {
      const active = link.dataset.editorialLink === id;
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'location');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  };

  const updateActiveLink = () => {
    scrollFrame = 0;
    if (activeLinkLocked) return;

    const navBottom = navShell ? navShell.getBoundingClientRect().bottom : 0;
    const activationLine = Math.max(navBottom + 48, window.innerHeight * .42);
    let activeSection = sections[0];

    sections.forEach((section) => {
      if (section.getBoundingClientRect().top <= activationLine) {
        activeSection = section;
      }
    });

    if (activeSection?.id) {
      setActiveLink(activeSection.id);
    }
  };

  const requestActiveLinkUpdate = () => {
    if (scrollFrame) return;
    scrollFrame = window.requestAnimationFrame(updateActiveLink);
  };

  const lockActiveLink = (id) => {
    activeLinkLocked = true;
    setActiveLink(id);
  };

  const releaseActiveLink = () => {
    if (!activeLinkLocked) return;
    activeLinkLocked = false;
    requestActiveLinkUpdate();
  };

  const collapsedDescriptionHeight = () => (window.matchMedia('(max-width: 700px)').matches ? 280 : 350);

  const updateDescriptionToggle = (expanded) => {
    descriptionToggle?.classList.toggle('is-expanded', expanded);
    descriptionToggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (descriptionToggleLabel) {
      descriptionToggleLabel.textContent = expanded ? 'Vezi mai puțin' : 'Vezi mai mult';
    }
  };

  const prepareDescription = () => {
    if (!description || !descriptionToggle) return;
    const collapsedHeight = collapsedDescriptionHeight();
    const expanded = description.classList.contains('is-expanded');
    description.style.setProperty('--product-description-collapsed-height', `${collapsedHeight}px`);

    if (description.scrollHeight <= collapsedHeight + 32) {
      description.classList.remove('is-collapsible', 'is-expanded', 'is-transitioning');
      description.style.removeProperty('max-height');
      descriptionToggle.hidden = true;
      updateDescriptionToggle(false);
      return;
    }

    description.classList.add('is-collapsible');
    description.classList.remove('is-transitioning');
    description.style.maxHeight = expanded ? 'none' : `${collapsedHeight}px`;
    descriptionToggle.hidden = false;
    updateDescriptionToggle(expanded);
  };

  const animateDescription = (expanded) => {
    if (!description || !descriptionToggle) return;

    if (descriptionFrame) {
      window.cancelAnimationFrame(descriptionFrame);
      descriptionFrame = 0;
    }

    const currentHeight = description.getBoundingClientRect().height;
    const targetHeight = expanded ? description.scrollHeight : collapsedDescriptionHeight();
    description.classList.remove('is-transitioning');
    description.style.maxHeight = `${currentHeight}px`;
    void description.offsetHeight;

    description.classList.toggle('is-expanded', expanded);
    updateDescriptionToggle(expanded);

    if (reducedMotion.matches) {
      description.style.maxHeight = expanded ? 'none' : `${targetHeight}px`;
      requestActiveLinkUpdate();
      return;
    }

    description.classList.add('is-transitioning');
    descriptionFrame = window.requestAnimationFrame(() => {
      descriptionFrame = 0;
      description.style.maxHeight = `${targetHeight}px`;
    });
  };

  const prepareReviewScore = () => {
    if (!reviewScore) return;

    const target = Number.parseFloat(reviewScore.dataset.reviewScoreValue || '');
    if (!Number.isFinite(target)) return;

    const formatScore = (value) => value.toLocaleString('ro-RO', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    });
    const showFinalScore = () => {
      reviewScore.textContent = formatScore(target);
    };

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
      showFinalScore();
      return;
    }

    reviewScore.textContent = formatScore(0);
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;

      observer.disconnect();
      reviewScore.classList.add('is-counting');
      const duration = 1050;
      const startedAt = performance.now();
      const update = (now) => {
        const progress = Math.min((now - startedAt) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        reviewScore.textContent = formatScore(target * eased);
        if (progress < 1) {
          window.requestAnimationFrame(update);
          return;
        }

        showFinalScore();
        reviewScore.classList.remove('is-counting');
        reviewScore.classList.add('is-counted');
      };

      window.requestAnimationFrame(update);
    }, { threshold: .55 });

    observer.observe(reviewScore);
  };

  links.forEach((link) => {
    link.addEventListener('click', () => lockActiveLink(link.dataset.editorialLink));
  });

  window.addEventListener('hashchange', () => {
    const hashId = window.location.hash.slice(1);
    if (sections.some((section) => section.id === hashId)) {
      lockActiveLink(hashId);
    }
  });

  descriptionToggle?.addEventListener('click', () => {
    animateDescription(!description.classList.contains('is-expanded'));
  });

  description?.addEventListener('transitionend', (event) => {
    if (event.propertyName !== 'max-height') return;

    description.classList.remove('is-transitioning');
    description.style.maxHeight = description.classList.contains('is-expanded')
      ? 'none'
      : `${collapsedDescriptionHeight()}px`;
    requestActiveLinkUpdate();
  });

  setHeaderOffset();
  prepareDescription();
  description?.querySelectorAll('img').forEach((image) => {
    if (!image.complete) {
      image.addEventListener('load', prepareDescription, { once: true });
    }
  });
  prepareReviewScore();
  const initialHash = window.location.hash.slice(1);
  if (sections.some((section) => section.id === initialHash)) {
    lockActiveLink(initialHash);
  } else {
    window.requestAnimationFrame(() => window.requestAnimationFrame(updateActiveLink));
  }

  window.addEventListener('scroll', requestActiveLinkUpdate, { passive: true });
  window.addEventListener('wheel', releaseActiveLink, { passive: true });
  window.addEventListener('touchstart', releaseActiveLink, { passive: true });
  window.addEventListener('keydown', (event) => {
    if (['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End', ' '].includes(event.key)) {
      releaseActiveLink();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (!event.target.closest('[data-editorial-link]')) {
      releaseActiveLink();
    }
  }, { passive: true });
  window.addEventListener('resize', () => {
    setHeaderOffset();
    prepareDescription();
    requestActiveLinkUpdate();
  }, { passive: true });

  if ('ResizeObserver' in window && siteHeader) {
    new ResizeObserver(setHeaderOffset).observe(siteHeader);
  }
}
