const reviewDialog = document.querySelector('[data-review-dialog]');
const reviewOpeners = [...document.querySelectorAll('[data-review-open]')];
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

if (reviewDialog instanceof HTMLDialogElement) {
  const closeButton = reviewDialog.querySelector('[data-review-close]');
  const firstField = reviewDialog.querySelector('input[name="rating"]');
  let returnFocus = null;
  let closingTimer = null;

  reviewDialog.hidden = false;
  reviewDialog.classList.add('is-ready');

  const openDialog = (opener = null) => {
    window.clearTimeout(closingTimer);
    returnFocus = opener instanceof HTMLElement ? opener : document.activeElement;
    reviewDialog.classList.remove('is-closing');
    if (!reviewDialog.open) reviewDialog.showModal();
    document.documentElement.classList.add('has-review-dialog');
    window.requestAnimationFrame(() => firstField?.focus({ preventScroll: true }));
  };

  const finishClose = () => {
    if (reviewDialog.open) reviewDialog.close();
    reviewDialog.classList.remove('is-closing');
    document.documentElement.classList.remove('has-review-dialog');
    if (window.location.hash === '#formular-recenzie') {
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#recenzii-produs`);
    }
    if (returnFocus instanceof HTMLElement) returnFocus.focus({ preventScroll: true });
  };

  const closeDialog = () => {
    if (!reviewDialog.open || reviewDialog.classList.contains('is-closing')) return;
    if (reducedMotion.matches) {
      finishClose();
      return;
    }
    reviewDialog.classList.add('is-closing');
    closingTimer = window.setTimeout(finishClose, 220);
  };

  reviewOpeners.forEach((opener) => opener.addEventListener('click', (event) => {
    event.preventDefault();
    openDialog(opener);
  }));

  closeButton?.addEventListener('click', closeDialog);
  reviewDialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeDialog();
  });
  reviewDialog.addEventListener('click', (event) => {
    if (event.target === reviewDialog) closeDialog();
  });
  reviewDialog.addEventListener('close', () => {
    document.documentElement.classList.remove('has-review-dialog');
  });

  if (window.location.hash === '#formular-recenzie') {
    window.requestAnimationFrame(() => openDialog());
  }
}

const reviewBodies = [...document.querySelectorAll('[data-review-body]')];
const detailDialog = document.querySelector('[data-review-detail-dialog]');
let excerptResizeTimer = null;

const fitReviewExcerpt = (body) => {
  if (!(body instanceof HTMLElement) || body.closest('.is-carousel-clone')) return;
  const excerpt = body.querySelector('[data-review-excerpt]');
  const moreButton = body.querySelector('[data-review-expand]');
  if (!(excerpt instanceof HTMLElement) || !(moreButton instanceof HTMLButtonElement)) return;

  const fullText = (moreButton.dataset.reviewFull || excerpt.textContent || '').trim().replace(/\s+/g, ' ');
  excerpt.textContent = fullText;
  moreButton.hidden = true;
  body.classList.remove('is-truncated');

  const lineHeight = Number.parseFloat(window.getComputedStyle(body).lineHeight);
  const maxHeight = (Number.isFinite(lineHeight) ? lineHeight : 24) * 3 + 1;
  if (body.scrollHeight <= maxHeight || fullText === '') return;

  moreButton.hidden = false;
  body.classList.add('is-truncated');
  const words = fullText.split(' ');
  let minimum = 1;
  let maximum = words.length;
  let fitted = 1;

  while (minimum <= maximum) {
    const middle = Math.floor((minimum + maximum) / 2);
    excerpt.textContent = `${words.slice(0, middle).join(' ')}… `;
    if (body.scrollHeight <= maxHeight) {
      fitted = middle;
      minimum = middle + 1;
    } else {
      maximum = middle - 1;
    }
  }

  excerpt.textContent = `${words.slice(0, fitted).join(' ')}… `;
};

if (reviewBodies.length > 0) {
  document.documentElement.classList.add('has-review-excerpts');
  reviewBodies.forEach(fitReviewExcerpt);
  window.addEventListener('resize', () => {
    window.clearTimeout(excerptResizeTimer);
    excerptResizeTimer = window.setTimeout(() => reviewBodies.forEach(fitReviewExcerpt), 120);
  }, { passive: true });
}

if (detailDialog instanceof HTMLDialogElement) {
  const closeButton = detailDialog.querySelector('[data-review-detail-close]');
  const avatar = detailDialog.querySelector('[data-review-detail-avatar]');
  const author = detailDialog.querySelector('[data-review-detail-author]');
  const stars = detailDialog.querySelector('[data-review-detail-stars]');
  const date = detailDialog.querySelector('[data-review-detail-date]');
  const verifiedBadge = detailDialog.querySelector('[data-review-detail-verified]');
  const demoBadge = detailDialog.querySelector('[data-review-detail-demo]');
  const title = detailDialog.querySelector('[data-review-detail-title]');
  const body = detailDialog.querySelector('[data-review-detail-body]');
  let returnFocus = null;
  let closingTimer = null;

  detailDialog.hidden = false;
  detailDialog.classList.add('is-ready');

  const finishClose = () => {
    if (detailDialog.open) detailDialog.close();
    detailDialog.classList.remove('is-closing');
    document.documentElement.classList.remove('has-review-dialog');
    if (returnFocus instanceof HTMLElement) returnFocus.focus({ preventScroll: true });
  };

  const closeDetail = () => {
    if (!detailDialog.open || detailDialog.classList.contains('is-closing')) return;
    if (reducedMotion.matches) {
      finishClose();
      return;
    }
    detailDialog.classList.add('is-closing');
    closingTimer = window.setTimeout(finishClose, 220);
  };

  const openDetail = (opener) => {
    window.clearTimeout(closingTimer);
    returnFocus = opener;
    const rating = Math.max(1, Math.min(5, Number(opener.dataset.reviewRating || 5)));
    const authorName = opener.dataset.reviewAuthor || 'Client La Măriuca';
    if (avatar) avatar.textContent = authorName.charAt(0).toLocaleUpperCase('ro-RO');
    if (author) author.textContent = authorName;
    if (stars) {
      stars.setAttribute('aria-label', `${rating} din 5 stele`);
      stars.replaceChildren(...Array.from({ length: 5 }, (_, index) => {
        const star = document.createElement('span');
        star.textContent = '★';
        star.setAttribute('aria-hidden', 'true');
        if (index < rating) star.classList.add('is-filled');
        return star;
      }));
    }
    if (date) {
      date.textContent = opener.dataset.reviewDateLabel || '';
      date.setAttribute('datetime', opener.dataset.reviewDate || '');
    }
    if (verifiedBadge) verifiedBadge.hidden = opener.dataset.reviewVerified !== '1';
    if (demoBadge) demoBadge.hidden = opener.dataset.reviewDemo !== '1';
    if (title) title.textContent = opener.dataset.reviewTitle || `Părerea lui ${authorName}`;
    if (body) body.textContent = opener.dataset.reviewFull || '';

    detailDialog.classList.remove('is-closing');
    if (!detailDialog.open) detailDialog.showModal();
    document.documentElement.classList.add('has-review-dialog');
    window.requestAnimationFrame(() => closeButton?.focus({ preventScroll: true }));
  };

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-review-expand]') : null;
    if (!(target instanceof HTMLButtonElement)) return;
    event.preventDefault();
    openDetail(target);
  });

  closeButton?.addEventListener('click', closeDetail);
  detailDialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeDetail();
  });
  detailDialog.addEventListener('click', (event) => {
    if (event.target === detailDialog) closeDetail();
  });
  detailDialog.addEventListener('close', () => {
    document.documentElement.classList.remove('has-review-dialog');
  });
}
