(() => {
  'use strict';
  const dialog=document.querySelector('[data-customer-return-dialog]');
  let opener=null,busy=false,controller=null;
  const update=form=>{const quantity=[...form.querySelectorAll('[data-customer-return-quantity]')].reduce((sum,input)=>sum+Number(input.value||0),0);const summary=form.querySelector('[data-customer-return-count]');if(summary)summary.textContent=quantity?`${quantity} ${quantity===1?'bucată selectată':'bucăți selectate'}`:'Alege produsele pentru retur';form.querySelector('[data-customer-return-quantity]')?.setCustomValidity(quantity?'':'Selectează cel puțin un produs.');};
  const initialize=root=>{root.querySelectorAll('[data-customer-return-form]').forEach(form=>{update(form);form.addEventListener('change',()=>update(form));});};
  initialize(document);
  const close=()=>{if(busy)return;controller?.abort();if(dialog.querySelector('[data-return-success]')){location.assign(dialog.dataset.trackingUrl);return;}dialog.close();opener?.focus();};
  dialog?.addEventListener('cancel',event=>{event.preventDefault();close();});
  document.addEventListener('click',async event=>{
    const trigger=event.target.closest('[data-customer-return-open]');
    if(trigger&&dialog){event.preventDefault();if(busy)return;opener=trigger;controller?.abort();controller=new AbortController();dialog.innerHTML='<div class="customer-return-loading" role="status">Pregătim solicitarea de retur…</div>';if(!dialog.open)dialog.showModal();try{const response=await fetch(trigger.href,{headers:{'X-Return-Modal':'1'},signal:controller.signal,credentials:'same-origin'});const html=await response.text();if(!html.includes('data-customer-return')){location.assign(response.url);return;}dialog.innerHTML=html;initialize(dialog);dialog.querySelector('select,a')?.focus();}catch(error){if(error.name!=='AbortError')location.assign(trigger.href);}return;}
    if(event.target.closest('[data-return-close]')&&dialog?.open){event.preventDefault();close();}
  });
  document.addEventListener('submit',async event=>{
    const form=event.target.closest('[data-customer-return-form]');if(!form||!dialog?.open)return;
    event.preventDefault();if(busy)return;busy=true;const button=form.querySelector('[type=submit]');button.disabled=true;button.textContent='Se trimite…';
    try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{'X-Return-Modal':'1'},credentials:'same-origin'});const html=await response.text();if(!html.includes('data-customer-return'))throw new Error('Sesiunea a expirat. Reîncarcă pagina și verifică din nou comanda.');dialog.innerHTML=html;initialize(dialog);dialog.querySelector('[role=alert],.customer-return__success a,select')?.focus();}
    catch(error){const box=form.querySelector('[data-return-error]');box.textContent=error.message||'Nu am putut trimite cererea. Încearcă din nou.';box.hidden=false;button.disabled=false;button.textContent='Trimite solicitarea →';}
    finally{busy=false;}
  });
})();
