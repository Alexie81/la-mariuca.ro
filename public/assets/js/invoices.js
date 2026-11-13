(() => {
  'use strict';
  const root = document.querySelector('[data-iv-page]'); if (!root) return;
  const dialog=root.querySelector('[data-iv-dialog]'), content=dialog?.querySelector('[data-iv-dialog-content]');
  const discard=root.querySelector('[data-iv-discard]'), loader=root.querySelector('[data-iv-loader]');
  let opener=null, baseline='', busy=false, timer=null, toastTimer=null, loadingController=null;
  const signature=form=>JSON.stringify([...new FormData(form).entries()].filter(([key])=>!['_csrf','request_key','version'].includes(key)));
  const dirty=()=>{const form=content?.querySelector('form');return form && signature(form)!==baseline;};
  const toast=text=>{const box=root.querySelector('[data-iv-toast]');if(!box)return;box.querySelector('span').textContent=text;box.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>box.hidden=true,1700);};
  const error=(form,text)=>{const box=form?.querySelector('[data-iv-errors]');if(box){box.textContent=text;box.hidden=false;box.focus();}else toast(text);};
  const startLoader=()=>{clearTimeout(timer);timer=setTimeout(()=>{if(loader && !loader.open)loader.showModal();},300);};
  const endLoader=()=>{clearTimeout(timer);if(loader?.open)loader.close();};
  const copy=async text=>{try{await navigator.clipboard.writeText(text);toast('Copiat cu succes');}catch{toast('Copierea nu este disponibilă. Selectează textul pentru copiere.');}};
  const init=scope=>{
    scope.querySelectorAll('[data-iv-select]').forEach(toggle=>{const row=toggle.closest('.iv-select-line'),quantity=row.querySelector('[data-iv-quantity]');quantity.disabled=!toggle.checked;});
    const type=scope.querySelector('[data-iv-customer-type]'),fields=scope.querySelector('[data-iv-company-fields]');if(type && fields)fields.hidden=type.value!=='company';
  };
  const open=async(url,trigger)=>{
    if(busy)return;
    loadingController?.abort();loadingController=new AbortController();opener=trigger||opener;startLoader();
    try{
      const response=await fetch(url,{headers:{'X-Invoice-Modal':'1'},credentials:'same-origin',signal:loadingController.signal});
      if(!response.ok)throw new Error('Formularul nu poate fi deschis. Reîncarcă pagina și încearcă din nou.');
      const html=await response.text();const fragment=new DOMParser().parseFromString(html,'text/html'),form=fragment.querySelector('[data-iv-form]');
      if(!form)throw new Error('Sesiunea a expirat. Reîncarcă pagina pentru autentificare.');
      content.replaceChildren(document.importNode(form,true));init(content);baseline=signature(content.querySelector('form'));
      endLoader();if(!dialog.open)dialog.showModal();dialog.querySelector('[data-iv-close]')?.focus();
    }catch(e){if(e.name!=='AbortError')toast(e.message||'Formularul nu poate fi deschis.');}finally{endLoader();}
  };
  const finishClose=()=>{dialog.dataset.closing='1';setTimeout(()=>{dialog.close();delete dialog.dataset.closing;content.replaceChildren();opener?.focus();},matchMedia('(prefers-reduced-motion: reduce)').matches?0:180);};
  const close=()=>{if(busy)return;if(dirty()){if(!discard.open)discard.showModal();}else finishClose();};
  dialog?.addEventListener('cancel',e=>{e.preventDefault();close();});
  dialog?.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();}});
  discard?.querySelector('[data-iv-keep]')?.addEventListener('click',()=>discard.close());
  discard?.querySelector('[data-iv-abandon]')?.addEventListener('click',()=>{discard.close();finishClose();});
  loader?.addEventListener('cancel',e=>e.preventDefault());
  root.addEventListener('change',e=>{
    const toggle=e.target.closest('[data-iv-select]');if(toggle){const row=toggle.closest('.iv-select-line'),q=row.querySelector('[data-iv-quantity]');q.disabled=!toggle.checked;row.classList.toggle('is-selected',toggle.checked);q.value=toggle.checked?q.dataset.default:'0';if(toggle.checked)q.focus();}
    if(e.target.matches('[data-iv-customer-type]')){const fields=e.target.form.querySelector('[data-iv-company-fields]');if(fields)fields.hidden=e.target.value!=='company';}
    if(e.target.matches('[data-iv-order]') && e.target.value){const url=new URL(e.target.dataset.previewUrl,location.href);url.searchParams.set('order',e.target.value);if(dialog?.open)open(url.href);else location.href=url.href;}
  });
  root.addEventListener('input',e=>{if(e.target.matches('input[type="search"]')){const clear=e.target.closest('.iv-search')?.querySelector('[data-iv-clear]');if(clear)clear.hidden=!e.target.value;}});
  root.addEventListener('click',async e=>{
    const addItem=e.target.closest('[data-iv-add-item]');if(addItem){const form=addItem.closest('form'),index=Number(addItem.dataset.nextIndex||0);const markup=form.querySelector('[data-iv-item-template]').innerHTML.replaceAll('INDEX',String(index));form.querySelector('[data-iv-edit-items]').insertAdjacentHTML('beforeend',markup);addItem.dataset.nextIndex=String(index+1);form.querySelector('[data-iv-edit-items]').lastElementChild.querySelector('input').focus();return;}
    const removeItem=e.target.closest('[data-iv-remove-item]');if(removeItem){removeItem.closest('.iv-edit-item').remove();return;}
    const trigger=e.target.closest('[data-iv-modal]');if(trigger){e.preventDefault();open(trigger.href,trigger);return;}
    const closeButton=e.target.closest('[data-iv-close]');if(closeButton && dialog?.open){e.preventDefault();close();return;}
    const copyButton=e.target.closest('[data-iv-copy]');if(copyButton){copy(copyButton.dataset.ivCopy);return;}
    if(e.target.closest('[data-iv-copy-link]')){copy(root.querySelector('[data-iv-public-link]').value);return;}
    const clear=e.target.closest('[data-iv-clear]');if(clear){const input=clear.closest('.iv-search').querySelector('input');input.value='';clear.hidden=true;input.focus();return;}
    const image=e.target.closest('[data-iv-image]');if(image){const lightbox=root.querySelector('[data-iv-lightbox]');lightbox.querySelector('img').src=image.dataset.ivImage;lightbox.querySelector('img').alt=image.dataset.title;lightbox.querySelector('span').textContent=image.dataset.title;lightbox.showModal();return;}
    if(e.target.closest('[data-iv-lightbox-close]')){root.querySelector('[data-iv-lightbox]').close();return;}
    const download=e.target.closest('[data-iv-download]');if(download){e.preventDefault();if(busy)return;busy=true;download.setAttribute('aria-busy','true');startLoader();
      try{const response=await fetch(download.href,{credentials:'same-origin',headers:{'Accept':'application/json, application/pdf, application/zip, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}});
        if(!response.ok||response.headers.get('Content-Type')?.includes('json')){let payload={};try{payload=await response.json();}catch{}throw new Error(payload.message||'Documentul nu a putut fi pregătit. Încearcă din nou.');}
        if(!response.headers.get('Content-Disposition'))throw new Error('Sesiunea a expirat. Reîncarcă pagina.');
        const blob=await response.blob(),url=URL.createObjectURL(blob),anchor=document.createElement('a');anchor.href=url;anchor.download=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||'document';anchor.hidden=true;document.body.append(anchor);anchor.click();setTimeout(()=>{URL.revokeObjectURL(url);anchor.remove();},60000);
      }catch(err){toast(err.message);}finally{endLoader();busy=false;download.removeAttribute('aria-busy');}return;
    }
  });
  root.addEventListener('submit',async e=>{
    const form=e.target.closest('[data-iv-form]');if(!form)return;e.preventDefault();if(busy)return;if(!form.reportValidity())return;
    if(form.method.toLowerCase()==='get'){const url=new URL(form.action,location.href);new FormData(form).forEach((value,key)=>url.searchParams.set(key,value));if(dialog?.open)open(url.href);else location.assign(url.href);return;}
    busy=true;const submit=form.querySelector('button[type="submit"],.iv-form-footer>button');if(submit)submit.disabled=true;startLoader();
    try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'Accept':'application/json'}});let result;try{result=await response.json();}catch{throw new Error('Sesiunea a expirat sau serverul nu răspunde. Datele tale sunt păstrate în formular.');}
      if(!response.ok||!result.ok)throw new Error(result.message||'Verifică datele introduse.');
      baseline=signature(form);if(result.link!==undefined){const box=form.querySelector('[data-iv-link-result]');if(result.link){box.hidden=false;box.querySelector('input').value=result.link;}else if(box)box.hidden=true;toast(result.message);}
      else{sessionStorage.setItem('iv-feedback',result.message);location.assign(result.redirect);}
    }catch(err){error(form,err.message);}finally{endLoader();busy=false;if(submit)submit.disabled=false;}
  });
  root.querySelector('[data-iv-lightbox]')?.addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.close();});
  init(root);const feedback=sessionStorage.getItem('iv-feedback');if(feedback){sessionStorage.removeItem('iv-feedback');toast(feedback);}
})();
