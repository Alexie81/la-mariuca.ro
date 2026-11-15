(() => {
  'use strict';
  const modal = document.querySelector('[data-stock-dialog]');
  const exportModal = document.querySelector('[data-stock-export-dialog]');
  if (!modal || typeof modal.showModal !== 'function') return;
  const body = modal.querySelector('[data-stock-dialog-body]');
  const head = modal.querySelector('[data-stock-dialog-head]');
  const originalHead = head.innerHTML;
  let controller, opener, currentUrl;
  const lock = () => document.documentElement.classList.toggle('stock-modal-open', modal.open || exportModal.open);
  const dirty = () => [...modal.querySelectorAll('[data-stock-threshold] input[name="minimum"]')].some(input => input.value !== input.defaultValue);
  function close() {
    if (dirty() && !window.confirm('Renunți la modificările nesalvate ale pragului?')) return;
    controller?.abort(); modal.close(); lock(); opener?.focus({preventScroll:true});
  }
  async function load(url, trigger, opening = false) {
    controller?.abort(); const job = new AbortController(); controller = job;
    if (opening) { opener = trigger; head.innerHTML = originalHead; body.innerHTML = '<div class="stock-loading" role="status"><span></span>Încărcăm fișa produsului…</div>'; modal.showModal(); lock(); }
    body.setAttribute('aria-busy', 'true');
    const scroll = opening ? 0 : body.scrollTop;
    try {
      const response = await fetch(url, {credentials:'same-origin',signal:job.signal});
      const html = new DOMParser().parseFromString(await response.text(), 'text/html');
      const detail = html.querySelector('[data-stock-detail]');
      if (!response.ok || !detail) throw new Error(response.redirected ? 'Sesiunea a expirat. Autentifică-te din nou.' : 'Fișa nu a putut fi încărcată. Reîncearcă.');
      const hero = detail.querySelector('.stock-detail-hero');
      hero.querySelector('h1').id = 'stock-sheet-title';
      detail.querySelector('.stock-back')?.remove();
      head.replaceChildren(hero);
      const closeButton = document.createElement('button'); closeButton.type = 'button'; closeButton.className = 'stock-icon-btn'; closeButton.dataset.stockClose = ''; closeButton.setAttribute('aria-label','Închide fișa'); closeButton.innerHTML = '<svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg>'; head.append(closeButton);
      body.replaceChildren(detail); body.scrollTop = scroll; currentUrl = url;
    } catch(error) {
      if (job.signal.aborted) return;
      const errorBox=document.createElement('div'); errorBox.className='stock-empty';
      const message=document.createElement('p'); message.textContent=error.message;
      const retry=document.createElement('button'); retry.className='stock-btn';retry.textContent='Reîncearcă';retry.addEventListener('click',()=>load(url,trigger));
      errorBox.append(message,retry);body.replaceChildren(errorBox);
    } finally { if (controller===job) body.removeAttribute('aria-busy'); }
  }
  document.addEventListener('click', event => {
    const open = event.target.closest('[data-stock-open]');
    if(open && !event.ctrlKey && !event.metaKey && !event.shiftKey) {event.preventDefault();load(open.href,open,true);return;}
    const paging=event.target.closest('[data-stock-page]');
    if(paging && modal.contains(paging)) {event.preventDefault();load(paging.href,paging);return;}
    if(event.target.closest('[data-stock-close]')) {close();return;}
    const exportButton=event.target.closest('[data-stock-export]');
    if(exportButton) {event.preventDefault();exportModal.showModal();lock();preview();return;}
    if(event.target.closest('[data-stock-export-close]')) {exportModal.close();lock();return;}
    const download=event.target.closest('[data-stock-download]');
    if(download && window.NirExports) {event.preventDefault();window.NirExports.download(download.href,download);}
  });
  modal.addEventListener('cancel',event=>{event.preventDefault();close();});
  modal.addEventListener('click',event=>{if(event.target===modal) {const r=modal.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)close();}});
  exportModal.addEventListener('close',lock);
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if(form.matches('[data-stock-filter]') && modal.contains(form)) {
      event.preventDefault();const url=new URL(form.action,location.href);url.search=new URLSearchParams(new FormData(form));load(url.href,form);return;
    }
    if(form.matches('[data-stock-export-form]') && window.NirExports) {event.preventDefault();exportModal.close();lock();window.NirExports.fromForm(form);return;}
    if(!form.matches('[data-stock-threshold]'))return;
    event.preventDefault();const button=form.querySelector('button');const message=form.querySelector('[data-stock-form-message]');button.disabled=true;message.textContent='';
    try {const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new FormData(form)});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Pragul nu a putut fi salvat.');message.textContent=result.message;message.className='stock-form-success';form.querySelector('[name="minimum"]').defaultValue=form.querySelector('[name="minimum"]').value;}
    catch(error){message.className='stock-form-error';message.textContent=error.message||'Salvarea nu a reușit. Reîncearcă.';}finally{button.disabled=false;}
  });
  function preview() {
    const form=exportModal.querySelector('form'), selected=form.querySelector('[name="period"]:checked').value;
    const custom=selected==='custom';const fields=form.querySelector('[data-stock-custom]');fields.hidden=!custom;fields.querySelectorAll('input').forEach(input=>{input.disabled=!custom;input.required=custom;});
    const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Bucharest',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
    const values=Object.fromEntries(parts.map(part=>[part.type,part.value]));const today=new Date(Number(values.year),Number(values.month)-1,Number(values.day));let start=new Date(today),end=new Date(today);
    if(selected==='year')start=new Date(today.getFullYear(),0,1);
    if(selected==='month')start=new Date(today.getFullYear(),today.getMonth(),1);
    if(selected==='previous'){start=new Date(today.getFullYear(),today.getMonth()-1,1);end=new Date(today.getFullYear(),today.getMonth(),0);}
    if(selected==='six'||selected==='three')start.setMonth(start.getMonth()-(selected==='six'?6:3));
    if(custom){start=new Date(form.elements.from.value+'T12:00:00');end=new Date(form.elements.to.value+'T12:00:00');}
    const fmt=date=>new Intl.DateTimeFormat('ro-RO').format(date);
    form.querySelector('[data-stock-period-preview]').textContent=selected==='all'?'De la prima înregistrare până astăzi':(Number.isNaN(+start)||Number.isNaN(+end)?'Completează începutul și sfârșitul perioadei.':fmt(start)+' — '+fmt(end));
    form.elements.to.min=form.elements.from.value;
  }
  exportModal.addEventListener('change',preview);preview();
})();
