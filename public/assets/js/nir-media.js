(() => {
  const dialog = document.querySelector('[data-nir-lightbox]');
  if (!dialog) return;
  let opener, timer;
  function close() {
    if (!dialog.open || dialog.classList.contains('is-closing')) return;
    dialog.classList.add('is-closing');
    const finish = () => { dialog.close(); dialog.classList.remove('is-closing'); document.documentElement.classList.remove('has-nir-image'); opener?.focus({preventScroll:true}); };
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) finish(); else timer = setTimeout(finish, 210);
  }
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-nir-image-open]');
    if (!trigger || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault(); clearTimeout(timer); opener = trigger;
    const image = dialog.querySelector('[data-nir-lightbox-image]');
    image.src = trigger.href; image.alt = trigger.dataset.imageName || '';
    dialog.querySelector('[data-nir-lightbox-caption]').textContent = image.alt;
    dialog.classList.remove('is-closing'); dialog.showModal();
    document.documentElement.classList.add('has-nir-image');
    dialog.querySelector('[data-nir-lightbox-close]').focus({preventScroll:true});
  });
  dialog.addEventListener('click', event => { if (event.target === dialog || event.target.closest('[data-nir-lightbox-close]')) close(); });
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
})();
