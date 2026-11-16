(() => {
  'use strict';
  const dialog=document.querySelector('[data-nir-export-loader]');
  if(!dialog || typeof dialog.showModal!=='function')return;
  const title=dialog.querySelector('[data-export-title]'),copy=dialog.querySelector('[data-export-copy]'),
    cancel=dialog.querySelector('[data-export-cancel]'),retry=dialog.querySelector('[data-export-retry]'),
    progress=dialog.querySelector('[data-transfer-progress]'),bar=dialog.querySelector('[data-transfer-bar]'),amount=dialog.querySelector('[data-transfer-amount]'),
    stats=dialog.querySelector('[data-export-stats]'),stage=dialog.querySelector('[data-export-stage]');
  let active=null,last=null;
  function filename(header){
    const encoded=/filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header || '');
    let name='';
    if(encoded){try{name=decodeURIComponent(encoded[1].trim());}catch{/* Fall back to the ASCII filename. */}}
    if(!name)name=/filename\s*=\s*"([^"]+)"/i.exec(header || '')?.[1] || 'Document-La-Mariuca';
    return name.replace(/[\\/\x00-\x1f\x7f]/g,'_').slice(0,240);
  }
  function close(){if(dialog.open)dialog.close();}
  function notify(text){document.dispatchEvent(new CustomEvent('nir:download-message',{detail:text}));}
  function preparation(text){progress.hidden=false;bar.removeAttribute?.('value');amount.textContent=text;}
  function counts(summary){
    if(!stats)return;
    stats.hidden=false;
    for(const key of ['documents','positions','files'])stats.querySelector(`[data-export-${key}]`).textContent=Number(summary[key]).toLocaleString('ro-RO');
    stats.querySelector('[data-export-label]').textContent=summary.label || 'Documente';
  }
  const metadataAvailable=url=>/\/admin\/(?:facturi\/(?:\d+\/(?:pdf|xlsx|xml|zip)|export\/(?:descarca|xlsx|zip))|nir-uri\/(?:\d+\/)?export|stoc\/(?:\d+\/)?export|produse\/export|comenzi\/\d+\/aviz\/pdf)(?:\/|$)/.test(url.pathname);
  async function checked(response){
    const type=(response.headers.get('Content-Type') || '').toLowerCase();
    if(!response.ok || response.redirected || type.includes('text/html')){
      let message='Fișierul nu a putut fi pregătit. Încearcă din nou.';
      if(type.includes('json')){const result=await response.json();message=result.message || message;}
      else if(response.status===401 || response.status===403 || response.redirected || type.includes('text/html'))message='Sesiunea a expirat sau nu ai acces. Autentifică-te din nou.';
      throw new Error(message);
    }
    return type;
  }
  async function download(url,trigger){
    if(active)return false;
    const target=new URL(url,location.href);if(target.origin!==new URL(location.href).origin)return false;
    const controller=new AbortController(),job={controller,trigger};active=job;last={url,trigger};
    dialog.classList.remove('has-error');progress.hidden=true;retry.hidden=true;cancel.hidden=false;cancel.textContent='Anulează';
    if(stats)stats.hidden=true;if(stage)stage.textContent='01 · Verificăm selecția';
    const zip=target.pathname.includes('/zip')||target.searchParams.get('format')==='zip';
    title.textContent=zip?'Pregătim arhiva completă':'Pregătim exportul tău';copy.textContent='Verificăm documentele și pregătim fișierele pentru tine.';
    preparation('În pregătire');
    trigger?.setAttribute('aria-busy','true');
    const timer=setTimeout(()=>{if(active===job&&!dialog.open)dialog.showModal();},300);
    try{
      if(metadataAvailable(target)){
        const infoUrl=new URL(target);infoUrl.searchParams.set('export_info','1');
        const info=await fetch(infoUrl.href,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},signal:controller.signal});
        await checked(info);const result=await info.json();
        if(!result.ok||!result.export)throw new Error(result.message||'Selecția exportului nu a putut fi verificată.');
        if(controller.signal.aborted)return false;
        counts(result.export);
      }
      if(stage)stage.textContent='02 · Generăm fișierele';
      copy.textContent=zip?'Generăm documentele și le organizăm în arhiva ZIP.':'Aranjăm datele și pregătim documentul pentru descărcare.';
      const response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/octet-stream, application/json'},signal:controller.signal});
      const type=await checked(response);
      if(type.includes('json')){
        let message='Fișierul nu a putut fi pregătit. Încearcă din nou.';
        const result=await response.json();message=result.message || message;
        throw new Error(message);
      }
      if(!response.headers.get('Content-Disposition'))throw new Error('Fișierul nu este disponibil. Reîncarcă pagina și încearcă din nou.');
      if(stage)stage.textContent='03 · Descărcăm';
      title.textContent='Exportul este pregătit';copy.textContent='Transferăm fișierul în descărcările browserului.';
      let blob;
      const encoding=response.headers.get('Content-Encoding');
      const total=encoding && encoding!=='identity'?0:Number(response.headers.get('Content-Length'));
      if(response.body?.getReader){
        const reader=response.body.getReader(),chunks=[];let received=0;
        while(true){const {done,value}=await reader.read();if(done)break;chunks.push(value);received+=value.length;
          if(total>0){bar.value=Math.min(100,received/total*100);amount.textContent=Math.floor(bar.value)+'% · transfer';}
          else amount.textContent=(received/1048576).toLocaleString('ro-RO',{maximumFractionDigits:1})+' MB primiți';
        }
        blob=new Blob(chunks,{type});
      }else blob=await response.blob();
      if(controller.signal.aborted)return false;
      if(!blob.size)throw new Error('Fișierul primit este gol. Încearcă din nou.');
      const objectUrl=URL.createObjectURL(blob),link=document.createElement('a');
      link.href=objectUrl;link.download=filename(response.headers.get('Content-Disposition'));link.hidden=true;
      document.body.append(link);link.click();link.remove();
      setTimeout(()=>URL.revokeObjectURL(objectUrl),60000);
      close();notify('Descărcarea a pornit. Fișierul apare în descărcările browserului.');return true;
    }catch(error){
      if(controller.signal.aborted){close();return false;}
      dialog.classList.add('has-error');title.textContent='Descărcarea nu a reușit';
      progress.hidden=true;if(stage)stage.textContent='Export întrerupt';
      copy.textContent=error.message || 'Verifică conexiunea și încearcă din nou.';
      cancel.textContent='Închide';retry.hidden=false;
      if(!dialog.open)dialog.showModal();return false;
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
    if(stats)stats.hidden=true;if(stage)stage.textContent='Încărcare documente';
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
  window.AdminExports=window.NirExports;
  document.addEventListener?.('click',event=>{
    const link=event.target.closest('a[data-admin-export],a[data-iv-download],a[data-nir-download],a[data-stock-download],a[data-pdf-download]');
    if(!link||event.button>0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||link.target==='_blank'||!link.href)return;
    event.preventDefault();event.stopImmediatePropagation();download(link.href,link);
  },true);
})();
