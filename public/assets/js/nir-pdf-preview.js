(() => {
  'use strict';
  const dialog = document.querySelector('[data-nir-pdf]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const find = name => dialog.querySelector(`[data-pdf-${name}]`);
  const body = find('body'), paper = find('paper'), status = find('status');
  const previous = find('previous'), next = find('next'), zoomIn = find('zoom-in'), zoomOut = find('zoom-out'), fit = find('fit');
  let library, pdf, loadingTask, renderTask, request, opener, url;
  let generation = 0, renderId = 0, pageNumber = 1, zoom = 1, busy = true, resizeTimer, closeTimer;

  function controls() {
    previous.disabled = busy || !pdf || pageNumber <= 1;
    next.disabled = busy || !pdf || pageNumber >= pdf.numPages;
    zoomIn.disabled = busy || !pdf || zoom >= 3;
    zoomOut.disabled = busy || !pdf || zoom <= 1;
    fit.disabled = busy || !pdf;
    fit.textContent = zoom === 1 ? 'La lățime' : `${Math.round(zoom * 100)}%`;
    find('page').textContent = pdf ? `${pageNumber} / ${pdf.numPages}` : '— / —';
  }

  function failure(message) {
    paper.hidden = true;
    status.hidden = false;
    status.classList.add('has-error');
    find('message').textContent = message;
    find('retry').hidden = false;
    busy = false;
    controls();
  }

  async function render(resetScroll = false) {
    if (!pdf || !dialog.open) return;
    const current = ++renderId, document = pdf;
    renderTask?.cancel();
    busy = true;
    controls();
    try {
      const page = await document.getPage(pageNumber);
      if (current !== renderId || !dialog.open) return;
      const base = page.getViewport({scale: 1});
      const available = Math.max(200, body.clientWidth - (body.clientWidth < 600 ? 24 : 48));
      const viewport = page.getViewport({scale: Math.min(available, 1120) / base.width * zoom});
      const density = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(12000000 / (viewport.width * viewport.height)));
      const canvas = window.document.createElement('canvas');
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', `Pagina ${pageNumber} din ${document.numPages} · ${opener.dataset.documentNumber}`);
      canvas.width = Math.floor(viewport.width * density);
      canvas.height = Math.floor(viewport.height * density);
      canvas.style.width = `${Math.floor(viewport.width)}px`;
      canvas.style.height = `${Math.floor(viewport.height)}px`;
      renderTask = page.render({canvasContext: canvas.getContext('2d'), viewport, transform: [density, 0, 0, density, 0, 0]});
      await renderTask.promise;
      if (current !== renderId || !dialog.open) return;
      paper.replaceChildren(canvas);
      paper.hidden = false;
      status.hidden = true;
      const chromeHeight = ['.nir-pdf__header', '.nir-pdf__toolbar', '.nir-pdf__footer']
        .reduce((height, selector) => height + dialog.querySelector(selector).offsetHeight, 0);
      dialog.style.setProperty('--nir-pdf-height', `${Math.ceil(viewport.height) + chromeHeight + (body.clientWidth < 600 ? 24 : 48) + 2}px`);
      if (resetScroll) body.scrollTo({top: 0, left: 0, behavior: 'instant'});
    } catch (error) {
      if (current === renderId && dialog.open && error.name !== 'RenderingCancelledException') {
        failure('Previzualizarea nu s-a încărcat. Poți reîncerca sau deschide PDF-ul separat.');
      }
    } finally {
      if (current === renderId) { busy = false; controls(); }
    }
  }

  function dispose(clearPaper = true) {
    ++generation;
    ++renderId;
    request?.abort();
    renderTask?.cancel();
    loadingTask?.destroy().catch(() => {});
    loadingTask = null;
    pdf = null;
    if (clearPaper) paper.replaceChildren();
    clearTimeout(resizeTimer);
  }

  async function load() {
    dispose();
    const current = generation;
    busy = true;
    controls();
    paper.hidden = true;
    status.hidden = true;
    status.classList.remove('has-error');
    find('retry').hidden = true;
    find('message').textContent = 'Pregătim documentul…';
    const timer = setTimeout(() => { if (current === generation && busy) status.hidden = false; }, 300);
    request = new AbortController();
    try {
      const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/pdf, application/json'}, signal: request.signal});
      if (response.redirected || response.status === 401 || response.status === 403) throw new Error('Sesiunea a expirat sau nu ai acces la acest document.');
      if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) throw new Error('PDF-ul nu a putut fi pregătit. Încearcă din nou.');
      const data = new Uint8Array(await response.arrayBuffer());
      if (current !== generation) return;
      library ||= import(dialog.dataset.libraryUrl).catch(error => { library = null; throw error; });
      const pdfjs = await library;
      if (current !== generation) return;
      pdfjs.GlobalWorkerOptions.workerSrc = dialog.dataset.workerUrl;
      // Render locally without CDN, blob fonts or eval; retain the site's CSP.
      loadingTask = pdfjs.getDocument({data, isEvalSupported: false, disableFontFace: true, useSystemFonts: false, useWasm: false, isImageDecoderSupported: false});
      const loaded = await loadingTask.promise;
      if (current !== generation) return;
      pdf = loaded;
      pageNumber = 1;
      zoom = 1;
      await render(true);
    } catch (error) {
      if (current === generation && dialog.open && error.name !== 'AbortError') {
        failure(error.message?.startsWith('Sesiunea') ? error.message : 'Previzualizarea nu s-a încărcat. Poți reîncerca sau deschide PDF-ul separat.');
      }
    } finally { clearTimeout(timer); }
  }

  function close() {
    if (!dialog.open || dialog.classList.contains('is-closing')) return;
    dispose(false);
    dialog.classList.add('is-closing');
    const finish = () => {
      dialog.close();
      paper.replaceChildren();
      dialog.classList.remove('is-closing');
      window.document.documentElement.classList.remove('has-nir-pdf');
      opener?.focus({preventScroll: true});
    };
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) finish();
    else closeTimer = setTimeout(finish, 170);
  }

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-nir-pdf-open]');
    if (!trigger || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    clearTimeout(closeTimer);
    opener = trigger;
    url = trigger.href;
    find('title').textContent = trigger.dataset.documentNumber || 'Document NIR';
    find('open').href = url;
    const download = new URL(url);
    download.searchParams.delete('view');
    find('download').href = download.href;
    dialog.classList.remove('is-closing');
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('has-nir-pdf');
    find('close').focus({preventScroll: true});
    load();
  });
  find('close').addEventListener('click', close);
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  let backdropStart = false;
  dialog.addEventListener('pointerdown', event => { backdropStart = event.target === dialog; });
  dialog.addEventListener('click', event => { if (event.target === dialog && backdropStart) close(); });
  previous.addEventListener('click', () => { if (!busy && pageNumber > 1) { --pageNumber; render(true); } });
  next.addEventListener('click', () => { if (!busy && pdf && pageNumber < pdf.numPages) { ++pageNumber; render(true); } });
  zoomIn.addEventListener('click', () => { zoom = Math.min(3, zoom + 0.5); render(); });
  zoomOut.addEventListener('click', () => { zoom = Math.max(1, zoom - 0.5); render(); });
  fit.addEventListener('click', () => { zoom = 1; render(true); });
  find('retry').addEventListener('click', load);
  find('download').addEventListener('click', event => {
    if (window.NirExports && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      event.preventDefault();
      window.NirExports.download(event.currentTarget.href, event.currentTarget);
    }
  });
  let lastWidth = 0;
  new ResizeObserver(() => {
    if (body.clientWidth === lastWidth) return;
    lastWidth = body.clientWidth;
    clearTimeout(resizeTimer);
    if (dialog.open && pdf) resizeTimer = setTimeout(() => render(), 120);
  }).observe(body);
})();
