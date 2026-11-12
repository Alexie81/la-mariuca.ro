(() => {
  'use strict';
  const dialog=document.querySelector('[data-nir-export-loader]');
  if(!dialog || typeof dialog.showModal!=='function')return;
  const title=dialog.querySelector('[data-export-title]'),copy=dialog.querySelector('[data-export-copy]'),
    cancel=dialog.querySelector('[data-export-cancel]'),retry=dialog.querySelector('[data-export-retry]'),
    progress=dialog.querySelector('[data-transfer-progress]'),bar=dialog.querySelector('[data-transfer-bar]'),amount=dialog.querySelector('[data-transfer-amount]');
  let active=null,last=null;
  function filename(header){
    const encoded=/filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header || '');
    let name='';
    if(encoded){try{name=decodeURIComponent(encoded[1].trim());}catch{/* Fall back to the ASCII filename. */}}
    if(!name)name=/filename\s*=\s*"([^"]+)"/i.exec(header || '')?.[1] || 'Document-NIR';
    return name.replace(/[\\/\x00-\x1f\x7f]/g,'_').slice(0,240);
  }
  function close(){if(dialog.open)dialog.close();}
  function notify(text){document.dispatchEvent(new CustomEvent('nir:download-message',{detail:text}));}
  async function download(url,trigger){
    if(active)return;
    const controller=new AbortController(),job={controller,trigger};active=job;last={url,trigger};
    dialog.classList.remove('has-error');progress.hidden=true;retry.hidden=true;cancel.hidden=false;cancel.textContent='Anulează';
    title.textContent='Pregătim fișierul tău';copy.textContent='Un moment, aranjăm documentele pentru descărcare.';
    trigger?.setAttribute('aria-busy','true');
    const timer=setTimeout(()=>{if(active===job&&!dialog.open)dialog.showModal();},300);
    try{
      const response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/octet-stream, application/json'},signal:controller.signal});
      const type=(response.headers.get('Content-Type') || '').toLowerCase();
      if(!response.ok || type.includes('json') || type.includes('text/html')){
        let message='Fișierul nu a putut fi pregătit. Încearcă din nou.';
        if(type.includes('json')){const result=await response.json();message=result.message || message;}
        else if(response.status===401 || response.status===403 || response.redirected)message='Sesiunea a expirat sau nu ai acces. Autentifică-te din nou.';
        throw new Error(message);
      }
      title.textContent='Descărcăm documentul';copy.textContent='Păstrează această pagină deschisă. Fișierul este în curs de transfer.';
      const blob=await response.blob();
      if(controller.signal.aborted)return;
      if(!blob.size)throw new Error('Fișierul primit este gol. Încearcă din nou.');
      const objectUrl=URL.createObjectURL(blob),link=document.createElement('a');
      link.href=objectUrl;link.download=filename(response.headers.get('Content-Disposition'));link.hidden=true;
      document.body.append(link);link.click();link.remove();
      setTimeout(()=>URL.revokeObjectURL(objectUrl),60000);
      close();notify('Descărcarea a pornit. Fișierul apare în descărcările browserului.');
    }catch(error){
      if(controller.signal.aborted){close();return;}
      dialog.classList.add('has-error');title.textContent='Descărcarea nu a reușit';
      copy.textContent=error.message || 'Verifică conexiunea și încearcă din nou.';
      cancel.textContent='Închide';retry.hidden=false;
      if(!dialog.open)dialog.showModal();
    }finally{
      clearTimeout(timer);if(active===job)active=null;trigger?.removeAttribute('aria-busy');
      if(!dialog.open && trigger?.isConnected)trigger.focus({preventScroll:true});
    }
  }
  function cancelDownload(){if(active?.kind==='upload')return;if(active)active.controller.abort();close();}
  cancel.addEventListener('click',cancelDownload);
  dialog.addEventListener('cancel',event=>{event.preventDefault();cancelDownload();});
  retry.addEventListener('click',()=>{if(last)download(last.url,last.trigger);});
  // Uploads can commit a document on the server. Keep their status visible until
  // the response arrives, rather than implying that closing a dialog cancels saving.
  window.NirTransfer={upload(){
    if(active)return null;
    const job={kind:'upload'};active=job;
    dialog.classList.remove('has-error');retry.hidden=true;cancel.hidden=true;
    progress.hidden=false;bar.value=0;amount.textContent='0%';
    title.textContent='Încărcăm documentele';
    copy.textContent='Trimitem fișierele tale. Păstrează această pagină deschisă.';
    const timer=setTimeout(()=>{if(active===job&&!dialog.open)dialog.showModal();},300);
    return {
      update(percent,text,processing=false){
        if(active!==job)return;
        bar.value=percent;amount.textContent=text;
        if(processing){title.textContent='Verificăm documentele';copy.textContent='Transferul este complet. Așteptăm confirmarea salvării.';}
      },
      finish(){clearTimeout(timer);if(active===job){active=null;close();}}
    };
  }};
  window.NirExports={download,fromForm(form){
    const url=new URL(form.action,location.href);
    for(const [key,value] of new FormData(form))if(typeof value==='string')url.searchParams.set(key,value);
    return download(url.href,form.querySelector('button[type="submit"]'));
  }};
})();
