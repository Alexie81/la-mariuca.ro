(() => {
  'use strict';
  document.documentElement.classList.add('nir-enhanced');
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(String(value || '0').replace(/\s/g, '').replace(',', '.')) || 0;
  const compactDecimal = value => /^-?\d+[.,]\d+$/.test(String(value ?? '')) ? String(value).replace(/0+$/, '').replace(/[.,]$/, '') : value;
  const decimal = value => Number(value).toLocaleString('ro-RO', {maximumFractionDigits: 4});
  const currency = (value, code) => Number(value).toLocaleString('ro-RO', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + code;
  let openingTrigger = null;
  let pickerLine = null;
  let pickerAbort = null;
  let searchTimer;
  let modalAbort;
  let toastTimer;
  let locationOwner = null;
  let locationOpener = null;
  let stockHelpOpener = null;
  const selectedFiles = new WeakMap();
  const matchRequests = new WeakMap();
  const rateRequests = new WeakMap();
  const icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-5 9 5v10l-9 5-9-5V7Zm0 0 9 5 9-5M12 12v10"/></svg>';

  function message(form, text, errors = {}) {
    const box = $('[data-nir-errors]', form);
    if (!box) return;
    box.replaceChildren();
    const p = document.createElement('p'); p.textContent = text; box.append(p); box.hidden = false;
    const displayed = new Set([String(text)]);
    let revealed = false;
    $$('[aria-invalid="true"]', form).forEach(field => field.removeAttribute('aria-invalid'));
    $$('[data-field-error]', form).forEach(field => { field.textContent = ''; field.hidden = true; });
    Object.entries(errors).forEach(([key, value]) => {
      const name = key.replace(/^lines\.(\d+)\.(.+)$/, 'lines[$1][$2]').replace(/^quantities\.(\d+)$/, 'quantities[$1]').replace(/^attachments$/, 'attachments[]');
      const field = Array.from(form.elements).find(element => element.name === name);
      if (field) { if (!revealed) { window.NirFlow?.reveal(field); revealed = true; } field.setAttribute('aria-invalid','true'); const details = field.closest('details'); if (details) details.open = true; }
      const error = $$('[data-field-error]', form).find(element => element.dataset.fieldError === name);
      if (error) { error.textContent = String(value); error.hidden = false; }
      else if(!displayed.has(String(value))) { const line = document.createElement('p'); line.textContent = String(value); box.append(line); displayed.add(String(value)); }
    });
    box.scrollIntoView({behavior:'smooth',block:'start'}); box.focus({preventScroll:true});
  }

  async function json(response) {
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) throw new Error(response.status === 401 || response.status === 403 ? 'Sesiunea a expirat sau nu ai acces. Reîncarcă pagina și autentifică-te.' : 'Serverul nu a putut procesa cererea. Încearcă din nou.');
    const data = await response.json();
    if (!response.ok || data.success === false) {
      const error = new Error(data.message || 'Verifică datele și încearcă din nou.');
      error.data = data; throw error;
    }
    return data;
  }

  async function openModal(trigger) {
    const dialog = $('[data-nir-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') { location.assign(trigger.href); return; }
    openingTrigger = trigger;
    modalAbort?.abort(); modalAbort = new AbortController();
    const body = $('[data-nir-dialog-content]', dialog);
    body.innerHTML = '<div class="nir-loading" role="status">Pregătim formularul…</div>';
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('has-nir-dialog');
    try {
      const response = await fetch(trigger.dataset.modalUrl || trigger.href, {headers:{'X-Requested-With':'XMLHttpRequest'},signal:modalAbort.signal});
      if (!response.ok) throw new Error('Formularul nu poate fi deschis acum.');
      const text = await response.text();
      if (text.trimStart().startsWith('{')) { const result = JSON.parse(text); throw new Error(result.message || 'Nu ai acces la această acțiune.'); }
      body.innerHTML = text;
      initialize(body);
      if(trigger.dataset.editorStep) window.NirFlow?.show($('[data-nir-editor]',body),trigger.dataset.editorStep);
      $$('input:not([type="hidden"]),select,textarea,button', body).find(control=>control.getClientRects().length>0)?.focus({preventScroll:true});
    } catch(error) {
      if(error.name === 'AbortError') return;
      body.innerHTML = '<div class="nir-form__header"><h2>Nu am putut deschide formularul</h2><button type="button" class="nir-close" data-nir-modal-close aria-label="Închide">×</button></div><div class="nir-form__body"><p>' + escape(error.message) + '</p><a class="nir-button nir-button--soft" href="'+escape(trigger.href)+'">Deschide pagina completă</a></div>';
    }
  }

  function closeModal(discard = false) {
    const dialog = $('[data-nir-dialog]');
    if (!dialog?.open) return;
    if ($('[data-nir-form][aria-busy="true"]', dialog)) return;
    const form = $('[data-nir-form]', dialog);
    if (!discard && window.NirUnsaved && !window.NirUnsaved.requestClose(form, () => closeModal(true))) return;
    modalAbort?.abort(); pickerAbort?.abort(); pickerLine = null;
    const finish = () => { dialog.close(); dialog.classList.remove('is-closing'); document.documentElement.classList.remove('has-nir-dialog'); openingTrigger?.focus({preventScroll:true}); };
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) finish();
    else { dialog.classList.add('is-closing'); setTimeout(finish,180); }
  }

  function toast(text, duration = 2500) {
    const element = $('[data-nir-toast]');
    if (!element) return;
    clearTimeout(toastTimer); $('span',element).textContent = text; element.hidden = false;
    toastTimer = setTimeout(() => { element.hidden = true; },duration);
  }

  async function submitForm(form, submitter) {
    if(form.getAttribute('aria-busy') === 'true') return;
    if(!submitter?.formNoValidate && !form.reportValidity()) return;
    const returns=$$('[data-reverse-select]',form);
    if(returns.length && !returns.some(input=>input.checked)){message(form,'Selectează cel puțin un produs pentru retur.');return;}
    const data = new FormData(form);
    if(submitter?.name) data.set(submitter.name,submitter.value);
    const buttons = $$('button[type="submit"]',form);
    const formBody=$('.nir-form__body',form),bodyWasInert=formBody?.inert;
    if(formBody)formBody.inert=true;
    form.setAttribute('aria-busy','true'); buttons.forEach(button => { button.disabled = true; });
    try {
      const response = window.NirUpload ? await window.NirUpload.post(form,form.action,data) : await fetch(form.action, {method:'POST',body:data,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
      const result = await json(response);
      window.NirUnsaved?.markSaved(form);
      if(result.redirect) { try { sessionStorage.setItem('nir-feedback',result.message || 'Recepția a fost salvată.'); } catch { /* Redirect still works when storage is unavailable. */ } location.assign(result.redirect); }
      else { form.removeAttribute('aria-busy'); closeModal(); toast(result.message || 'Modificările au fost salvate.'); }
    } catch(error) {
      if(formBody)formBody.inert=bodyWasInert;
      if(error.data?.errors?.accept_alternative_stock){const box=$('[data-reverse-alternative]',form);if(box){box.hidden=false;const input=$('input',box);if(input){input.required=true;input.checked=false;}}}
      const warnings = error.data?.warnings || [];
      if(warnings.length) {
        let box = $('[data-nir-warnings]',form);
        if(!box) { box = document.createElement('div'); box.className='nir-warnings'; box.dataset.nirWarnings=''; $('.nir-form__body',form)?.append(box); }
        box.replaceChildren();
        warnings.forEach(warning => { const p=document.createElement('p'); p.textContent= typeof warning === 'string' ? warning : (warning.message || String(warning)); box.append(p); }); box.hidden=false;
        let acknowledgement = $('[data-nir-acknowledge]',form);
        if(!acknowledgement) { acknowledgement=document.createElement('label'); acknowledgement.className='nir-acknowledge'; acknowledgement.dataset.nirAcknowledge=''; acknowledgement.innerHTML='<input type="checkbox" name="accept_warnings" value="1" required><span>Am verificat avertismentele și doresc să continui.</span>'; box.after(acknowledgement); }
        acknowledgement.hidden=false; $('input',acknowledgement).required=true; $('input',acknowledgement).checked=false;
        box.scrollIntoView({behavior:'smooth',block:'center'});
      }
      if(!warnings.length || Object.keys(error.data?.errors || {}).length) message(form,error.message,error.data?.errors || {});
    } finally { form.removeAttribute('aria-busy'); if(formBody)formBody.inert=bodyWasInert; buttons.forEach(button => { button.disabled=false; }); }
  }

  function lineValue(line,key) { return $('[name$="['+key+']"]',line)?.value || ''; }
  function setLineValue(line,key,value) { if(key==='tracked'){const checkbox=$('[data-line-tracked]',line);if(checkbox)checkbox.checked=Boolean(value)&&value!=='0';return;} const field=$('[name$="['+key+']"]',line); if(field) field.value=(field.inputMode==='decimal' ? compactDecimal(value) : value) ?? ''; }
  function linePreview(line) {
    const form=line.closest('form'),mode=lineValue(line,'price_mode') || 'unit';
    const code=form.elements.currency?.value.toUpperCase() || 'RON';
    $$('[data-price-field]',line).forEach(element=>{element.hidden=!element.dataset.priceField.split(' ').includes(mode);});
    const tracked=$('[data-line-tracked]',line);
    const match=$('[data-product-match]',line);if(match)match.style.opacity=tracked&&!tracked.checked?'.6':'1';
    const details=$('.nir-line-details',line);
    if(number(lineValue(line,'invoice_quantity'))!==number(lineValue(line,'received_quantity')) || number(lineValue(line,'received_quantity'))!==number(lineValue(line,'accepted_quantity'))) {if(details)details.open=true;}
    try {
      const fields={};['accepted_quantity','conversion_factor','unit_price','discount_percent','vat_percent','extra_cost_ron','net_override','tax_override','gross_override'].forEach(key=>{fields[key]=lineValue(line,key)||'0';});
      fields.price_mode=mode;fields.tracked=tracked?.checked ?? true;
      const amounts=NirAmounts.line(fields,form.elements.exchange_rate?.value || '1',$('input[type="checkbox"][name="vat_in_cost"]',form)?.checked);
      const stock=$('[data-line-stock]',line);if(stock)stock.textContent=decimal(amounts.stock_quantity)+' '+(lineValue(line,'stock_unit')||'buc');
      const sum=$('[data-line-total]',line);if(sum)sum.textContent=currency(Number(amounts.total_bani)/100,code);
      const cost=$('[data-line-cost]',line);if(cost)cost.textContent=Number(amounts.unit_cost_ron).toLocaleString('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:6})+' RON';
      return amounts;
    } catch {
      ['[data-line-stock]','[data-line-total]','[data-line-cost]'].forEach(selector=>{const node=$(selector,line);if(node)node.textContent='Verifică valorile';});
      return null;
    }
  }

  function preview(form) {
    let net=0n,tax=0n,total=0n,valid=true;
    $$('[data-nir-line]',form).forEach(line=>{const amounts=linePreview(line);if(!amounts){valid=false;return;}net+=BigInt(amounts.net_bani);tax+=BigInt(amounts.tax_bani);total+=BigInt(amounts.total_bani);});
    const code=form.elements.currency?.value.toUpperCase() || 'RON';
    [['net',net],['tax',tax],['total',total]].forEach(([key,value])=>{const node=$('[data-summary-'+key+']',form);if(node)node.textContent=valid?currency(Number(value)/100,code):'Verifică valorile';});
    window.NirFlow?.refresh(form);
  }

  function renumber(form) {
    const lines=$$('[data-nir-line]',form);
    lines.forEach((line,index)=>{line.dataset.index=String(index);$('[data-line-number]',line).textContent=String(index+1);$$('[name]',line).forEach(field=>{field.name=field.name.replace(/^lines\[[^\]]+\]/,'lines['+index+']');});});
    const count=$('[data-line-count]',form);if(count)count.textContent=lines.length+' '+(lines.length===1?'poziție':'poziții');
    preview(form);
  }

  function addLine(form, values={}, batch=false) {
    const lines=$$('[data-nir-line]',form);
    if(lines.length>=1000){message(form,'Un document poate conține maximum 1.000 de poziții.');return null;}
    const template=$('[data-nir-line-template]',form);
    if(!template)return null;
    const fragment=template.content.cloneNode(true); const line=$('[data-nir-line]',fragment);
    Object.entries(values).forEach(([key,value])=>setLineValue(line,key,value));
    const name=$('[data-line-title]',line);if(name)name.textContent=values.supplier_name || 'Produs nou';
    $('[data-nir-lines]',form).append(fragment);if(!batch)renumber(form);return line;
  }

  function currencyChanged(form) {
    const input=form.elements.currency,rate=form.elements.exchange_rate;
    if(!input||!rate)return;
    input.value=input.value.toUpperCase();rate.readOnly=input.value==='RON';
    if(rate.readOnly)rate.value='1';
    const fetchButton=$('[data-nir-fetch-rate]',form);if(fetchButton)fetchButton.hidden=rate.readOnly;
    preview(form);
  }

  function invoiceDateChanged(form) {
    const date=form.elements.invoice_date?.value;if(!date)return;
    ['nir_at','received_at'].forEach(key=>{const field=form.elements[key];if(field&&!field.dataset.manuallyChanged)field.value=date+'T'+(field.value.split('T')[1]||'09:00');});
    const rateDate=form.elements.exchange_rate_date;if(rateDate&&!rateDate.dataset.manuallyChanged)rateDate.value=date;
  }

  function locationsChanged(form) {
    const warehouse=form.elements.warehouse_id?.value;const select=form.elements.location_id;if(!select)return;
    Array.from(select.options).forEach(option=>{ if(option.value){ const mismatch=option.dataset.warehouse && option.dataset.warehouse!==warehouse;option.hidden=Boolean(mismatch);option.disabled=Boolean(mismatch); }});
    if(select.selectedOptions[0]?.disabled)select.value='';
    const available=Array.from(select.options).filter(option=>option.value&&!option.disabled);
    if(!select.value){const preferred=available.find(option=>option.dataset.default==='1') || (available.length===1?available[0]:null);if(preferred)select.value=preferred.value;}
  }

  function fileList(form) {
    const input=$('[data-nir-files]',form),container=$('[data-nir-file-list]',form);if(!input||!container)return;
    $$('[data-local-file]',container).forEach(item=>item.remove());
    Array.from(input.files).forEach((file,index)=>{const item=document.createElement('span');item.dataset.localFile='';item.innerHTML=icon+'<span>'+escape(file.name)+' · '+escape((file.size/1024/1024).toFixed(1))+' MB</span><button type="button" aria-label="Elimină '+escape(file.name)+'" data-remove-file="'+index+'">×</button>';container.append(item);});
  }

  function acceptFiles(form, files) {
    const input=$('[data-nir-files]',form);if(!input)return;
    const transfer=new DataTransfer();(selectedFiles.get(input) || []).forEach(file=>transfer.items.add(file));
    for(const file of files){if(!/\.(pdf|jpe?g|png|webp|xlsx|xml)$/i.test(file.name)){message(form,'Format neacceptat: '+file.name);continue;}if(Array.from(transfer.files).some(existing=>existing===file))continue;if(transfer.files.length>=30){message(form,'Poți încărca cel mult 30 de fișiere odată.');break;}transfer.items.add(file);}
    input.files=transfer.files;selectedFiles.set(input,Array.from(input.files));fileList(form);
  }

  async function loadProducts(form,query='') {
    const results=$('[data-product-results]',form), status=$('[data-product-status]',form);
    if(!results)return;
    pickerAbort?.abort(); const request=new AbortController(); pickerAbort=request;
    const supplier=form.elements.supplier_id?.value || '';
    results.innerHTML='<div class="nir-catalog-empty" role="status">Căutăm în catalog…</div>';
    results.setAttribute('aria-busy','true');
    if(status)status.textContent='Căutare în curs…';
    try {
      const url=new URL(form.dataset.productUrl,location.origin);url.searchParams.set('q',query);url.searchParams.set('supplier_id',supplier);
      const data=await json(await fetch(url,{headers:{Accept:'application/json'},signal:request.signal}));
      if(request.signal.aborted || pickerAbort!==request || supplier!==(form.elements.supplier_id?.value || ''))return;
      const products=data.items || [];
      results.replaceChildren();
      if(status)status.textContent=products.length ? (products[0].search_match==='exact' ? 'Potrivire exactă · ' : '')+products.length+' '+(products.length===1?'variantă disponibilă':'variante disponibile') : 'Niciun rezultat';
      if(!products.length){results.innerHTML='<div class="nir-catalog-empty"><strong>Nu am găsit produsul</strong>Încearcă alt nume sau verifică exact codul introdus.</div>';return;}
      products.forEach(product=>{
        const button=document.createElement('button');button.type='button';button.className='nir-catalog-option';
        const image=product.image_url || '';
        button.innerHTML=(image?'<img src="'+escape(image)+'" alt="" loading="lazy">':'<i>'+icon+'</i>')+
          '<span class="nir-catalog-copy"><strong>'+escape(product.product_name || product.name)+'</strong><small>'+escape(product.option_summary || 'Variantă standard')+'</small><span class="nir-catalog-code">'+escape(product.sku || product.primary_sku || 'Fără SKU')+'</span></span><span class="nir-catalog-arrow" aria-hidden="true">›</span>';
        button.addEventListener('click',()=>selectProduct(form,product));results.append(button);
      });
    }catch(error){if(!request.signal.aborted && pickerAbort===request){results.innerHTML='<div class="nir-catalog-empty" role="alert"><strong>Căutarea nu a reușit</strong>'+escape(error.message)+'</div>';if(status)status.textContent='Încearcă din nou';}}
    finally{if(pickerAbort===request)results.removeAttribute('aria-busy');}
  }

  function openPicker(button) {
    const form=button.closest('form'); pickerLine=button.closest('[data-nir-line]');
    const panel=$('[data-nir-product-panel]',form); panel.hidden=false;
    Array.from(form.children).forEach(child=>{if(child!==panel && !child.inert){child.inert=true;child.dataset.pickerInert='1';}});
    const search=$('[data-product-search]',form);search.value=lineValue(pickerLine,'supplier_name');search.focus();loadProducts(form,search.value);
  }
  function closePicker(form) {
    clearTimeout(searchTimer);pickerAbort?.abort();
    const panel=$('[data-nir-product-panel]',form);if(panel)panel.hidden=true;
    $$('[data-picker-inert]',form).forEach(child=>{child.inert=false;delete child.dataset.pickerInert;});
    $('[data-nir-product-picker]',pickerLine || form)?.focus();pickerLine=null;
  }
  function selectProduct(form,product) {
    const line=pickerLine;if(!line)return;
    applyProduct(line,product);preview(form);closePicker(form);
  }

  function applyProduct(line,product) {
    setLineValue(line,'product_id',product.product_id);setLineValue(line,'variant_id',product.variant_id || product.id);
    $('[data-product-label]',line).textContent=product.product_name || product.name;
    $('[data-product-meta]',line).textContent=[product.option_summary,product.sku,product.category_name].filter(Boolean).join(' · ');
    const image=$('.nir-product-match__image',line);image.replaceChildren();if(product.image_url){const img=document.createElement('img');img.src=product.image_url;img.alt='';image.append(img);}else image.innerHTML=icon;
    if(!lineValue(line,'supplier_name')){setLineValue(line,'supplier_name',product.product_name || product.name);$('[data-line-title]',line).textContent=product.product_name || product.name;}
    $('[data-line-subtitle]',line).textContent=product.sku || product.primary_sku || '';
    $('[data-nir-product-picker] span',line).textContent='Schimbă';
  }

  function applyReference(line,reference,batch=false) {
    if(!reference)return;
    ['purchase_unit','stock_unit','conversion_factor'].forEach(key=>{const field=$('[name$="['+key+']"]',line);if(field&&!field.dataset.manuallyChanged&&reference[key])setLineValue(line,key,reference[key]);});
    const history=$('[data-price-history]',line);if(history&&reference.last_price){history.textContent='Ultimul preț la acest furnizor: '+decimal(reference.last_price)+' '+(reference.last_currency || 'RON');history.hidden=false;}
    if(!batch)preview(line.closest('form'));
  }

  async function matchLine(line) {
    const form=line.closest('form');if(!form.dataset.matchUrl || lineValue(line,'variant_id') || !form.elements.supplier_id.value)return;
    const name=lineValue(line,'supplier_name');if(!name && !lineValue(line,'supplier_code') && !lineValue(line,'supplier_ean'))return;
    const data=new FormData();data.set('_csrf',form.elements._csrf.value);data.set('supplier_id',form.elements.supplier_id.value);
    ['supplier_name','supplier_code','supplier_ean'].forEach(key=>data.set('line['+key+']',lineValue(line,key)));
    const signature=()=>[form.elements.supplier_id.value,...['supplier_name','supplier_code','supplier_ean'].map(key=>lineValue(line,key))].join('\u0000');
    const original=signature();matchRequests.get(line)?.abort();const request=new AbortController();matchRequests.set(line,request);
    try{const result=await json(await fetch(form.dataset.matchUrl,{method:'POST',body:data,signal:request.signal,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}));
      if(lineValue(line,'variant_id') || !line.isConnected || signature()!==original || matchRequests.get(line)!==request)return;
      if(result.matched && !result.ambiguous){
        if(result.product)applyProduct(line,result.product);
        else{setLineValue(line,'product_id',result.product_id);setLineValue(line,'variant_id',result.variant_id);$('[data-product-label]',line).textContent='Asociere exactă găsită';$('[data-product-meta]',line).textContent='Referință verificată pentru acest furnizor';$('[data-nir-product-picker] span',line).textContent='Verifică produsul';}
        applyReference(line,result.reference);
      }else if(result.suggestions?.length){$('[data-product-meta]',line).textContent=result.suggestions.length+' sugestii disponibile. Apasă „Asociază” pentru a alege.';}
    }catch{/* A failed suggestion request never prevents manual selection or saving. */}
  }

  async function createInline(button,kind) {
    const form=button.closest('form'),details=button.closest('details'),error=$('[data-'+kind+'-error]',details);
    const data=new FormData();data.set('_csrf',form.elements._csrf.value);
    const warehouse=form.elements.warehouse_id?.value;
    if(kind==='location')data.set('warehouse_id',warehouse);
    $$('[name^="new_'+kind+'["]',details).forEach(field=>{data.set(field.name.replace('new_'+kind+'[','').replace(/\]$/,''),field.value);});
    button.disabled=true;if(error)error.textContent='';
    try{const result=await json(await fetch(form.dataset[kind+'Url'],{method:'POST',body:data,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}));const item=result[kind] || result.item || result;const select=form.elements[kind+'_id'];const option=document.createElement('option');option.value=item.id || result.id;option.textContent=item.alias || item.name || data.get(kind==='supplier'?'alias':'name');if(kind==='location')option.dataset.warehouse=String(item.warehouse_id || warehouse);select.append(option);if(kind!=='location'||form.elements.warehouse_id.value===warehouse)select.value=option.value;if(kind==='location')locationsChanged(form);details.open=false;$$('input:not([type=hidden]),textarea',details).forEach(input=>input.value='');}
    catch(e){if(error)error.textContent=e.message;else message(form,e.message,e.data?.errors || {});}
    finally{button.disabled=false;}
  }

  function openLocation(trigger) {
    const owner = trigger.closest('[data-nir-form]');
    const warehouse = owner?.elements.warehouse_id;
    const dialog = $('[data-receipt-location-dialog]');
    if (!owner || !dialog || !warehouse?.value) {
      warehouse?.focus(); warehouse?.reportValidity(); return;
    }
    locationOwner = owner; locationOpener = trigger;
    const form = $('[data-receipt-location-form]', dialog);
    form.reset(); $('[data-nir-errors]', form).hidden = true;
    form.elements.is_default.checked = !Array.from(owner.elements.location_id.options).some(option => option.value && option.dataset.warehouse === warehouse.value && !option.disabled);
    $('[data-location-warehouse-label]', dialog).textContent = warehouse.selectedOptions[0].textContent;
    dialog.showModal(); document.documentElement.classList.add('has-nir-dialog'); form.elements.name.focus({preventScroll:true});
  }

  function closeLocation(dialog) {
    if ($('[data-receipt-location-form][aria-busy="true"]', dialog)) return;
    dialog.close(); if (!$('[data-nir-dialog]')?.open) document.documentElement.classList.remove('has-nir-dialog'); locationOpener?.focus({preventScroll:true}); locationOwner = null;
  }

  async function saveLocation(form) {
    if (form.getAttribute('aria-busy') === 'true' || !form.reportValidity() || !locationOwner) return;
    const owner = locationOwner, warehouse = owner.elements.warehouse_id.value;
    const dialog = form.closest('dialog'), buttons = $$('button', form);
    const data = new FormData(form); data.set('_csrf', owner.elements._csrf.value); data.set('warehouse_id', warehouse);
    form.setAttribute('aria-busy','true'); buttons.forEach(button => button.disabled = true);
    try {
      const result = await json(await fetch(owner.dataset.locationUrl, {method:'POST',body:data,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}));
      if (owner.isConnected) {
        const select = owner.elements.location_id, previous = select.value;
        select.replaceChildren(new Option('Alege locația', ''));
        result.locations.forEach(item => {
          const option = new Option(item.name + (Number(item.is_default) ? ' · implicită' : ''), String(item.id));
          option.dataset.warehouse = String(item.warehouse_id); option.dataset.default = String(item.is_default); select.append(option);
        });
        select.value = owner.elements.warehouse_id.value === warehouse ? String(result.location.id) : previous;
        locationsChanged(owner);
      }
      form.removeAttribute('aria-busy'); closeLocation(dialog);
      toast('Locația a fost adăugată și selectată.');
    } catch (error) { message(form, error.message, error.data?.errors || {}); }
    finally { form.removeAttribute('aria-busy'); buttons.forEach(button => button.disabled = false); }
  }

  async function importLines(input) {
    const file=input.files[0];if(!file)return;const form=input.closest('form'),status=$('[data-import-status]',form);
    if(form.getAttribute('aria-busy')==='true')return;
    const body=$('.nir-form__body',form),buttons=$$('button[type="submit"]',form),disabled=buttons.map(button=>button.disabled);
    form.setAttribute('aria-busy','true');if(body)body.inert=true;buttons.forEach(button=>button.disabled=true);
    status.hidden=false;status.textContent='Citim documentul. Verificăm denumirile și valorile…';input.disabled=true;
    try{
      const attachments=selectedFiles.get($('[data-nir-files]',form)) || [];
      if(attachments.length>=30&&!attachments.some(existing=>existing===file))throw new Error('Elimină un atașament înainte de import: documentul sursă trebuie păstrat, iar limita este de 30 de fișiere.');
      const data=new FormData();data.set('_csrf',form.elements._csrf.value);data.set('file',file);data.set('supplier_id',form.elements.supplier_id.value);
      const response=window.NirUpload ? await window.NirUpload.post(form,form.dataset.importUrl,data) : await fetch(form.dataset.importUrl,{method:'POST',body:data,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
      const result=await json(response);
      const imported=result.data || result.import || result;const lines=imported.lines || [];
      if(!lines.length)throw new Error('Nu au fost găsite poziții importabile în acest fișier.');
      const current=$$('[data-nir-line]',form),empty=current.length===1&&!lineValue(current[0],'supplier_name')&&!lineValue(current[0],'variant_id');
      if((empty?0:current.length)+lines.length>1000)throw new Error('Importul ar depăși 1.000 de poziții. Nicio poziție nu a fost adăugată.');
      if(empty)current[0].remove();
      lines.forEach(values=>{const line=addLine(form,values,true);if(line&&values.match?.matched&&!values.match.ambiguous){if(values.match.product)applyProduct(line,values.match.product);else{setLineValue(line,'product_id',values.match.product_id);setLineValue(line,'variant_id',values.match.variant_id);$('[data-product-label]',line).textContent='Asociere exactă găsită';$('[data-product-meta]',line).textContent='Verifică produsul înainte de confirmare.';}if(values.purchase_unit&&values.purchase_unit!=='buc')$('[name$="[purchase_unit]"]',line).dataset.manuallyChanged='import';applyReference(line,values.match.reference,true);}});
      const header=imported.header || imported;
      ['invoice_series','invoice_number','invoice_date','currency','supplier_id'].forEach(key=>{if(header[key]&&form.elements[key])form.elements[key].value=header[key];});
      if(header.invoice_date)invoiceDateChanged(form);
      acceptFiles(form,[file]);renumber(form);currencyChanged(form);
      status.replaceChildren();const summary=document.createElement('p');summary.textContent=lines.length+' poziții importate. Verifică asocierile, cantitățile și valorile înainte de confirmare.';status.append(summary);
      const warnings=imported.warnings || result.warnings || [];status.classList.toggle('has-warnings',warnings.length>0);
      if(warnings.length){const title=document.createElement('strong');title.textContent='Verificări necesare pentru această factură';status.append(title);const list=document.createElement('ul');warnings.forEach(warning=>{const li=document.createElement('li');li.textContent=typeof warning==='string'?warning:(warning.message || String(warning));list.append(li);});status.append(list);status.scrollIntoView({behavior:'smooth',block:'center'});}
      else { window.NirFlow?.show(form,2); $('[data-nir-lines]',form).scrollIntoView({behavior:'smooth',block:'start'}); }
    }catch(e){status.textContent=e.message;}finally{input.disabled=false;input.value='';form.removeAttribute('aria-busy');if(body)body.inert=false;buttons.forEach((button,index)=>button.disabled=disabled[index]);}
  }

  async function fetchRate(button) {
    const form=button.closest('form'),status=$('[data-rate-message]',form);
    const signature=()=>[form.elements.currency.value,form.elements.exchange_rate_date.value,form.elements.exchange_rate.value].join('|');
    const original=signature(),request=new AbortController();rateRequests.get(form)?.abort();rateRequests.set(form,request);
    button.disabled=true;status.textContent='Căutăm ultimul curs publicat…';
    const url=new URL(form.dataset.rateUrl,location.origin);url.searchParams.set('date',form.elements.exchange_rate_date.value);url.searchParams.set('currency',form.elements.currency.value);
    try {
      const result=await json(await fetch(url,{headers:{Accept:'application/json'},signal:request.signal}));
      if(!form.isConnected || rateRequests.get(form)!==request)return;
      if(signature()!==original){status.textContent='Datele au fost schimbate. Solicită din nou cursul pentru valorile actuale.';return;}
      if(result.rate){form.elements.exchange_rate.value=compactDecimal(result.rate);form.elements.exchange_rate_date.value=result.date;}
      status.textContent=result.message || 'Cursul a fost actualizat.';preview(form);
    } catch(error) {if(error.name!=='AbortError')status.textContent=error.message+' Poți introduce manual cursul verificat.';}
    finally {if(rateRequests.get(form)===request)button.disabled=false;}
  }

  function reverseSelection(row, changed = false) {
    const toggle = $('[data-reverse-select]',row), field = $('[data-reverse-quantity]',row);
    if(!toggle || !field) return;
    const available = number(field.max) > 0;
    toggle.disabled = !available;
    if(!available) toggle.checked = false;
    if(changed) {
      if(toggle.checked) field.value = field.dataset.previousQuantity || compactDecimal(field.max);
      else {if(number(field.value)>0) field.dataset.previousQuantity=field.value;field.value='0';}
    }
    field.disabled = !toggle.checked;
    field.required = toggle.checked;
    field.min = toggle.checked ? '0.0001' : '0';
    row.classList.toggle('is-selected',toggle.checked);
    row.classList.toggle('is-unavailable',!available);
    const hint=$('[data-reverse-hint]',row);
    if(hint)hint.textContent=!available?'Nicio cantitate disponibilă':toggle.checked?'Poți modifica această cantitate':'Activează produsul pentru retur';
    const form=row.closest('form'),summary=$('[data-reverse-summary]',form);
    if(summary){const count=$$('[data-reverse-select]',form).filter(input=>input.checked).length;summary.textContent=count?count+' '+(count===1?'produs selectat':'produse selectate'):'Niciun produs selectat';}
    reverseAlternative(form);
  }

  function reverseAlternative(form) {
    const box=$('[data-reverse-alternative]',form);if(!box)return;
    const needed=$$('[data-reverse-quantity]',form).some(field=>!field.disabled && number(field.value)>number(field.dataset.originalAvailable));
    const input=$('input',box);box.hidden=!needed;
    if(input){input.required=needed;if(!needed)input.checked=false;}
    $$('[data-reverse-quantity]',form).forEach(field=>{
      const exceeded=!field.disabled && number(field.value)>number(field.max);
      field.setCustomValidity?.(exceeded?'La acest furnizor și preț de achiziție poți returna cel mult '+decimal(number(field.max))+' '+(field.dataset.unit || 'buc')+' din acest document.':'');
    });
  }

  function initialize(root) {
    $$('.nir-reverse-row',root).forEach(row=>reverseSelection(row));
    $$('[data-nir-registry-search]',root).forEach(form=>{
      const input=$('input[type="search"]',form),clear=$('[data-nir-search-clear]',form);
      if(!input||!clear||form.classList.contains('has-clear-control'))return;
      form.classList.add('has-clear-control');
      const sync=()=>{clear.hidden=input.value.length===0;};
      const reset=()=>{input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();};
      input.addEventListener('input',sync);
      input.addEventListener('keydown',event=>{if(event.key==='Escape'&&input.value){event.preventDefault();event.stopPropagation();reset();}});
      clear.addEventListener('click',reset);
      sync();
    });
    $$('[data-nir-form]',root).forEach(form=>{currencyChanged(form);locationsChanged(form);renumber(form);window.NirFlow?.init(form);window.NirUnsaved?.track(form);});
    const exportForm=$('[data-nir-export-form]',root);if(exportForm)exportDates(exportForm);
    $$('[data-receipt-location-dialog]',root).forEach(dialog=>{
      dialog.addEventListener('cancel',event=>{event.preventDefault();event.stopPropagation();closeLocation(dialog);});
      dialog.addEventListener('click',event=>{if(event.target===dialog){const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)closeLocation(dialog);}});
    });
  }
  function exportDates(form){const block=$('[data-export-dates]',form);if(block){block.hidden=form.elements.period.value!=='custom';$$('input',block).forEach(input=>{input.required=!block.hidden;input.disabled=block.hidden;});}}

  async function copyDocumentNumber(button) {
    if(button.disabled || !button.dataset.nirCopy)return;
    button.disabled=true;
    let copied=false;
    try {
      if(navigator.clipboard?.writeText) {
        try { await navigator.clipboard.writeText(button.dataset.nirCopy);copied=true; } catch { /* Keep the user-initiated fallback available. */ }
      }
      if(!copied) {
        const field=document.createElement('textarea');
        field.value=button.dataset.nirCopy;field.readOnly=true;
        field.style.cssText='position:fixed;left:0;top:0;width:1px;height:1px;opacity:0;font-size:16px;';
        document.body.append(field);
        try { field.focus({preventScroll:true});field.select();copied=document.execCommand('copy'); } finally { field.remove(); }
      }
      if(!copied)throw new Error('Copy failed');
      button.classList.add('is-copied');
      toast('Numărul documentului a fost copiat.',1700);
      setTimeout(()=>button.classList.remove('is-copied'),1700);
    } catch { toast('Copierea nu a reușit. Poți selecta și copia manual numărul.',4000); }
    finally { button.disabled=false;button.focus({preventScroll:true}); }
  }

  document.addEventListener('click',event=>{
    const copy=event.target.closest('[data-nir-copy]');if(copy){event.preventDefault();copyDocumentNumber(copy);return;}
    const help=event.target.closest('[data-nir-stock-help]');
    if(help){const dialog=$('[data-nir-stock-help-dialog]');if(dialog?.showModal){stockHelpOpener=help;dialog.showModal();}return;}
    if(event.target.closest('[data-nir-stock-help-close]')){$('[data-nir-stock-help-dialog]')?.close();stockHelpOpener?.focus({preventScroll:true});return;}
    const download=event.target.closest('[data-nir-download]');
    if(download && window.NirExports && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey){event.preventDefault();window.NirExports.download(download.href,download);return;}
    const locationOpen=event.target.closest('[data-receipt-location-open]');if(locationOpen){event.preventDefault();openLocation(locationOpen);return;}
    const locationClose=event.target.closest('[data-receipt-location-close]');if(locationClose){event.preventDefault();closeLocation(locationClose.closest('dialog'));return;}
    const trigger=event.target.closest('[data-nir-modal-open]');if(trigger&&!event.ctrlKey&&!event.metaKey){event.preventDefault();openModal(trigger);return;}
    const close=event.target.closest('[data-nir-modal-close]');if(close){if($('[data-nir-dialog]')?.open){event.preventDefault();closeModal();return;}const form=close.closest('[data-nir-form]');if(window.NirUnsaved && !window.NirUnsaved.requestClose(form,()=>location.assign(close.href)))event.preventDefault();return;}
    const add=event.target.closest('[data-nir-add-line]');if(add){event.preventDefault();const line=addLine(add.closest('form'));line?.scrollIntoView({behavior:'smooth',block:'start'});$('[data-line-name]',line || document)?.focus({preventScroll:true});return;}
    const remove=event.target.closest('[data-nir-remove-line]');if(remove){event.preventDefault();const form=remove.closest('form');remove.closest('[data-nir-line]').remove();if(!$$('[data-nir-line]',form).length)addLine(form);renumber(form);return;}
    const supplier=event.target.closest('[data-nir-add-supplier]');if(supplier){event.preventDefault();createInline(supplier,'supplier');return;}
    const newLocation=event.target.closest('[data-nir-add-location]');if(newLocation){event.preventDefault();createInline(newLocation,'location');return;}
    const picker=event.target.closest('[data-nir-product-picker]');if(picker){openPicker(picker);return;}
    const pickerClose=event.target.closest('[data-product-close]');if(pickerClose){closePicker(pickerClose.closest('form'));return;}
    const removeFile=event.target.closest('[data-remove-file]');if(removeFile){const form=removeFile.closest('form'),input=$('[data-nir-files]',form),transfer=new DataTransfer();Array.from(input.files).filter((_,index)=>index!==Number(removeFile.dataset.removeFile)).forEach(file=>transfer.items.add(file));input.files=transfer.files;selectedFiles.set(input,Array.from(input.files));fileList(form);return;}
    const rateButton=event.target.closest('[data-nir-fetch-rate]');if(rateButton)fetchRate(rateButton);
  });
  document.addEventListener('keydown',event=>{
    const panel=event.target.closest('[data-nir-product-panel]');if(!panel || panel.hidden)return;
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closePicker(panel.closest('form'));return;}
    // Searching must not submit the underlying NIR form.
    if(event.key==='Enter' && event.target.matches('[data-product-search]')){event.preventDefault();clearTimeout(searchTimer);loadProducts(panel.closest('form'),event.target.value);return;}
    if(event.key==='Tab'){
      const focusable=$$('button:not([disabled]),input',panel),first=focusable[0],last=focusable[focusable.length-1];
      if(event.shiftKey && event.target===first){event.preventDefault();last?.focus();}
      else if(!event.shiftKey && event.target===last){event.preventDefault();first?.focus();}
    }
  });
  document.addEventListener('input',event=>{
    const search=event.target.closest('[data-product-search]');if(search){pickerAbort?.abort();clearTimeout(searchTimer);searchTimer=setTimeout(()=>loadProducts(search.closest('form'),search.value),260);return;}
    const form=event.target.closest('[data-nir-form]');if(!form)return;
    if(event.target.matches('[data-reverse-quantity]'))reverseAlternative(form);
    if(event.target.matches('[data-line-name]'))$('[data-line-title]',event.target.closest('[data-nir-line]')).textContent=event.target.value || 'Produs nou';
    if(/\[(purchase_unit|stock_unit|conversion_factor)\]$/.test(event.target.name || ''))event.target.dataset.manuallyChanged='1';
    if(event.target.closest('[data-nir-line]') || event.target.name==='exchange_rate')preview(form);
  });
  document.addEventListener('focusout',event=>{if(/\[(supplier_name|supplier_code|supplier_ean)\]$/.test(event.target.name || '')){const line=event.target.closest('[data-nir-line]');if(line)matchLine(line);}});
  document.addEventListener('change',event=>{
    if(event.target.matches('[data-nir-autosubmit]')){event.target.form.requestSubmit();return;}
    const form=event.target.closest('form');if(!form)return;
    if(event.target.matches('[data-nir-currency]'))currencyChanged(form);
    if(event.target.matches('[data-nir-warehouse]'))locationsChanged(form);
    if(event.target.name==='invoice_date')invoiceDateChanged(form);
    if(['nir_at','received_at','exchange_rate_date'].includes(event.target.name))event.target.dataset.manuallyChanged='1';
    if(event.target.matches('[data-nir-files]')){acceptFiles(form,Array.from(event.target.files));}
    if(event.target.matches('[data-nir-camera]')){acceptFiles(form,Array.from(event.target.files));event.target.value='';}
    if(event.target.matches('[data-nir-import]'))importLines(event.target);
    if(event.target.matches('[data-export-period]'))exportDates(form);
    if(event.target.matches('[data-reverse-select]'))reverseSelection(event.target.closest('.nir-reverse-row'),true);
    if(event.target.matches('[data-reverse-quantity]'))reverseAlternative(form);
    if(event.target.closest('[data-nir-line]'))preview(form);
    if(event.target.name==='vat_in_cost')preview(form);
  });
  document.addEventListener('submit',event=>{
    if(event.target.matches('[data-receipt-location-form]')){event.preventDefault();saveLocation(event.target);return;}
    if(event.target.matches('[data-nir-form]')){event.preventDefault();submitForm(event.target,event.submitter);}
    if(event.target.matches('[data-nir-export-form]')){const form=event.target;const start=form.elements.from?.value,end=form.elements.to?.value;if(form.elements.period.value==='custom'&&start>end){event.preventDefault();message(form,'Data de început trebuie să fie înainte de data de sfârșit.');return;}if(window.NirExports){event.preventDefault();window.NirExports.fromForm(form);return;}const progress=$('[data-export-progress]',form);if(progress)progress.hidden=false;setTimeout(()=>{if(progress)progress.textContent='Exportul a fost solicitat. Descărcarea începe când fișierul este pregătit.';},1500);}
  });
  document.addEventListener('dragover',event=>{const zone=event.target.closest('[data-nir-dropzone]');if(zone){event.preventDefault();zone.classList.add('is-dragging');}});
  document.addEventListener('dragleave',event=>{event.target.closest('[data-nir-dropzone]')?.classList.remove('is-dragging');});
  document.addEventListener('drop',event=>{const zone=event.target.closest('[data-nir-dropzone]');if(zone){event.preventDefault();zone.classList.remove('is-dragging');acceptFiles(zone.closest('form'),Array.from(event.dataTransfer.files));}});
  $$('[data-nir-dialog]').forEach(dialog=>{dialog.addEventListener('cancel',event=>{event.preventDefault();const panel=$('[data-nir-product-panel]:not([hidden])',dialog);if(panel){closePicker(panel.closest('form'));}else closeModal();});dialog.addEventListener('click',event=>{if(event.target===dialog)closeModal();});});
  document.addEventListener('nir:download-message',event=>toast(event.detail,6000));
  $('[data-nir-stock-help-dialog]')?.addEventListener('cancel',event=>{event.preventDefault();event.stopPropagation();event.currentTarget.close();stockHelpOpener?.focus({preventScroll:true});});
  initialize(document);
  try{const feedback=sessionStorage.getItem('nir-feedback');if(feedback){sessionStorage.removeItem('nir-feedback');toast(feedback);}}catch{/* Storage can be unavailable in private browsing. */}
})();
