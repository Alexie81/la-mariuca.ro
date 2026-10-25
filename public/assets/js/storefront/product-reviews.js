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

const reviewForms = [...document.querySelectorAll('[data-review-form]')];

const reviewFieldControls = (field) => [...field.querySelectorAll('input, textarea, select')]
  .filter((control) => control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement);

const reviewFieldIsValid = (field) => {
  const controls = reviewFieldControls(field);
  if (controls.length === 0) return true;
  const radios = controls.filter((control) => control instanceof HTMLInputElement && control.type === 'radio');
  if (radios.length > 0) return radios.some((control) => control.checked);
  return controls.every((control) => control.checkValidity());
};

const clearReviewFieldError = (field) => {
  field.classList.remove('has-review-error');
  field.querySelectorAll('[aria-invalid="true"]').forEach((control) => control.removeAttribute('aria-invalid'));
  const error = field.querySelector('[data-review-error]');
  if (error instanceof HTMLElement) error.hidden = true;
};

const showReviewFieldError = (field, message = '') => {
  field.classList.add('has-review-error');
  reviewFieldControls(field).forEach((control) => control.setAttribute('aria-invalid', 'true'));
  const error = field.querySelector('[data-review-error]');
  if (error instanceof HTMLElement) {
    error.textContent = message || field.dataset.reviewErrorMessage || 'Completează acest câmp.';
    error.hidden = false;
  }
};

reviewForms.forEach((form) => {
  if (!(form instanceof HTMLFormElement)) return;
  form.noValidate = true;
  const fields = [...form.querySelectorAll('[data-review-field]')];
  const summary = form.querySelector('[data-review-validation-summary]');
  const submit = form.querySelector('.product-review-submit');
  const submitLabel = submit?.querySelector('span');
  const defaultSubmitLabel = submitLabel?.textContent || 'Trimite recenzia';

  const setSummary = (message = '') => {
    if (!(summary instanceof HTMLElement)) return;
    const copy = summary.querySelector('p');
    if (copy && message) copy.textContent = message;
    summary.hidden = false;
  };

  fields.forEach((field) => {
    reviewFieldControls(field).forEach((control) => {
      const clearWhenValid = () => {
        if (!reviewFieldIsValid(field)) return;
        clearReviewFieldError(field);
        if (summary instanceof HTMLElement && !form.querySelector('.has-review-error')) summary.hidden = true;
      };
      control.addEventListener('input', clearWhenValid);
      control.addEventListener('change', clearWhenValid);
    });
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    let firstInvalid = null;
    fields.forEach((field) => {
      if (reviewFieldIsValid(field)) {
        clearReviewFieldError(field);
        return;
      }
      showReviewFieldError(field);
      firstInvalid ||= reviewFieldControls(field)[0] || null;
    });

    if (firstInvalid instanceof HTMLElement) {
      setSummary('Completează zonele marcate cu roșu înainte de trimitere.');
      firstInvalid.focus({ preventScroll: true });
      firstInvalid.closest('[data-review-field]')?.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'center' });
      return;
    }

    if (submit instanceof HTMLButtonElement) submit.disabled = true;
    form.classList.add('is-submitting');
    if (submitLabel) submitLabel.textContent = 'Se trimite…';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const payload = await response.json();

      if (response.ok && payload.ok) {
        window.location.assign(payload.redirect || `${window.location.pathname}${window.location.search}#recenzii-produs`);
        return;
      }

      if (payload.field) {
        const target = form.elements.namedItem(payload.field);
        const control = target instanceof RadioNodeList ? target[0] : target;
        const field = control instanceof HTMLElement ? control.closest('[data-review-field]') : null;
        if (field instanceof HTMLElement) {
          showReviewFieldError(field, payload.message || 'Verifică acest câmp.');
          setSummary(payload.message || 'Verifică zona marcată cu roșu.');
          control.focus({ preventScroll: true });
          field.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'center' });
          return;
        }
      }

      setSummary(payload.message || 'Recenzia nu a putut fi trimisă. Verifică datele și încearcă din nou.');
    } catch (error) {
      setSummary('Conexiunea a fost întreruptă. Datele au rămas completate; încearcă din nou.');
    } finally {
      if (submit instanceof HTMLButtonElement) submit.disabled = false;
      form.classList.remove('is-submitting');
      if (submitLabel) submitLabel.textContent = defaultSubmitLabel;
    }
  });
});

const reviewBodies = [...document.querySelectorAll('[data-review-body]')];
const detailDialog = document.querySelector('[data-review-detail-dialog]');
let excerptResizeTimer = null;

const fitReviewExcerpt = (body) => {
  if (!(body instanceof HTMLElement) || body.closest('.is-carousel-clone')) return;
  const excerpt = body.querySelector('[data-review-excerpt]');
  const moreButton = body.querySelector('[data-review-expand]');
  if (!(excerpt instanceof HTMLElement) || !(moreButton instanceof HTMLButtonElement)) return;

  const fullText = (moreButton.dataset.reviewFull || excerpt.textContent || '').trim().replace(/\s+/g, ' ');
  const characterLimit = window.matchMedia('(max-width: 640px)').matches ? 118 : 178;
  if (fullText.length <= characterLimit) {
    excerpt.textContent = fullText;
    moreButton.hidden = true;
    body.classList.remove('is-truncated');
    return;
  }

  const candidate = fullText.slice(0, characterLimit + 1);
  const lastSpace = candidate.lastIndexOf(' ');
  const safeEnd = lastSpace > Math.floor(characterLimit * .7) ? lastSpace : characterLimit;
  excerpt.textContent = `${fullText.slice(0, safeEnd).trimEnd()}… `;
  moreButton.hidden = false;
  body.classList.add('is-truncated');
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
