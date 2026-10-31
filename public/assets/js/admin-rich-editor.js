(() => {
  'use strict';

  const editorStates = new WeakMap();
  const allowedPasteTags = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'H2', 'H3', 'H4', 'BLOCKQUOTE', 'A']);

  const escapeHtml = (value) => String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const plainTextToHtml = (value) => String(value || '')
    .replace(/\r\n?/g, '\n')
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean)
    .map((paragraph) => `<p>${escapeHtml(paragraph).replaceAll('\n', '<br>')}</p>`)
    .join('');

  const initialHtml = (value) => /<\s*(?:p|br|strong|em|u|ul|ol|li|h[2-4]|blockquote|a|div|img)\b/i.test(value || '')
    ? value
    : plainTextToHtml(value);

  const topLevelBlock = (node, canvas) => {
    let current = node?.nodeType === Node.TEXT_NODE ? node.parentElement : node;
    while (current && current.parentElement !== canvas) current = current.parentElement;
    return current?.parentElement === canvas ? current : null;
  };

  const normalizeImageWidth = (block) => {
    const match = String(block?.style.width || '').match(/([\d.]+)%/);
    return Math.max(30, Math.min(100, Math.round(Number.parseFloat(match?.[1] || '100'))));
  };

  const imageAlignment = (block) => {
    if (block?.classList.contains('product-rich-image--left')) return 'left';
    if (block?.classList.contains('product-rich-image--right')) return 'right';
    return 'center';
  };

  const setEditorStatus = (editor, message, state = 'ready') => {
    const status = editor.querySelector('[data-rich-editor-status]');
    if (status) status.textContent = message;
    editor.dataset.richEditorState = state;
  };

  const serializeEditor = (editor) => {
    const state = editorStates.get(editor);
    if (!state) return '';
    const clone = state.canvas.cloneNode(true);
    clone.querySelectorAll('[data-rich-editor-ui]').forEach((element) => element.remove());
    clone.querySelectorAll('.product-rich-image').forEach((block) => {
      block.classList.remove('is-selected', 'is-dragging', 'is-drop-before', 'is-drop-after');
      block.removeAttribute('contenteditable');
      block.removeAttribute('draggable');
      block.removeAttribute('tabindex');
      block.removeAttribute('aria-label');
      const width = normalizeImageWidth(block);
      block.setAttribute('style', `width: ${width}%;`);
      block.querySelectorAll('img').forEach((image) => image.removeAttribute('draggable'));
    });
    const html = clone.innerHTML.trim();
    state.source.value = html === '<p><br></p>' ? '' : html;
    return state.source.value;
  };

  const dispatchEditorChange = (editor) => {
    const state = editorStates.get(editor);
    if (!state) return;
    serializeEditor(editor);
    state.canvas.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const updateImageButtons = (editor) => {
    const state = editorStates.get(editor);
    if (!state) return;
    const selected = state.selectedImage;
    const up = editor.querySelector('[data-rich-image-up]');
    const down = editor.querySelector('[data-rich-image-down]');
    const settings = editor.querySelector('[data-rich-image-settings]');
    if (up) up.disabled = !selected || !selected.previousElementSibling;
    if (down) down.disabled = !selected || !selected.nextElementSibling;
    if (settings) {
      settings.disabled = !selected;
      settings.setAttribute('aria-expanded', selected && !state.panel.hidden ? 'true' : 'false');
    }
  };

  const updateImageControls = (editor) => {
    const state = editorStates.get(editor);
    const selected = state?.selectedImage;
    if (!state || !selected) return;
    const width = normalizeImageWidth(selected);
    state.size.value = String(width);
    state.sizeOutput.textContent = `${width}%`;
    selected.querySelector('[data-rich-image-width-badge]')?.replaceChildren(`${width}%`);
    state.alt.value = selected.querySelector('img')?.alt || '';
    const alignment = imageAlignment(selected);
    state.panel.querySelectorAll('[data-rich-image-align]').forEach((button) => {
      const active = button.dataset.richImageAlign === alignment;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateImageButtons(editor);
  };

  const closeImagePanel = (editor, deselect = false) => {
    const state = editorStates.get(editor);
    if (!state) return;
    state.panel.hidden = true;
    if (deselect && state.selectedImage) {
      state.selectedImage.classList.remove('is-selected');
      state.selectedImage = null;
    }
    updateImageButtons(editor);
  };

  const selectImage = (editor, block, openPanel = true) => {
    const state = editorStates.get(editor);
    if (!state || !block) return;
    state.selectedImage?.classList.remove('is-selected');
    state.selectedImage = block;
    block.classList.add('is-selected');
    if (openPanel) state.panel.hidden = false;
    updateImageControls(editor);
  };

  const applyImageWidth = (editor, width) => {
    const state = editorStates.get(editor);
    if (!state?.selectedImage) return;
    const normalized = Math.max(30, Math.min(100, Math.round(Number(width) || 100)));
    state.selectedImage.style.width = `${normalized}%`;
    state.size.value = String(normalized);
    state.sizeOutput.textContent = `${normalized}%`;
    const badge = state.selectedImage.querySelector('[data-rich-image-width-badge]');
    if (badge) badge.textContent = `${normalized}%`;
  };

  const hydrateImage = (editor, block) => {
    if (!block || block.dataset.richImageEnhanced === 'true') return;
    block.dataset.richImageEnhanced = 'true';
    block.classList.add('product-rich-image');
    if (!block.classList.contains('product-rich-image--left') && !block.classList.contains('product-rich-image--right')) {
      block.classList.add('product-rich-image--center');
    }
    block.style.width = `${normalizeImageWidth(block)}%`;
    block.contentEditable = 'false';
    block.draggable = true;
    block.tabIndex = 0;
    block.setAttribute('aria-label', 'Imagine în descriere. Apasă pentru opțiuni sau trage pentru mutare.');
    block.querySelector('img')?.setAttribute('draggable', 'false');

    const badge = document.createElement('span');
    badge.className = 'admin-rich-editor__image-width-badge';
    badge.dataset.richEditorUi = '';
    badge.dataset.richImageWidthBadge = '';
    badge.textContent = `${normalizeImageWidth(block)}%`;
    const handle = document.createElement('button');
    handle.type = 'button';
    handle.className = 'admin-rich-editor__resize-handle';
    handle.dataset.richEditorUi = '';
    handle.dataset.richImageResize = '';
    handle.setAttribute('aria-label', 'Redimensionează imaginea');
    handle.innerHTML = '<svg viewBox="0 0 18 18" aria-hidden="true"><path d="M6 14h8V6M9 11l5-5"/></svg>';
    block.append(badge, handle);
  };

  const createImageBlock = (editor, payload) => {
    const block = document.createElement('div');
    block.className = 'product-rich-image product-rich-image--center';
    block.style.width = '100%';
    const image = document.createElement('img');
    image.src = payload.url;
    image.alt = payload.alt || '';
    if (payload.width) image.width = payload.width;
    if (payload.height) image.height = payload.height;
    block.append(image);
    hydrateImage(editor, block);
    return block;
  };

  const rememberSelection = (editor) => {
    const state = editorStates.get(editor);
    const selection = window.getSelection();
    if (!state || !selection?.rangeCount) return;
    const range = selection.getRangeAt(0);
    if (state.canvas.contains(range.commonAncestorContainer)) state.range = range.cloneRange();
  };

  const restoreSelection = (editor) => {
    const state = editorStates.get(editor);
    if (!state?.range) return false;
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(state.range);
    return true;
  };

  const insertImageBlock = (editor, block) => {
    const state = editorStates.get(editor);
    if (!state) return;
    const anchor = state.range ? topLevelBlock(state.range.startContainer, state.canvas) : null;
    if (anchor) anchor.after(block);
    else state.canvas.append(block);
    if (!block.nextElementSibling) {
      const paragraph = document.createElement('p');
      paragraph.append(document.createElement('br'));
      block.after(paragraph);
    }
    selectImage(editor, block, true);
    dispatchEditorChange(editor);
    block.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const uploadImage = async (editor, file) => {
    const state = editorStates.get(editor);
    if (!state || !file) return;
    if (!/^image\/(?:jpeg|png|webp)$/i.test(file.type || '')) {
      setEditorStatus(editor, 'Alege o imagine JPG, PNG sau WebP.', 'error');
      return;
    }
    const form = editor.closest('form');
    const body = new FormData();
    body.append('_csrf', form?.querySelector('input[name="_csrf"]')?.value || '');
    body.append('image', file, file.name || 'imagine-produs');
    body.append('alt_text', String(file.name || '').replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' '));
    editor.classList.add('is-uploading');
    setEditorStatus(editor, 'Optimizăm și încărcăm imaginea…', 'uploading');
    try {
      const response = await fetch(editor.dataset.uploadUrl || '', {
        method: 'POST',
        body,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const contentType = response.headers.get('Content-Type') || '';
      if (!contentType.includes('application/json')) throw new Error('Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.');
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Imaginea nu a putut fi încărcată.');
      insertImageBlock(editor, createImageBlock(editor, payload));
      setEditorStatus(editor, 'Imagine adăugată. O poți redimensiona sau muta.', 'success');
    } catch (error) {
      setEditorStatus(editor, error.message || 'Imaginea nu a putut fi încărcată.', 'error');
    } finally {
      editor.classList.remove('is-uploading');
    }
  };

  const sanitizePastedHtml = (html) => {
    const source = document.createElement('div');
    source.innerHTML = html;
    const clean = (node) => {
      if (node.nodeType === Node.TEXT_NODE) return document.createTextNode(node.textContent || '');
      if (node.nodeType !== Node.ELEMENT_NODE) return document.createDocumentFragment();
      const children = Array.from(node.childNodes).map(clean);
      if (!allowedPasteTags.has(node.tagName)) {
        const fragment = document.createDocumentFragment();
        children.forEach((child) => fragment.append(child));
        return fragment;
      }
      const tag = node.tagName === 'B' ? 'strong' : (node.tagName === 'I' ? 'em' : node.tagName.toLowerCase());
      const element = document.createElement(tag);
      if (tag === 'a') {
        const href = node.getAttribute('href') || '';
        if (/^(?:https?:|\/)/i.test(href)) element.setAttribute('href', href);
      }
      children.forEach((child) => element.append(child));
      return element;
    };
    const output = document.createElement('div');
    Array.from(source.childNodes).forEach((node) => output.append(clean(node)));
    return output.innerHTML;
  };

  const runCommand = (editor, command) => {
    const state = editorStates.get(editor);
    if (!state) return;
    state.canvas.focus({ preventScroll: true });
    restoreSelection(editor);
    document.execCommand(command, false);
    rememberSelection(editor);
    dispatchEditorChange(editor);
  };

  const initEditor = (editor) => {
    if (editor.dataset.richEditorEnhanced === 'true') return;
    const field = editor.closest('.admin-product-rich-field');
    const source = field?.querySelector('[data-rich-editor-source]');
    const canvas = editor.querySelector('[data-rich-editor-canvas]');
    const panel = editor.querySelector('[data-rich-image-panel]');
    const size = editor.querySelector('[data-rich-image-size]');
    const sizeOutput = editor.querySelector('[data-rich-image-size-output]');
    const alt = editor.querySelector('[data-rich-image-alt]');
    const input = editor.querySelector('[data-rich-image-input]');
    if (!source || !canvas || !panel || !size || !sizeOutput || !alt || !input) return;

    editor.dataset.richEditorEnhanced = 'true';
    editor.hidden = false;
    source.hidden = true;
    canvas.innerHTML = initialHtml(source.value);
    const state = { source, canvas, panel, size, sizeOutput, alt, input, selectedImage: null, range: null, draggedImage: null };
    editorStates.set(editor, state);
    canvas.querySelectorAll('.product-rich-image').forEach((block) => hydrateImage(editor, block));
    serializeEditor(editor);

    canvas.addEventListener('input', () => {
      rememberSelection(editor);
      serializeEditor(editor);
      setEditorStatus(editor, 'Modificările vor fi păstrate când salvezi produsul.', 'changed');
    });
    canvas.addEventListener('keyup', () => rememberSelection(editor));
    canvas.addEventListener('mouseup', () => rememberSelection(editor));
    canvas.addEventListener('focus', () => rememberSelection(editor));
    canvas.addEventListener('click', (event) => {
      const block = event.target.closest('.product-rich-image');
      if (block) selectImage(editor, block, true);
      else closeImagePanel(editor, true);
    });
    canvas.addEventListener('contextmenu', (event) => {
      const block = event.target.closest('.product-rich-image');
      if (!block) return;
      event.preventDefault();
      selectImage(editor, block, true);
      requestAnimationFrame(() => alt.focus({ preventScroll: true }));
    });
    canvas.addEventListener('keydown', (event) => {
      const block = event.target.closest?.('.product-rich-image');
      if (!block) return;
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectImage(editor, block, true);
      }
      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        block.remove();
        closeImagePanel(editor, true);
        dispatchEditorChange(editor);
      }
    });
    canvas.addEventListener('paste', (event) => {
      const imageFiles = Array.from(event.clipboardData?.items || [])
        .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
        .map((item) => item.getAsFile())
        .filter(Boolean);
      if (imageFiles.length) {
        event.preventDefault();
        imageFiles.reduce((promise, file) => promise.then(() => uploadImage(editor, file)), Promise.resolve());
        return;
      }
      const html = event.clipboardData?.getData('text/html') || '';
      if (html) {
        event.preventDefault();
        document.execCommand('insertHTML', false, sanitizePastedHtml(html));
      }
      window.setTimeout(() => dispatchEditorChange(editor));
    });

    editor.querySelectorAll('[data-rich-command]').forEach((button) => {
      button.addEventListener('mousedown', (event) => {
        event.preventDefault();
        rememberSelection(editor);
      });
      button.addEventListener('click', () => runCommand(editor, button.dataset.richCommand));
    });
    editor.querySelector('[data-rich-image-add]')?.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      input.value = '';
      if (file) uploadImage(editor, file);
    });
    editor.querySelector('[data-rich-image-panel-close]')?.addEventListener('click', () => closeImagePanel(editor));
    editor.querySelector('[data-rich-image-settings]')?.addEventListener('click', () => {
      if (!state.selectedImage) return;
      state.panel.hidden = !state.panel.hidden;
      updateImageButtons(editor);
    });
    size.addEventListener('input', () => applyImageWidth(editor, size.value));
    size.addEventListener('change', () => dispatchEditorChange(editor));
    alt.addEventListener('input', () => {
      const image = state.selectedImage?.querySelector('img');
      if (image) image.alt = alt.value;
      serializeEditor(editor);
    });
    alt.addEventListener('change', () => dispatchEditorChange(editor));
    panel.querySelectorAll('[data-rich-image-align]').forEach((button) => {
      button.addEventListener('click', () => {
        const selected = state.selectedImage;
        if (!selected) return;
        selected.classList.remove('product-rich-image--left', 'product-rich-image--center', 'product-rich-image--right');
        selected.classList.add(`product-rich-image--${button.dataset.richImageAlign}`);
        updateImageControls(editor);
        dispatchEditorChange(editor);
      });
    });
    editor.querySelector('[data-rich-image-remove]')?.addEventListener('click', () => {
      if (!state.selectedImage) return;
      const removed = state.selectedImage;
      closeImagePanel(editor, true);
      removed.classList.add('is-removing');
      window.setTimeout(() => {
        removed.remove();
        dispatchEditorChange(editor);
      }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
    });

    const moveImage = (direction) => {
      const selected = state.selectedImage;
      const sibling = direction === 'up' ? selected?.previousElementSibling : selected?.nextElementSibling;
      if (!selected || !sibling) return;
      if (direction === 'up') sibling.before(selected);
      else sibling.after(selected);
      updateImageButtons(editor);
      dispatchEditorChange(editor);
      selected.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    editor.querySelector('[data-rich-image-up]')?.addEventListener('click', () => moveImage('up'));
    editor.querySelector('[data-rich-image-down]')?.addEventListener('click', () => moveImage('down'));

    canvas.addEventListener('pointerdown', (event) => {
      const handle = event.target.closest('[data-rich-image-resize]');
      const block = handle?.closest('.product-rich-image');
      if (!handle || !block) return;
      event.preventDefault();
      selectImage(editor, block, true);
      block.classList.add('is-resizing');
      const startX = event.clientX;
      const startWidth = normalizeImageWidth(block);
      const move = (moveEvent) => {
        const rect = canvas.getBoundingClientRect();
        const multiplier = imageAlignment(block) === 'center' ? 2 : 1;
        const change = ((moveEvent.clientX - startX) / Math.max(1, rect.width)) * 100 * multiplier;
        applyImageWidth(editor, startWidth + change);
      };
      const finish = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', finish);
        block.classList.remove('is-resizing');
        dispatchEditorChange(editor);
      };
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', finish, { once: true });
    });

    canvas.addEventListener('dragstart', (event) => {
      const block = event.target.closest('.product-rich-image');
      if (!block) return;
      state.draggedImage = block;
      block.classList.add('is-dragging');
      selectImage(editor, block, false);
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', 'product-description-image');
    });
    canvas.addEventListener('dragover', (event) => {
      if (!state.draggedImage) return;
      event.preventDefault();
      const target = topLevelBlock(event.target, canvas);
      canvas.querySelectorAll('.is-drop-before,.is-drop-after').forEach((item) => item.classList.remove('is-drop-before', 'is-drop-after'));
      if (!target || target === state.draggedImage) return;
      const after = event.clientY > target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
      target.classList.add(after ? 'is-drop-after' : 'is-drop-before');
    });
    canvas.addEventListener('drop', (event) => {
      if (!state.draggedImage) return;
      event.preventDefault();
      const target = topLevelBlock(event.target, canvas);
      if (target && target !== state.draggedImage) {
        if (target.classList.contains('is-drop-after')) target.after(state.draggedImage);
        else target.before(state.draggedImage);
      } else if (!target) canvas.append(state.draggedImage);
      dispatchEditorChange(editor);
    });
    canvas.addEventListener('dragend', () => {
      canvas.querySelectorAll('.is-dragging,.is-drop-before,.is-drop-after').forEach((item) => item.classList.remove('is-dragging', 'is-drop-before', 'is-drop-after'));
      state.draggedImage = null;
      updateImageButtons(editor);
    });
  };

  const initializeEditors = (root = document) => {
    if (root.matches?.('[data-product-rich-editor]')) initEditor(root);
    root.querySelectorAll?.('[data-product-rich-editor]').forEach(initEditor);
  };

  initializeEditors(document);
  const observer = new MutationObserver((records) => {
    records.forEach((record) => record.addedNodes.forEach((node) => {
      if (node.nodeType === Node.ELEMENT_NODE) initializeEditors(node);
    }));
  });
  observer.observe(document.body, { childList: true, subtree: true });
  document.addEventListener('submit', (event) => {
    event.target.querySelectorAll?.('[data-product-rich-editor]').forEach(serializeEditor);
  }, true);
})();
