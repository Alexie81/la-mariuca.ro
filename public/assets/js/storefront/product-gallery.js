document.querySelectorAll('[data-product-gallery]').forEach((gallery) => {
  const mainImage = gallery.querySelector('[data-product-gallery-main]');
  const thumbnails = Array.from(gallery.querySelectorAll('[data-product-gallery-thumbnail]'));
  if (!(mainImage instanceof HTMLImageElement) || thumbnails.length < 2) {
    return;
  }

  thumbnails.forEach((thumbnail) => {
    thumbnail.addEventListener('click', () => {
      const source = thumbnail.dataset.gallerySrc;
      if (!source || source === mainImage.getAttribute('src')) {
        return;
      }

      mainImage.classList.add('is-changing');
      mainImage.src = source;
      mainImage.alt = thumbnail.dataset.galleryAlt || '';
      thumbnails.forEach((item) => {
        const active = item === thumbnail;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      window.setTimeout(() => mainImage.classList.remove('is-changing'), 180);
    });
  });
});
