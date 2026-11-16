(() => {
  'use strict';
  const root = document.querySelector('[data-iv-page]'); if (!root) return;
  const dialog=root.querySelector('[data-iv-dialog]'), content=dialog?.querySelector('[data-iv-dialog-content]');
  const discard=root.querySelector('[data-iv-discard]'), loader=root.querySelector('[data-iv-loader]');
  let opener=null, baseline='', busy=false, timer=null, toastTimer=null, loadingController=null;
  const signature=form=>JSON.stringify([...new FormData(form).entries()].filter(([key])=>!['_csrf','request_key','version'].includes(key)));
  const dirty=()=>{const form=content?.querySelector('form:not([data-iv-export])');return form && signature(form)!==baseline;};
  const toast=text=>{const box=root.querySelector('[data-iv-toast]');if(!box)return;box.querySelector('span').textContent=text;box.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>box.hidden=true,1700);};
  const error=(form,text)=>{const box=form?.querySelector('[data-iv-errors]');if(box){box.textContent=text;box.hidden=false;box.focus();}else toast(text);};
  const startLoader=()=>{clearTimeout(timer);timer=setTimeout(()=>{if(loader && !loader.open)loader.showModal();},300);};
  const endLoader=()=>{clearTimeout(timer);if(loader?.open)loader.close();};
  const copy=async text=>{try{await navigator.clipboard.writeText(text);toast('Copiat cu succes');}catch{toast('Copierea nu este disponibilă. Selectează textul pentru copiere.');}};
  const downloadFile=async(url,trigger,form=null)=>{
    if(window.AdminExports){if(busy)return false;busy=true;try{return await window.AdminExports.download(url,trigger);}finally{busy=false;}}
    if(busy)return false;busy=true;trigger.setAttribute('aria-busy','true');if(trigger.tagName==='BUTTON')trigger.disabled=true;startLoader();
    try{
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json, application/pdf, application/zip, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}});
      if(!response.ok||response.headers.get('Content-Type')?.includes('json')){let payload={};try{payload=await response.json();}catch{}throw new Error(payload.message||'Documentul nu a putut fi pregătit. Încearcă din nou.');}
      if(!response.headers.get('Content-Disposition'))throw new Error('Sesiunea a expirat. Reîncarcă pagina.');
      const blob=await response.blob(),objectUrl=URL.createObjectURL(blob),anchor=document.createElement('a');anchor.href=objectUrl;anchor.download=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||'document';anchor.hidden=true;document.body.append(anchor);anchor.click();
      setTimeout(()=>{URL.revokeObjectURL(objectUrl);anchor.remove();},60000);return true;
    }catch(err){if(form)error(form,err.message);else toast(err.message);return false;}
    finally{endLoader();busy=false;trigger.removeAttribute('aria-busy');if(trigger.tagName==='BUTTON')trigger.disabled=false;}
  };
  const exportRequests=new WeakMap(),exportTimers=new WeakMap();
  const exportUrl=(form,base)=>{const url=new URL(base,location.href);new FormData(form).forEach((value,key)=>url.searchParams.append(key,value));return url.href;};
  const updateExport=async form=>{
    exportRequests.get(form)?.abort();const controller=new AbortController();exportRequests.set(form,controller);
    const period=form.querySelector('[name="period"]:checked'),format=form.querySelector('[name="format"]:checked').value,custom=period.value==='custom';
    form.querySelector('[data-iv-export-custom]').hidden=!custom;
    form.querySelectorAll('[type="date"]').forEach(input=>input.required=custom);
    const from=custom?form.querySelector('[name="from"]').value:period.dataset.from,to=custom?form.querySelector('[name="to"]').value:period.dataset.to;
    const formatDate=value=>value?value.split('-').reverse().join('.'):'';
    form.querySelector('[data-iv-export-title]').textContent=(format==='zip'?'Centralizator + toate facturile':'Centralizator Excel')+' · '+period.dataset.label.toLocaleLowerCase('ro-RO');
    const dates=form.querySelector('[data-iv-export-dates]'),count=form.querySelector('[data-iv-export-count]'),submit=form.querySelector('button[type="submit"]');
    dates.textContent=period.value==='all'?'Toată perioada':from&&to?formatDate(from)+' — '+formatDate(to):'Completează intervalul dorit.';
    form.querySelector('[data-iv-export-success]').hidden=true;form.querySelector('[data-iv-errors]').hidden=true;submit.disabled=true;
    if(custom&&(!from||!to)){count.textContent='Alege data de început și data de sfârșit.';return;}
    count.textContent='Verificăm documentele din interval…';
    try{
      const response=await fetch(exportUrl(form,form.dataset.previewUrl),{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
      const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Nu am putut verifica documentele.');
      if(controller.signal.aborted||!form.isConnected)return;
      dates.textContent=result.selection.from?formatDate(result.selection.from)+' — '+formatDate(result.selection.to):'Toată perioada';
      count.textContent=result.count?result.count+' '+(result.count===1?'document':'documente')+' · Toate rezultatele, indiferent de paginare.':'Nicio factură în perioada și filtrele alese.';
      form.querySelector('.iv-export-summary').classList.toggle('is-empty',result.count===0);submit.disabled=result.count===0;
    }catch(err){if(err.name!=='AbortError'&&form.isConnected){count.textContent='Modifică selecția pentru a verifica din nou.';error(form,err.message||'Verificarea nu a reușit.');}}
  };
  const disclosures=new WeakMap();
  const initialValues=new WeakMap();
  const saveDialog=root.querySelector('[data-iv-save-dialog]');
  const confirmEdit=form=>new Promise(resolve=>{
    if(!saveDialog){resolve(true);return;}
    if(saveDialog.open){resolve(false);return;}
    const before=initialValues.get(form)||new Map(),after=new Map(new FormData(form)),groups=new Set();
    new Set([...before.keys(),...after.keys()]).forEach(key=>{
      if(before.get(key)===after.get(key))return;
      if(key.startsWith('items['))groups.add('Produsele, cantitățile sau prețurile');
      else if(key.startsWith('billing['))groups.add('Datele clientului și facturarea');
      else if(key.startsWith('shipping'))groups.add('Adresa sau costul livrării');
    });
    const list=saveDialog.querySelector('[data-iv-save-changes]');list.replaceChildren();
    (groups.size?[...groups]:['Confirmarea datelor comenzii']).forEach(text=>{const li=document.createElement('li');li.textContent=text;list.append(li);});
    const checkbox=saveDialog.querySelector('[data-iv-save-notify]'),field=form.querySelector('[name="notify_customer"]');
    checkbox.checked=field.checked;
    saveDialog.querySelector('[data-iv-save-recipient]').textContent='Către '+(form.querySelector('[name="billing[email]"]')?.value||'adresa salvată în comandă');
    const controller=new AbortController(),options={signal:controller.signal};
    const finish=accepted=>{if(accepted)field.checked=checkbox.checked;controller.abort();saveDialog.close();form.querySelector('button[type="submit"]')?.focus();resolve(accepted);};
    saveDialog.querySelectorAll('[data-iv-save-cancel]').forEach(button=>button.addEventListener('click',()=>finish(false),options));
    saveDialog.querySelector('[data-iv-save-accept]').addEventListener('click',()=>finish(true),options);
    saveDialog.addEventListener('cancel',event=>{event.preventDefault();finish(false);},options);
    saveDialog.showModal();saveDialog.querySelector('[data-iv-save-accept]').focus();
  });
  const toggleDisclosure=details=>{
    const current=disclosures.get(details),expand=current?!current.expand:!details.open;
    const start=details.getBoundingClientRect().height;
    current?.animation.cancel();
    disclosures.delete(details);
    details.dataset.expanded=String(expand);
    if(!details.animate||matchMedia('(prefers-reduced-motion: reduce)').matches){details.open=expand;return;}
    details.open=true;
    const style=getComputedStyle(details),borders=parseFloat(style.borderTopWidth)+parseFloat(style.borderBottomWidth);
    const end=expand?details.getBoundingClientRect().height:details.querySelector('summary').getBoundingClientRect().height+borders;
    const animation=details.animate({height:[`${start}px`,`${end}px`]}, {duration:expand?380:300,easing:'cubic-bezier(.22,1,.36,1)'});
    disclosures.set(details,{animation,expand});
    animation.onfinish=()=>{
      if(disclosures.get(details)?.animation!==animation)return;
      details.open=expand;
      disclosures.delete(details);
    };
  };
  const shareResult=(form,result)=>{
    form.querySelector('[name="public_token"]').value=result.public_token;
    form.querySelector('[name="link_action"]').value='email';
    form.querySelector('[name="request_key"]').value=result.next_request_key;
    form.querySelector('[data-iv-public-link]').value=result.link;
    form.querySelector('[data-iv-share-ready]').hidden=false;
    const whatsapp=form.querySelector('[data-iv-whatsapp]');
    if(result.whatsapp_url){whatsapp.href=result.whatsapp_url;whatsapp.removeAttribute('aria-disabled');}else{whatsapp.removeAttribute('href');whatsapp.setAttribute('aria-disabled','true');}
    form.querySelector('[data-iv-share-phone-note]').hidden=!!result.whatsapp_url;
    const status=form.querySelector('[data-iv-share-status]');status.textContent=result.message;status.classList.toggle('is-success',!!result.link_email_sent);
    form.querySelector('[data-iv-share-submit] span').textContent=result.link_email_sent?'Trimite din nou pe email':'Trimite pe email';
    form.querySelector('[data-iv-errors]').hidden=true;
    baseline=signature(form);
  };
  const prepareShare=async form=>{
    if(form.querySelector('[name="public_token"]').value)return;
    const submit=form.querySelector('[data-iv-share-submit]');submit.disabled=true;busy=true;
    form.querySelector('[data-iv-share-status]').textContent='Pregătim linkul privat…';
    try{const body=new FormData(form);body.set('link_action','prepare');const response=await fetch(form.action,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Linkul nu a putut fi pregătit.');shareResult(form,result);}
    catch(err){form.querySelector('[data-iv-share-status]').textContent='Linkul nu este încă pregătit. Poți încerca din nou.';error(form,err.message||'Reîncearcă pregătirea linkului.');}
    finally{submit.disabled=false;busy=false;}
  };
  const init=scope=>{
    scope.querySelectorAll('[data-iv-export]').forEach(form=>updateExport(form));
    scope.querySelectorAll('.iv-page-size-apply').forEach(button=>button.hidden=true);
    scope.querySelectorAll('[data-iv-received-now]').forEach(button=>{
      button.hidden=false;
      button.addEventListener('click',()=>{
        const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Bucharest',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date()).map(part=>[part.type,part.value]));
        const input=button.form.querySelector('[name="received_at"]');
        input.value=`${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
        input.dispatchEvent(new Event('change',{bubbles:true}));input.focus();
      });
    });
    scope.querySelectorAll('[data-iv-form]').forEach(form=>{initialValues.set(form,new Map(new FormData(form)));if(form.dataset.kind==='order-edit')form.querySelector('[name="notify_customer"]')?.closest('.iv-edit-notify').setAttribute('hidden','');});
    const balance=scope.querySelector('[data-iv-payment-amount]');
    if(balance){const refund=scope.querySelector('[data-iv-refund-fields]'),amount=refund?.querySelector('[name="refund_amount"]');let partialAmount=amount?.value||'0.00',lastState='';const update=()=>{const state=scope.querySelector('[data-iv-payment-state]:checked')?.value;balance.hidden=state!=='partial';if(refund){const isRefund=['refunded','partially_refunded'].includes(state);refund.hidden=!isRefund;amount.required=isRefund;amount.readOnly=state==='refunded';if(state==='refunded'){if(lastState!=='refunded')partialAmount=amount.value;amount.value=refund.dataset.received;}else if(lastState==='refunded'){amount.value=partialAmount;}lastState=state;}};scope.querySelectorAll('[data-iv-payment-state]').forEach(radio=>radio.addEventListener('change',update));update();}
    scope.querySelectorAll('[data-iv-select]').forEach(toggle=>{const row=toggle.closest('.iv-select-line'),quantity=row.querySelector('[data-iv-quantity]');quantity.disabled=!toggle.checked;});
    const type=scope.querySelector('[data-iv-customer-type]'),fields=scope.querySelector('[data-iv-company-fields]');if(type && fields)fields.hidden=type.value!=='company';
    if(matchMedia('(max-width: 900px)').matches)scope.querySelectorAll('[data-iv-mobile-fold]').forEach(panel=>panel.open=false);
    scope.querySelectorAll('[data-iv-share]').forEach(box=>prepareShare(box.closest('form')));
  };
  const picker=root.querySelector('[data-iv-picker]'), pickerQuery=picker?.querySelector('[data-iv-picker-query]');
  const pickerResults=picker?.querySelector('[data-iv-picker-results]'), pickerStatus=picker?.querySelector('[data-iv-picker-status]');
  let pickerSource=null, pickerRow=null, pickerController=null, pickerTimer=null, pickerPage=1;
  const money=value=>new Intl.NumberFormat('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100)+' Lei';
  const closePicker=()=>{clearTimeout(pickerTimer);pickerController?.abort();picker.close();pickerSource?.focus();};
  const chooseProduct=product=>{
    const form=pickerSource.closest('form'),add=form.querySelector('[data-iv-add-item]');
    let row=pickerRow;
    if(!row){
      const index=Number(add.dataset.nextIndex||0),fragment=form.querySelector('[data-iv-item-template]').content.cloneNode(true);
      fragment.querySelectorAll('[name]').forEach(el=>el.name=el.name.replaceAll('INDEX',String(index)));
      row=fragment.firstElementChild;form.querySelector('[data-iv-edit-items]').append(fragment);add.dataset.nextIndex=String(index+1);
    }
    row.dataset.ivChosenSku=product.sku;row.querySelector('[data-iv-sku]').value=product.sku;
    row.querySelector('[data-iv-product-name]').textContent=product.name;
    row.querySelector('[data-iv-product-meta]').textContent=[product.option_summary,product.sku,money(product.price_bani)+' / buc.'].filter(Boolean).join(' · ');
    row.querySelector('[data-iv-unit-price]').value=(Number(product.price_bani)/100).toFixed(2);
    row.querySelector('[data-iv-unit-price]').readOnly=false;
    const photo=row.querySelector('[data-iv-preview-photo]'),img=photo.querySelector('img'),icon=photo.querySelector('svg');
    img.hidden=true;img.removeAttribute('src');icon.removeAttribute('hidden');
    if(product.image_url){img.alt=product.name;img.onload=()=>{img.hidden=false;icon.setAttribute('hidden','');};img.onerror=()=>{img.hidden=true;icon.removeAttribute('hidden');};img.src=product.image_url;}
    row.classList.add('is-resolved');closePicker();row.querySelector('input[type="number"]').focus();
  };
  const searchProducts=async(append=false)=>{
    pickerController?.abort();const controller=new AbortController();pickerController=controller;
    const query=pickerQuery.value.trim(),more=picker.querySelector('[data-iv-picker-more]');
    if(!append){pickerPage=1;pickerResults.replaceChildren();picker.querySelector('.iv-picker-scroll').scrollTop=0;}
    pickerResults.setAttribute('aria-busy','true');pickerStatus.textContent='Căutăm în catalog…';more.hidden=true;
    try{
      const url=new URL(pickerSource.closest('[data-iv-product-url]').dataset.ivProductUrl,location.href);url.searchParams.set('q',query);url.searchParams.set('page',String(pickerPage));
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
      if(!response.ok)throw new Error();const result=await response.json();if(controller.signal.aborted||!picker.open)return;
      const chosen=new Set([...pickerSource.closest('form').querySelectorAll('[data-iv-chosen-sku]')].filter(row=>row!==pickerRow).map(row=>row.dataset.ivChosenSku));
      result.products.forEach(product=>{
        const option=picker.querySelector('[data-iv-choice-template]').content.firstElementChild.cloneNode(true);
        option.querySelector('strong').textContent=product.name;option.querySelector('.iv-choice-variant').textContent=product.option_summary||'Mărime standard';
        option.querySelector('small').textContent=product.sku;option.querySelector('.iv-choice-price').textContent=money(product.price_bani);
        const img=option.querySelector('img'),icon=option.querySelector('.iv-choice-photo svg');
        if(product.image_url){img.onload=()=>{img.hidden=false;icon.setAttribute('hidden','');};img.onerror=()=>{img.hidden=true;icon.removeAttribute('hidden');};img.src=product.image_url;}
        if(chosen.has(product.sku)){option.disabled=true;option.querySelector('.iv-choice-price').textContent='Deja în comandă';option.querySelector('.iv-choice-add').textContent='✓';}
        option.addEventListener('click',()=>chooseProduct(product));pickerResults.append(option);
      });
      const count=pickerResults.children.length;pickerStatus.textContent=count?`${count} variante afișate${result.more?' · Mai sunt produse în catalog':''}. Alege varianta dorită.`:'Niciun produs găsit. Încearcă un alt nume sau SKU.';
      more.hidden=!result.more;
    }catch(err){if(err.name!=='AbortError')pickerStatus.textContent='Catalogul nu poate fi încărcat. Modifică căutarea pentru a reîncerca.';}
    finally{if(pickerController===controller)pickerResults.removeAttribute('aria-busy');}
  };
  const openPicker=(button,row=null)=>{pickerSource=button;pickerRow=row;pickerQuery.value='';picker.querySelector('[data-iv-picker-clear]').hidden=true;picker.showModal();pickerQuery.focus();searchProducts();};
  picker?.addEventListener('cancel',e=>{e.preventDefault();closePicker();});
  picker?.querySelectorAll('[data-iv-picker-close]').forEach(button=>button.addEventListener('click',closePicker));
  picker?.querySelector('[data-iv-picker-clear]').addEventListener('click',()=>{pickerQuery.value='';picker.querySelector('[data-iv-picker-clear]').hidden=true;pickerQuery.focus();clearTimeout(pickerTimer);searchProducts();});
  picker?.querySelector('[data-iv-picker-more]').addEventListener('click',()=>{pickerPage++;searchProducts(true);});
  pickerQuery?.addEventListener('input',()=>{pickerController?.abort();clearTimeout(pickerTimer);picker.querySelector('[data-iv-picker-clear]').hidden=!pickerQuery.value;pickerTimer=setTimeout(()=>searchProducts(),220);});
  pickerQuery?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();clearTimeout(pickerTimer);searchProducts();}if(e.key==='ArrowDown'){e.preventDefault();pickerResults.querySelector('button:not(:disabled)')?.focus();}});
  const open=async(url,trigger)=>{
    if(busy)return;
    loadingController?.abort();loadingController=new AbortController();opener=trigger||opener;startLoader();
    try{
      const response=await fetch(url,{headers:{'X-Invoice-Modal':'1'},credentials:'same-origin',signal:loadingController.signal});
      if(response.redirected){location.assign(response.url);return;}
      if(!response.ok)throw new Error('Formularul nu poate fi deschis. Reîncarcă pagina și încearcă din nou.');
      const html=await response.text();const fragment=new DOMParser().parseFromString(html,'text/html'),form=fragment.querySelector('[data-iv-form]');
      if(!form)throw new Error('Sesiunea a expirat. Reîncarcă pagina pentru autentificare.');
      content.replaceChildren(document.importNode(form,true));dialog.dataset.kind=form.dataset.kind;dialog.setAttribute('aria-label',form.querySelector('h2')?.textContent||'Administrare factură');init(content);baseline=signature(content.querySelector('form'));
      endLoader();if(!dialog.open)dialog.showModal();dialog.querySelector('[data-iv-close]')?.focus();
      if(form.querySelector('[name="edit_focus"]')?.value==='items'){const heading=dialog.querySelector('[data-iv-items-heading]');heading?.scrollIntoView({block:'start'});heading?.focus({preventScroll:true});}
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
    const exportForm=e.target.closest('[data-iv-export]');if(exportForm){clearTimeout(exportTimers.get(exportForm));updateExport(exportForm);return;}
    if(e.target.matches('[data-iv-page-size]')){e.target.form.requestSubmit();return;}
    const toggle=e.target.closest('[data-iv-select]');if(toggle){const row=toggle.closest('.iv-select-line'),q=row.querySelector('[data-iv-quantity]');q.disabled=!toggle.checked;row.classList.toggle('is-selected',toggle.checked);q.value=toggle.checked?q.dataset.default:'0';if(toggle.checked)q.focus();}
    if(e.target.matches('[data-iv-customer-type]')){const fields=e.target.form.querySelector('[data-iv-company-fields]');if(fields)fields.hidden=e.target.value!=='company';}
    if(e.target.matches('[data-iv-order]') && e.target.value){const url=new URL(e.target.dataset.previewUrl,location.href);url.searchParams.set('order',e.target.value);const warehouse=e.target.form.querySelector('[name="warehouse_id"]');if(warehouse?.value)url.searchParams.set('warehouse_id',warehouse.value);if(dialog?.open)open(url.href);else location.href=url.href;}
  });
  root.addEventListener('input',e=>{const exportForm=e.target.closest('[data-iv-export]');if(exportForm&&e.target.type==='date'){exportRequests.get(exportForm)?.abort();clearTimeout(exportTimers.get(exportForm));exportTimers.set(exportForm,setTimeout(()=>updateExport(exportForm),250));return;}if(e.target.matches('input[type="search"]')){const clear=e.target.closest('.iv-search')?.querySelector('[data-iv-clear]');if(clear)clear.hidden=!e.target.value;}});
  root.addEventListener('click',async e=>{
    const disclosure=e.target.closest('[data-iv-disclosure]>summary');if(disclosure){e.preventDefault();toggleDisclosure(disclosure.parentElement);return;}
    const addItem=e.target.closest('[data-iv-add-item]');if(addItem){openPicker(addItem);return;}
    const changeItem=e.target.closest('[data-iv-change-product]');if(changeItem){openPicker(changeItem,changeItem.closest('.iv-edit-item'));return;}
    const removeItem=e.target.closest('[data-iv-remove-item]');if(removeItem){const form=removeItem.closest('form');if(form.querySelectorAll('.iv-edit-item').length===1){error(form,'Păstrează cel puțin un produs. Poți înlocui această poziție cu alt produs.');return;}removeItem.closest('.iv-edit-item').remove();form.querySelector('[data-iv-add-item]')?.focus();return;}
    const trigger=e.target.closest('[data-iv-modal]');if(trigger){if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();open(trigger.href,trigger);return;}
    const closeButton=e.target.closest('[data-iv-close]');if(closeButton && dialog?.open){e.preventDefault();close();return;}
    const copyButton=e.target.closest('[data-iv-copy]');if(copyButton){copy(copyButton.dataset.ivCopy);return;}
    if(e.target.closest('[data-iv-copy-link]')){copy(e.target.closest('form').querySelector('[data-iv-public-link]').value);return;}
    if(e.target.closest('[data-iv-whatsapp][aria-disabled="true"]')){e.preventDefault();return;}
    const clear=e.target.closest('[data-iv-clear]');if(clear){const input=clear.closest('.iv-search').querySelector('input');input.value='';clear.hidden=true;input.focus();return;}
    const image=e.target.closest('[data-iv-image]');if(image){const lightbox=root.querySelector('[data-iv-lightbox]');lightbox.querySelector('img').src=image.dataset.ivImage;lightbox.querySelector('img').alt=image.dataset.title;lightbox.querySelector('span').textContent=image.dataset.title;lightbox.showModal();return;}
    if(e.target.closest('[data-iv-lightbox-close]')){root.querySelector('[data-iv-lightbox]').close();return;}
    const download=e.target.closest('[data-iv-download]');if(download){e.preventDefault();await downloadFile(download.href,download);return;}
  });
  const navigateAfterSave=(redirect)=>{
    const target=new URL(redirect||location.href,location.href);
    if(target.origin===location.origin&&target.pathname===location.pathname&&target.search===location.search){
      history.replaceState(history.state,'',target.href);location.reload();
    }else location.assign(target.href);
  };
  root.addEventListener('submit',async e=>{
    if(e.target.matches('[data-iv-export]')){e.preventDefault();const form=e.target;if(busy||!form.reportValidity())return;const success=await downloadFile(exportUrl(form,form.action),form.querySelector('button[type="submit"]'),form);if(success){const message=form.querySelector('[data-iv-export-success]');message.textContent='Fișierul este pregătit. Descărcarea a pornit.';message.hidden=false;}return;}
    if(e.target.matches('[data-dispatch-issue]')){
      e.preventDefault();if(busy)return;busy=true;const form=e.target,button=form.querySelector('button');button.disabled=true;startLoader();
      try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Avizul nu a putut fi generat.');sessionStorage.setItem('iv-feedback',result.message);navigateAfterSave(result.redirect);}
      catch(err){toast(err.message||'Avizul nu a putut fi generat. Reîncarcă pagina și încearcă din nou.');}
      finally{busy=false;button.disabled=false;endLoader();}return;
    }
    const form=e.target.closest('[data-iv-form]');if(!form)return;e.preventDefault();if(busy)return;if(!form.reportValidity())return;
    if(form.method.toLowerCase()==='get'){const url=new URL(form.action,location.href);new FormData(form).forEach((value,key)=>url.searchParams.set(key,value));if(dialog?.open)open(url.href);else location.assign(url.href);return;}
    if(form.dataset.kind==='order-edit' && !await confirmEdit(form))return;
    busy=true;const submit=form.querySelector('button[type="submit"],.iv-form-footer>button');if(submit)submit.disabled=true;startLoader();
    try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'Accept':'application/json'}});let result;try{result=await response.json();}catch{throw new Error('Sesiunea a expirat sau serverul nu răspunde. Datele tale sunt păstrate în formular.');}
      if(!response.ok||!result.ok)throw new Error(result.message||'Verifică datele introduse.');
      baseline=signature(form);if(result.link!==undefined){shareResult(form,result);}
      else{sessionStorage.setItem('iv-feedback',result.message);navigateAfterSave(result.redirect);}
    }catch(err){error(form,err.message);}finally{endLoader();busy=false;if(submit)submit.disabled=false;}
  });
  root.querySelector('[data-iv-lightbox]')?.addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.close();});
  window.addEventListener('beforeunload',event=>{if(dirty()&&!busy){event.preventDefault();event.returnValue='';}});
  init(root);const feedback=sessionStorage.getItem('iv-feedback');if(feedback){sessionStorage.removeItem('iv-feedback');toast(feedback);}
})();
