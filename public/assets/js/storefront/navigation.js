const toggle = document.querySelector('[data-menu-toggle]');
const menu = document.querySelector('[data-menu]');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const phoneSearchViewport = window.matchMedia('(max-width: 560px)');
const menuCloseDuration = 380;
let menuTransitionTimer;

const syncSearchPlaceholders = () => {
  document.querySelectorAll('[data-search-placeholder]').forEach((input) => {
    input.placeholder = phoneSearchViewport.matches
      ? input.dataset.placeholderPhone
      : input.dataset.placeholderDesktop;
  });
};

syncSearchPlaceholders();
phoneSearchViewport.addEventListener?.('change', syncSearchPlaceholders);

document.querySelectorAll('.menu-group').forEach((group, index) => {
  group.classList.add(`menu-sequence-${Math.min(index, 12)}`);
});

document.querySelectorAll('.menu-group > a').forEach((link) => {
  const linkUrl = new URL(link.href, window.location.origin);
  const isHomeLink = link.classList.contains('mobile-home-link') && linkUrl.pathname === '/';
  const isAllProductsLink = link.classList.contains('all-products-link')
    && linkUrl.pathname === window.location.pathname;
  if (linkUrl.pathname === window.location.pathname && (linkUrl.pathname.includes('/categorie/') || isHomeLink || isAllProductsLink)) {
    link.classList.add('is-current');
    link.setAttribute('aria-current', 'page');
  }
});

const shopSortLabelViewport = window.matchMedia('(max-width: 760px)');

const syncShopSortLabels = () => {
  document.querySelectorAll('[data-shop-sort-select] option').forEach((option) => {
    option.dataset.desktopLabel ||= option.textContent.trim();
    option.textContent = shopSortLabelViewport.matches
      ? option.dataset.mobileLabel
      : option.dataset.desktopLabel;
  });
};

syncShopSortLabels();
shopSortLabelViewport.addEventListener?.('change', syncShopSortLabels);

const shopCategoryCarouselViewport = window.matchMedia('(max-width: 1100px)');

document.querySelectorAll('[data-shop-category-carousel]').forEach((carousel) => {
  const viewport = carousel.querySelector('[data-shop-category-viewport]');
  const track = carousel.querySelector('[data-shop-category-track]');
  const originalItems = [...carousel.querySelectorAll('[data-shop-category-item]')];
  if (!viewport || !track || originalItems.length < 2) return;

  const clones = originalItems.map((item) => {
    const clone = item.cloneNode(true);
    clone.removeAttribute('data-shop-category-item');
    clone.setAttribute('aria-hidden', 'true');
    clone.setAttribute('tabindex', '-1');
    clone.querySelectorAll('img').forEach((image) => image.setAttribute('loading', 'lazy'));
    track.append(clone);
    return clone;
  });
  const allItems = [...originalItems, ...clones];
  const autoplayDelay = 1700;
  let autoplayTimer;
  let loopResetTimer;
  let wheelTimer;
  let cycleWidth = 0;
  let dragStartX = null;
  let dragged = false;
  let focusPaused = false;
  let hoverPaused = false;

  const measureCycle = () => {
    cycleWidth = clones[0].offsetLeft - originalItems[0].offsetLeft;
  };

  const normalizeLoopPosition = () => {
    if (cycleWidth > 0 && viewport.scrollLeft >= cycleWidth) {
      viewport.scrollLeft -= cycleWidth;
    }
  };

  const canAutoplay = () => (
    shopCategoryCarouselViewport.matches
    && !reducedMotion.matches
    && !focusPaused
    && !hoverPaused
    && document.visibilityState === 'visible'
  );

  const clearAutoplay = () => {
    window.clearTimeout(autoplayTimer);
    autoplayTimer = undefined;
  };

  const scheduleAutoplay = () => {
    clearAutoplay();
    if (!canAutoplay()) return;
    autoplayTimer = window.setTimeout(advanceCarousel, autoplayDelay);
  };

  const advanceCarousel = () => {
    if (!canAutoplay()) return;
    measureCycle();
    normalizeLoopPosition();

    const currentLeft = viewport.scrollLeft;
    const nextItem = allItems.find((item) => (item.offsetLeft - track.offsetLeft) > currentLeft + 4);
    const targetLeft = nextItem ? nextItem.offsetLeft - track.offsetLeft : 0;
    viewport.scrollTo({
      left: targetLeft,
      behavior: reducedMotion.matches ? 'auto' : 'smooth',
    });

    window.clearTimeout(loopResetTimer);
    if (cycleWidth > 0 && targetLeft >= cycleWidth) {
      loopResetTimer = window.setTimeout(normalizeLoopPosition, 620);
    }
    scheduleAutoplay();
  };

  const resetAfterInteraction = () => {
    normalizeLoopPosition();
    scheduleAutoplay();
  };

  viewport.addEventListener('pointerdown', (event) => {
    clearAutoplay();
    dragStartX = event.clientX;
    dragged = false;
  });

  viewport.addEventListener('pointermove', (event) => {
    if (dragStartX === null) return;
    if (Math.abs(event.clientX - dragStartX) > 8) dragged = true;
  });

  viewport.addEventListener('pointerup', () => {
    dragStartX = null;
    resetAfterInteraction();
    window.setTimeout(() => { dragged = false; }, 0);
  });

  viewport.addEventListener('pointercancel', () => {
    dragStartX = null;
    dragged = false;
    resetAfterInteraction();
  });

  viewport.addEventListener('click', (event) => {
    if (!dragged) return;
    event.preventDefault();
    event.stopPropagation();
    dragged = false;
  }, true);

  viewport.addEventListener('wheel', () => {
    clearAutoplay();
    window.clearTimeout(wheelTimer);
    wheelTimer = window.setTimeout(resetAfterInteraction, 180);
  }, { passive: true });

  viewport.addEventListener('mouseenter', () => {
    hoverPaused = true;
    clearAutoplay();
  });

  viewport.addEventListener('mouseleave', () => {
    hoverPaused = false;
    resetAfterInteraction();
  });

  viewport.addEventListener('focusin', () => {
    focusPaused = true;
    clearAutoplay();
  });

  viewport.addEventListener('focusout', () => {
    window.setTimeout(() => {
      focusPaused = viewport.contains(document.activeElement);
      resetAfterInteraction();
    }, 0);
  });

  const syncCarousel = () => {
    measureCycle();
    if (!shopCategoryCarouselViewport.matches) viewport.scrollLeft = 0;
    scheduleAutoplay();
  };

  window.addEventListener('resize', syncCarousel);
  document.addEventListener('visibilitychange', scheduleAutoplay);
  shopCategoryCarouselViewport.addEventListener?.('change', syncCarousel);
  reducedMotion.addEventListener?.('change', scheduleAutoplay);
  document.fonts?.ready.then(syncCarousel);
  syncCarousel();
});

document.querySelectorAll('[data-shop-sort]').forEach((form) => {
  form.querySelectorAll('select').forEach((select) => {
    select.addEventListener('change', () => form.requestSubmit());
  });
});

document.querySelectorAll('[data-shop-budget-range]').forEach((range) => {
  const form = range.closest('[data-shop-filter-form]');
  const output = form?.querySelector('[data-shop-budget-value]');
  const progress = form?.querySelector('[data-shop-budget-progress]');
  const filter = range.closest('.shop-budget-filter');
  const formatter = new Intl.NumberFormat('ro-RO');
  let interactionTimer;
  const animateBudget = () => {
    if (!filter || reducedMotion.matches) return;
    filter.classList.remove('is-interacting');
    window.requestAnimationFrame(() => filter.classList.add('is-interacting'));
    window.clearTimeout(interactionTimer);
    interactionTimer = window.setTimeout(() => filter.classList.remove('is-interacting'), 520);
  };
  const syncBudget = () => {
    if (output) output.textContent = formatter.format(Number(range.value));
    if (progress) progress.value = Number(range.value) - Number(range.min);
    range.setAttribute('aria-valuetext', `${formatter.format(Number(range.value))} lei`);
  };

  syncBudget();
  range.addEventListener('input', () => {
    syncBudget();
    animateBudget();
  });

  let precisionDrag = null;
  const setPreciseValue = (value) => {
    const minimum = Number(range.min);
    const maximum = Number(range.max);
    const nextValue = Math.max(minimum, Math.min(maximum, Math.round(value)));
    if (Number(range.value) === nextValue) return;
    range.value = String(nextValue);
    range.dispatchEvent(new Event('input', { bubbles: true }));
  };

  range.addEventListener('pointerdown', (event) => {
    if (event.button !== 0) return;
    const rect = range.getBoundingClientRect();
    const minimum = Number(range.min);
    const maximum = Number(range.max);
    const current = Number(range.value);
    const thumbPosition = rect.left + ((current - minimum) / (maximum - minimum)) * rect.width;
    let startValue = current;

    if (Math.abs(event.clientX - thumbPosition) > 14) {
      startValue = minimum + ((event.clientX - rect.left) / rect.width) * (maximum - minimum);
      setPreciseValue(startValue);
      startValue = Number(range.value);
    }

    precisionDrag = { pointerId: event.pointerId, startX: event.clientX, startValue };
    range.setPointerCapture?.(event.pointerId);
    event.preventDefault();
  }, { passive: false });

  range.addEventListener('pointermove', (event) => {
    if (!precisionDrag || precisionDrag.pointerId !== event.pointerId) return;
    setPreciseValue(precisionDrag.startValue + (event.clientX - precisionDrag.startX));
    event.preventDefault();
  }, { passive: false });

  const finishPrecisionDrag = (event) => {
    if (!precisionDrag || precisionDrag.pointerId !== event.pointerId) return;
    precisionDrag = null;
    range.releasePointerCapture?.(event.pointerId);
    range.dispatchEvent(new Event('change', { bubbles: true }));
  };

  range.addEventListener('pointerup', finishPrecisionDrag);
  range.addEventListener('pointercancel', finishPrecisionDrag);
});

document.querySelectorAll('[data-shop-filter-form]').forEach((form) => {
  const button = form.querySelector('.shop-filter-actions button[type="submit"]');
  const count = form.querySelector('[data-shop-filter-result-count]');
  const label = form.querySelector('[data-shop-filter-result-label]');
  const range = form.querySelector('[data-shop-budget-range]');
  if (!button || !count || !label) return;

  let debounceTimer;
  let requestController;
  let requestSequence = 0;

  const updateResultCount = async () => {
    const sequence = ++requestSequence;
    requestController?.abort();
    requestController = new AbortController();

    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    url.search = '';
    const params = new URLSearchParams(new FormData(form));
    params.set('preview', '1');
    url.search = params.toString();
    button.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: requestController.signal,
      });
      if (!response.ok) throw new Error('Nu s-a putut actualiza numărul produselor.');
      const payload = await response.json();
      if (sequence !== requestSequence || !Number.isInteger(payload.count)) return;
      count.textContent = String(payload.count);
      label.textContent = payload.count === 1 ? 'produs' : 'produse';
    } catch (error) {
      if (error.name !== 'AbortError') console.warn(error.message);
    } finally {
      if (sequence === requestSequence) button.removeAttribute('aria-busy');
    }
  };

  const scheduleResultCount = (delay = 0) => {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(updateResultCount, delay);
  };

  form.addEventListener('change', (event) => {
    if (event.target === range) return;
    scheduleResultCount();
  });

  range?.addEventListener('input', () => scheduleResultCount(120));
});

document.querySelectorAll('[data-shop-category-tree]').forEach((tree) => {
  tree.classList.add('is-enhanced');

  const allProducts = tree.querySelector('.shop-filter-option-all');
  const categoryInputs = [...tree.querySelectorAll('input[name="categorie[]"]')];

  const syncAllProductsState = () => {
    if (!allProducts) return;

    const hasSelectedCategory = categoryInputs.some((input) => input.checked);
    allProducts.classList.toggle('is-current', !hasSelectedCategory);

    if (hasSelectedCategory) {
      allProducts.removeAttribute('aria-current');
    } else {
      allProducts.setAttribute('aria-current', 'true');
    }
  };

  categoryInputs.forEach((input) => input.addEventListener('change', syncAllProductsState));
  syncAllProductsState();

  tree.querySelectorAll('[data-shop-category-toggle]').forEach((toggle) => {
    const branch = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!branch) return;

    const setExpanded = (expanded) => {
      toggle.setAttribute('aria-expanded', String(expanded));
      toggle.setAttribute('aria-label', expanded ? toggle.dataset.labelClose : toggle.dataset.labelOpen);
      branch.hidden = !expanded;
    };

    setExpanded(toggle.getAttribute('aria-expanded') === 'true');
    toggle.addEventListener('click', () => {
      setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
    });
  });
});

const shopFilterViewport = window.matchMedia('(min-width: 1101px)');
document.querySelectorAll('[data-shop-filters]').forEach((panel) => {
  const summary = panel.querySelector(':scope > summary');
  const drawer = panel.querySelector('[data-shop-filter-form]');
  const closeButton = panel.querySelector('[data-shop-filter-close]');
  const backdrop = panel.parentElement?.querySelector('[data-shop-filter-backdrop]');
  let closeTimer;

  if (!summary || !drawer || !closeButton || !backdrop) return;

  const setDrawerSemantics = (enabled) => {
    if (enabled) {
      drawer.setAttribute('role', 'dialog');
      drawer.setAttribute('aria-modal', 'true');
      drawer.setAttribute('aria-labelledby', 'shop-filter-drawer-title');
      summary.setAttribute('aria-expanded', String(panel.classList.contains('is-drawer-open')));
      return;
    }

    drawer.removeAttribute('role');
    drawer.removeAttribute('aria-modal');
    drawer.removeAttribute('aria-labelledby');
    summary.removeAttribute('aria-expanded');
  };

  const finishDrawerClose = (restoreFocus = false) => {
    window.clearTimeout(closeTimer);
    panel.classList.remove('is-drawer-open', 'is-drawer-closing');
    backdrop.classList.remove('is-visible');
    backdrop.hidden = true;
    document.documentElement.classList.remove('shop-filter-drawer-active');

    if (!shopFilterViewport.matches) {
      panel.open = false;
      setDrawerSemantics(true);
    } else {
      setDrawerSemantics(false);
    }

    if (restoreFocus && !shopFilterViewport.matches) {
      summary.focus({ preventScroll: true });
    }
  };

  const closeDrawer = ({ immediate = false, restoreFocus = true } = {}) => {
    if (shopFilterViewport.matches) return;

    panel.classList.remove('is-drawer-open');
    panel.classList.add('is-drawer-closing');
    backdrop.classList.remove('is-visible');
    summary.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('shop-filter-drawer-active');

    if (immediate || reducedMotion.matches) {
      finishDrawerClose(restoreFocus);
      return;
    }

    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(() => finishDrawerClose(restoreFocus), 440);
  };

  const openDrawer = () => {
    if (shopFilterViewport.matches || panel.classList.contains('is-drawer-open')) return;

    window.clearTimeout(closeTimer);
    panel.classList.remove('is-drawer-closing');
    panel.open = true;
    backdrop.hidden = false;
    setDrawerSemantics(true);
    document.documentElement.classList.add('shop-filter-drawer-active');

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        panel.classList.add('is-drawer-open');
        backdrop.classList.add('is-visible');
        summary.setAttribute('aria-expanded', 'true');
        closeButton.focus({ preventScroll: true });
      });
    });
  };

  const syncFilterDisclosure = () => {
    window.clearTimeout(closeTimer);
    panel.classList.remove('is-drawer-open', 'is-drawer-closing');
    backdrop.classList.remove('is-visible');
    backdrop.hidden = true;
    document.documentElement.classList.remove('shop-filter-drawer-active');

    if (shopFilterViewport.matches) {
      document.documentElement.classList.remove('has-shop-filter-drawer');
      panel.open = true;
      setDrawerSemantics(false);
      return;
    }

    document.documentElement.classList.add('has-shop-filter-drawer');
    panel.open = false;
    setDrawerSemantics(true);
  };

  summary.addEventListener('click', (event) => {
    if (shopFilterViewport.matches) return;
    event.preventDefault();
    openDrawer();
  });

  closeButton.addEventListener('click', () => closeDrawer());
  backdrop.addEventListener('click', () => closeDrawer());

  drawer.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeDrawer();
      return;
    }

    if (event.key !== 'Tab' || !panel.classList.contains('is-drawer-open')) return;

    const focusable = [...drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter((element) => element.getClientRects().length > 0);
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  syncFilterDisclosure();
  shopFilterViewport.addEventListener?.('change', syncFilterDisclosure);
});

const submenuToggles = [...document.querySelectorAll('[data-submenu-toggle]')];

const setSubmenuState = (control, expanded) => {
  const panel = document.getElementById(control.getAttribute('aria-controls'));
  if (!panel) return;
  control.setAttribute('aria-expanded', String(expanded));
  panel.classList.toggle('is-expanded', expanded);

  if (!expanded) {
    panel.querySelectorAll('[data-submenu-toggle][aria-expanded="true"]').forEach((nestedControl) => {
      setSubmenuState(nestedControl, false);
    });
  }
};

const resetSubmenus = () => {
  submenuToggles.forEach((control) => setSubmenuState(control, false));
};

const desktopMenuList = document.querySelector('[data-desktop-menu-list]');
const desktopOverflow = document.querySelector('[data-desktop-menu-overflow]');
const desktopOverflowToggle = document.querySelector('[data-desktop-menu-overflow-toggle]');
const desktopOverflowPanel = document.querySelector('[data-desktop-menu-overflow-panel]');
const desktopOverflowItems = document.querySelector('[data-desktop-menu-overflow-items]');
const desktopMenuViewport = window.matchMedia('(min-width: 1101px)');
const desktopMenuItems = desktopMenuList
  ? [...desktopMenuList.querySelectorAll(':scope > [data-desktop-menu-item]')]
  : [];
const desktopCategoryItems = desktopMenuItems.filter(
  (item) => !item.classList.contains('menu-highlight') && !item.classList.contains('menu-fixed'),
);
const mobileMenuStory = desktopMenuList?.querySelector(':scope > .mobile-menu-story') ?? null;
const maximumVisibleDesktopCategories = 6;
let desktopOverflowFrame;
let desktopLayoutActive = false;
let desktopMenuObservedWidth;

const closeDesktopOverflow = () => {
  if (!desktopOverflowToggle || !desktopOverflowPanel) return;
  desktopOverflowToggle.setAttribute('aria-expanded', 'false');
  desktopOverflowPanel.hidden = true;
};

const restoreDesktopMenuItems = () => {
  if (!desktopMenuList) return;
  desktopMenuItems.forEach((item) => {
    desktopMenuList.insertBefore(item, mobileMenuStory);
  });
};

const desktopMenuHasOverflow = () => {
  if (!desktopMenuList) return false;
  const visibleItems = [...desktopMenuList.querySelectorAll(':scope > [data-desktop-menu-item]')];
  const lastItem = visibleItems.at(-1);
  if (!lastItem) return false;
  const listRect = desktopMenuList.getBoundingClientRect();
  const listStyle = window.getComputedStyle(desktopMenuList);
  const contentRight = listRect.right
    - Number.parseFloat(listStyle.paddingRight || '0')
    - Number.parseFloat(listStyle.borderRightWidth || '0');

  return desktopMenuList.scrollWidth > desktopMenuList.clientWidth + 1
    || lastItem.getBoundingClientRect().right > contentRight + .5;
};

const syncDesktopMenuOverflow = () => {
  if (!desktopMenuList || !desktopOverflow || !desktopOverflowItems || !desktopOverflowToggle) return;

  if (!desktopMenuViewport.matches) {
    closeDesktopOverflow();
    if (desktopLayoutActive || desktopOverflowItems.childElementCount > 0) restoreDesktopMenuItems();
    desktopOverflow.hidden = true;
    desktopMenuList.classList.remove('has-desktop-overflow');
    desktopLayoutActive = false;
    return;
  }

  desktopLayoutActive = true;
  closeDesktopOverflow();
  restoreDesktopMenuItems();
  desktopOverflow.hidden = true;
  desktopMenuList.classList.remove('has-desktop-overflow');

  const candidates = [...desktopCategoryItems];
  const needsCategoryLimit = candidates.length > maximumVisibleDesktopCategories;
  const needsWidthOverflow = desktopMenuHasOverflow();
  if (!needsCategoryLimit && !needsWidthOverflow) return;

  desktopOverflow.hidden = false;
  desktopMenuList.classList.add('has-desktop-overflow');

  while (candidates.length > maximumVisibleDesktopCategories) {
    const item = candidates.pop();
    desktopOverflowItems.prepend(item);
  }

  while (desktopMenuHasOverflow() && candidates.length > 1) {
    const item = candidates.pop();
    desktopOverflowItems.prepend(item);
  }

  const hiddenCount = desktopOverflowItems.children.length;
  if (hiddenCount === 0) {
    desktopOverflow.hidden = true;
    desktopMenuList.classList.remove('has-desktop-overflow');
    return;
  }

  desktopOverflowToggle.setAttribute(
    'aria-label',
    `Arată ${hiddenCount} ${hiddenCount === 1 ? 'categorie care nu încape' : 'categorii care nu încap'} în bară`,
  );
};

const scheduleDesktopMenuOverflow = () => {
  window.cancelAnimationFrame(desktopOverflowFrame);
  desktopOverflowFrame = window.requestAnimationFrame(() => {
    desktopOverflowFrame = window.requestAnimationFrame(syncDesktopMenuOverflow);
  });
};

if (desktopOverflow && desktopOverflowToggle && desktopOverflowPanel) {
  desktopOverflowToggle.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const willOpen = desktopOverflowPanel.hidden;
    closeDesktopOverflow();
    if (willOpen) {
      desktopOverflowPanel.hidden = false;
      desktopOverflowToggle.setAttribute('aria-expanded', 'true');
    }
  });

  document.addEventListener('pointerdown', (event) => {
    if (!desktopOverflow.contains(event.target)) closeDesktopOverflow();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || desktopOverflowPanel.hidden) return;
    closeDesktopOverflow();
    desktopOverflowToggle.focus();
  });

  window.addEventListener('resize', scheduleDesktopMenuOverflow, { passive: true });
  new ResizeObserver(([entry]) => {
    const observedWidth = entry?.contentRect.width;
    if (!Number.isFinite(observedWidth)) return;
    if (desktopMenuObservedWidth !== undefined && Math.abs(observedWidth - desktopMenuObservedWidth) < .5) return;
    desktopMenuObservedWidth = observedWidth;
    scheduleDesktopMenuOverflow();
  }).observe(desktopMenuList);
  desktopMenuViewport.addEventListener?.('change', scheduleDesktopMenuOverflow);
  document.fonts?.ready.then(scheduleDesktopMenuOverflow);
  window.addEventListener('load', scheduleDesktopMenuOverflow, { once: true });
  scheduleDesktopMenuOverflow();
}

submenuToggles.forEach((control) => {
  control.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const willOpen = control.getAttribute('aria-expanded') !== 'true';

    if (willOpen) {
      const owner = control.parentElement;
      const siblings = owner?.parentElement?.children ?? [];
      [...siblings].forEach((sibling) => {
        const siblingControl = sibling.querySelector?.(':scope > [data-submenu-toggle]');
        if (siblingControl && siblingControl !== control) setSubmenuState(siblingControl, false);
      });
    }

    setSubmenuState(control, willOpen);
    if (willOpen) {
      window.requestAnimationFrame(() => control.scrollIntoView({ block: 'nearest', behavior: reducedMotion.matches ? 'auto' : 'smooth' }));
    }
  });
});

const closeMenu = () => {
  if (!toggle || !menu) return;
  window.clearTimeout(menuTransitionTimer);
  toggle.setAttribute('aria-expanded', 'false');
  toggle.querySelector('.sr-only').textContent = 'Deschide meniul';
  syncSearchPlaceholders();

  const finishClose = () => {
    menu.classList.remove('is-open', 'is-closing');
    document.body.classList.remove('menu-open', 'menu-closing');
  };

  if (!menu.classList.contains('is-open') || reducedMotion.matches) {
    finishClose();
    return;
  }

  menu.classList.add('is-closing');
  document.body.classList.add('menu-closing');
  menuTransitionTimer = window.setTimeout(finishClose, menuCloseDuration);
};

const openMenu = () => {
  if (!toggle || !menu) return;
  window.clearTimeout(menuTransitionTimer);
  menu.classList.remove('is-closing');
  document.body.classList.remove('menu-closing');
  toggle.setAttribute('aria-expanded', 'true');
  toggle.querySelector('.sr-only').textContent = 'Închide meniul';
  menu.classList.add('is-open');
  document.body.classList.add('menu-open');
  menu.scrollTop = 0;
  resetSubmenus();
  syncSearchPlaceholders();
  closeSuggestions();
  closeUtilityPanels();
};

if (toggle && menu) {
  toggle.addEventListener('click', () => {
    const willOpen = toggle.getAttribute('aria-expanded') !== 'true';
    if (willOpen) openMenu();
    else closeMenu();
  });
}

const closeUtilityPanels = (except = null) => {
  document.querySelectorAll('[data-utility-panel]').forEach((panel) => {
    if (panel === except) return;
    panel.hidden = true;
    const control = document.querySelector(`[aria-controls="${panel.id}"]`);
    control?.setAttribute('aria-expanded', 'false');
  });
};

document.querySelectorAll('[data-utility-panel-toggle]').forEach((panelToggle) => {
  panelToggle.addEventListener('click', () => {
    const panel = document.getElementById(panelToggle.getAttribute('aria-controls'));
    if (!panel) return;
    const willOpen = panel.hidden;
    closeUtilityPanels(panel);
    panel.hidden = !willOpen;
    panelToggle.setAttribute('aria-expanded', String(willOpen));
    panelToggle.classList.remove('is-pulsing');
    void panelToggle.offsetWidth;
    panelToggle.classList.add('is-pulsing');
    window.setTimeout(() => panelToggle.classList.remove('is-pulsing'), 620);
  });
});

const releaseHearts = (source) => {
  ['left', 'center', 'right'].forEach((direction) => {
    const heart = document.createElement('span');
    heart.className = `floating-heart floating-heart--${direction}`;
    heart.textContent = '♥';
    source.append(heart);
    window.setTimeout(() => heart.remove(), 1100);
  });
};

document.querySelectorAll('[data-animated-action]').forEach((action) => {
  action.addEventListener('pointerdown', () => {
    action.classList.remove('is-pulsing');
    void action.offsetWidth;
    action.classList.add('is-pulsing');
    if (action.matches('.favorite-link, .mobile-favorite')) releaseHearts(action);
    window.setTimeout(() => action.classList.remove('is-pulsing'), 620);
  });
});

const search = document.querySelector('#site-search');
const searchClear = document.querySelector('[data-search-clear]');
const searchQuickLinks = document.querySelector('.search-quick-links');
const searchQuickPrev = document.querySelector('[data-search-quick-prev]');
const searchQuickNext = document.querySelector('[data-search-quick-next]');
const suggestions = document.querySelector('[data-search-suggestions]');
let searchTimer;
let searchRequest;

const syncSearchClear = () => {
  if (!search || !searchClear) return;
  searchClear.hidden = search.value.length === 0;
};

const syncQuickLinkScrollControls = () => {
  if (!searchQuickLinks || !searchQuickPrev || !searchQuickNext) return;
  const maxScroll = Math.max(0, searchQuickLinks.scrollWidth - searchQuickLinks.clientWidth);
  const hasOverflow = maxScroll > 2;
  searchQuickLinks.classList.toggle('is-scrollable', hasOverflow);
  searchQuickPrev.hidden = !hasOverflow || searchQuickLinks.scrollLeft <= 2;
  searchQuickNext.hidden = !hasOverflow || searchQuickLinks.scrollLeft >= maxScroll - 2;
};

const scrollQuickLinks = (direction) => {
  if (!searchQuickLinks) return;
  searchQuickLinks.scrollBy({
    left: direction * Math.max(220, searchQuickLinks.clientWidth * .72),
    behavior: reducedMotion.matches ? 'auto' : 'smooth',
  });
};

const activateQuickLinkScroll = (direction) => {
  scrollQuickLinks(direction);
  window.requestAnimationFrame(() => search?.focus({ preventScroll: true }));
};

if (searchQuickLinks && searchQuickPrev && searchQuickNext) {
  searchQuickPrev.addEventListener('click', () => activateQuickLinkScroll(-1));
  searchQuickNext.addEventListener('click', () => activateQuickLinkScroll(1));
  searchQuickLinks.addEventListener('scroll', syncQuickLinkScrollControls, { passive: true });
  searchQuickLinks.addEventListener('wheel', (event) => {
    const maxScroll = searchQuickLinks.scrollWidth - searchQuickLinks.clientWidth;
    if (maxScroll <= 2 || Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
    event.preventDefault();
    searchQuickLinks.scrollLeft += event.deltaY;
  }, { passive: false });
  search?.addEventListener('focus', () => window.requestAnimationFrame(syncQuickLinkScrollControls));
  window.addEventListener('resize', syncQuickLinkScrollControls);
  window.requestAnimationFrame(syncQuickLinkScrollControls);
}

const closeSuggestions = () => {
  if (!search || !suggestions) return;
  suggestions.hidden = true;
  search.setAttribute('aria-expanded', 'false');
  document.body.classList.remove('search-open');
};

const searchSectionTitle = (label) => {
  const title = document.createElement('p');
  title.className = 'search-section-title';
  title.textContent = label;
  return title;
};

const searchSuggestionArrow = () => {
  const arrow = document.createElement('span');
  arrow.className = 'search-suggestion-arrow';
  arrow.setAttribute('aria-hidden', 'true');
  arrow.innerHTML = '<svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"></path></svg>';
  return arrow;
};

const searchMedia = (url, className) => {
  if (!url) return null;
  const image = document.createElement('img');
  image.className = className;
  image.src = url;
  image.alt = '';
  image.loading = 'lazy';
  image.decoding = 'async';
  return image;
};

const renderSuggestions = (payload) => {
  if (!search || !suggestions) return;
  suggestions.replaceChildren();
  const items = Array.isArray(payload.items) ? payload.items : [];
  const categories = Array.isArray(payload.categories) ? payload.categories : [];
  const query = search.value.trim();

  const close = document.createElement('button');
  close.className = 'search-panel-close';
  close.type = 'button';
  close.setAttribute('aria-label', 'Închide sugestiile');
  close.textContent = '×';
  close.addEventListener('click', () => {
    closeSuggestions();
    search.blur();
  });

  const intro = document.createElement('div');
  intro.className = 'search-panel-intro';
  const introEyebrow = document.createElement('span');
  introEyebrow.textContent = 'Rezultate rapide';
  const introTitle = document.createElement('strong');
  introTitle.textContent = `Pentru „${query}”`;
  intro.append(introEyebrow, introTitle);
  suggestions.append(intro, close);

  if (searchQuickLinks) {
    const mobileShortcuts = document.createElement('section');
    mobileShortcuts.className = 'search-mobile-shortcuts';
    mobileShortcuts.setAttribute('aria-label', 'Categorii populare');

    const shortcutTitle = document.createElement('p');
    shortcutTitle.className = 'search-mobile-shortcuts-title';
    shortcutTitle.textContent = 'Caută rapid';

    const shortcutGrid = document.createElement('div');
    shortcutGrid.className = 'search-mobile-shortcuts-grid';
    searchQuickLinks.querySelectorAll('a').forEach((link) => shortcutGrid.append(link.cloneNode(true)));
    mobileShortcuts.append(shortcutTitle, shortcutGrid);
    suggestions.append(mobileShortcuts);
  }

  if (categories.length > 0) {
    const categoryList = document.createElement('div');
    categoryList.className = 'search-category-list';
    categoryList.append(searchSectionTitle('Categorii'));
    categories.forEach((category) => {
      const link = document.createElement('a');
      link.className = 'search-category-suggestion';
      link.href = category.url;

      const categoryImage = searchMedia(category.image_url, 'search-category-image');
      if (categoryImage) {
        const icon = document.createElement('span');
        icon.className = 'search-category-icon has-image';
        icon.setAttribute('aria-hidden', 'true');
        icon.append(categoryImage);
        link.classList.add('has-image');
        link.append(icon);
      }

      const name = document.createElement('strong');
      name.textContent = category.name;

      const arrow = searchSuggestionArrow();
      link.append(name, arrow);
      categoryList.append(link);
    });
    suggestions.append(categoryList);
  }

  if (items.length > 0) {
    const productList = document.createElement('div');
    productList.className = 'search-product-list';
    productList.append(searchSectionTitle('Produse sugerate'));
    const priceFormatter = new Intl.NumberFormat('ro-RO', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

    items.forEach((item) => {
      const link = document.createElement('a');
      link.className = 'search-product-suggestion';
      link.href = item.url;

      const productImage = searchMedia(item.image_url, 'search-product-image');
      if (productImage) {
        const thumb = document.createElement('span');
        thumb.className = 'search-suggestion-thumb has-image';
        thumb.setAttribute('aria-hidden', 'true');
        thumb.append(productImage);
        link.classList.add('has-image');
        link.append(thumb);
      }

      const copy = document.createElement('span');
      copy.className = 'search-suggestion-copy';
      const title = document.createElement('strong');
      title.textContent = item.name;
      const price = document.createElement('small');
      price.className = 'search-suggestion-price';
      price.textContent = `${priceFormatter.format((item.price_bani || 0) / 100)} Lei`;
      copy.append(title, price);

      const arrow = searchSuggestionArrow();
      link.append(copy, arrow);
      productList.append(link);
    });
    suggestions.append(productList);
  }

  if (items.length === 0 && categories.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'search-empty';
    empty.textContent = 'Nu am găsit categorii sau produse pentru această căutare.';
    suggestions.append(empty);
  }

  const allResults = document.createElement('a');
  const searchUrl = new URL(search.form.action, window.location.origin);
  searchUrl.searchParams.set('q', query);
  allResults.className = 'search-all-results';
  allResults.href = searchUrl.toString();
  allResults.innerHTML = '<span aria-hidden="true">⌕</span>';
  const allResultsLabel = document.createElement('strong');
  allResultsLabel.textContent = `Vezi toate rezultatele pentru „${query}”`;
  const allResultsArrow = searchSuggestionArrow();
  allResults.append(allResultsLabel, allResultsArrow);
  suggestions.append(allResults);

  suggestions.hidden = false;
  search.setAttribute('aria-expanded', 'true');
  document.body.classList.add('search-open');
};

const loadSuggestions = () => {
  if (!search || !suggestions) return;
  const query = search.value.trim();
  window.clearTimeout(searchTimer);
  searchRequest?.abort();

  if (query.length < 2) {
    closeSuggestions();
    return;
  }

  searchTimer = window.setTimeout(async () => {
    searchRequest = new AbortController();
    try {
      const url = new URL(suggestions.dataset.endpoint, window.location.origin);
      url.searchParams.set('q', query);
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: searchRequest.signal,
      });
      if (!response.ok) throw new Error('Search request failed');
      const payload = await response.json();
      renderSuggestions(payload);
    } catch (error) {
      if (error.name !== 'AbortError') closeSuggestions();
    }
  }, 160);
};

search?.addEventListener('input', () => {
  syncSearchClear();
  loadSuggestions();
});
search?.addEventListener('focus', loadSuggestions);
searchClear?.addEventListener('click', () => {
  if (!search) return;
  search.value = '';
  syncSearchClear();
  closeSuggestions();
  search.focus();
});
syncSearchClear();

document.addEventListener('click', (event) => {
  if (suggestions && search && !event.target.closest('.search-form')) closeSuggestions();
  if (!event.target.closest('[data-utility-panel-toggle]') && !event.target.closest('[data-utility-panel]')) closeUtilityPanels();
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;

  const activeControl = document.activeElement?.closest?.('[data-submenu-toggle][aria-expanded="true"]');
  const activePanel = document.activeElement?.closest?.('.submenu.is-expanded');
  const panelControl = activePanel ? document.querySelector(`[aria-controls="${activePanel.id}"]`) : null;
  const submenuControl = activeControl || panelControl;
  if (submenuControl) {
    event.preventDefault();
    setSubmenuState(submenuControl, false);
    submenuControl.focus();
    return;
  }

  closeSuggestions();
  closeUtilityPanels();
  if (document.body.classList.contains('menu-open')) {
    closeMenu();
    toggle?.focus();
  }
});
