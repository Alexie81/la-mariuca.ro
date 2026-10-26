const footerMenus = Array.from(document.querySelectorAll('.footer-menu'));
const mobileFooter = window.matchMedia('(max-width: 1024px)');
const reducedFooterMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function resetFooterMenuMotion(menu) {
  const content = menu.querySelector('.footer-menu-links');

  content?.getAnimations().forEach((animation) => animation.cancel());

  if (content) {
    content.style.removeProperty('height');
    content.style.removeProperty('opacity');
    content.style.removeProperty('overflow');
    content.style.removeProperty('transform');
  }

  menu.classList.remove('is-animating');
}

function syncFooterMenus() {
  footerMenus.forEach((menu) => {
    resetFooterMenuMotion(menu);

    if (mobileFooter.matches) {
      menu.removeAttribute('open');
    } else {
      menu.setAttribute('open', '');
    }
  });
}

syncFooterMenus();
mobileFooter.addEventListener('change', syncFooterMenus);

footerMenus.forEach((menu) => {
  const summary = menu.querySelector('summary');
  const content = menu.querySelector('.footer-menu-links');

  menu.classList.add('has-smooth-motion');

  summary?.addEventListener('click', (event) => {
    if (!mobileFooter.matches) {
      return;
    }

    event.preventDefault();

    if (!content || menu.classList.contains('is-animating')) {
      return;
    }

    if (reducedFooterMotion.matches || typeof content.animate !== 'function') {
      menu.open = !menu.open;
      return;
    }

    menu.classList.add('is-animating');
    content.style.overflow = 'hidden';

    if (menu.open) {
      const startHeight = content.getBoundingClientRect().height;
      const animation = content.animate([
        { height: `${startHeight}px`, opacity: 1, transform: 'translateY(0)' },
        { height: '0px', opacity: 0, transform: 'translateY(-7px)' },
      ], {
        duration: 280,
        easing: 'cubic-bezier(.4, 0, .2, 1)',
      });

      animation.onfinish = () => {
        menu.open = false;
        resetFooterMenuMotion(menu);
      };
      animation.oncancel = () => resetFooterMenuMotion(menu);
      return;
    }

    menu.open = true;
    const targetHeight = content.scrollHeight;
    const animation = content.animate([
      { height: '0px', opacity: 0, transform: 'translateY(-7px)' },
      { height: `${targetHeight}px`, opacity: 1, transform: 'translateY(0)' },
    ], {
      duration: 340,
      easing: 'cubic-bezier(.22, 1, .36, 1)',
    });

    animation.onfinish = () => resetFooterMenuMotion(menu);
    animation.oncancel = () => resetFooterMenuMotion(menu);
  });

  menu.addEventListener('toggle', () => {
    if (!mobileFooter.matches && !menu.open) {
      menu.setAttribute('open', '');
    }
  });
});

const categoryLists = Array.from(document.querySelectorAll('[data-footer-category-list]'));
const categoryBatchSize = 4;

categoryLists.forEach((list) => {
  const categories = Array.from(list.querySelectorAll('[data-footer-category]'));
  const moreButton = list.querySelector('[data-footer-category-more]');
  const moreCount = moreButton?.querySelector('[data-footer-category-more-count]');

  if (!moreButton || categories.length <= categoryBatchSize) {
    return;
  }

  let visibleCount = categoryBatchSize;

  categories.forEach((category, index) => {
    category.hidden = index >= visibleCount;
  });

  function syncMoreButton() {
    const remaining = categories.length - visibleCount;

    if (remaining <= 0) {
      moreButton.hidden = true;
      moreButton.setAttribute('aria-expanded', 'true');
      return;
    }

    const nextBatch = Math.min(categoryBatchSize, remaining);
    moreButton.hidden = false;
    moreButton.setAttribute('aria-expanded', 'false');
    moreButton.setAttribute('aria-label', `Afișează încă ${nextBatch} categorii`);

    if (moreCount) {
      moreCount.textContent = `+${nextBatch}`;
    }
  }

  moreButton.addEventListener('click', () => {
    const previousVisibleCount = visibleCount;
    visibleCount = Math.min(visibleCount + categoryBatchSize, categories.length);

    categories.slice(previousVisibleCount, visibleCount).forEach((category) => {
      category.hidden = false;
      category.classList.remove('is-revealed');
      requestAnimationFrame(() => category.classList.add('is-revealed'));
    });

    syncMoreButton();
  });

  syncMoreButton();
});
