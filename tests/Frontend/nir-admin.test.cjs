const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// Deliberately small DOM fixtures exercise request ordering and form state, not layout.
class Element {
  constructor(tag='div') { this.tagName=tag; this.children=[]; this.dataset={}; this.attributes={}; this.queries={}; this.parents={}; this.disabled=false; this.hidden=false; this.isConnected=true; this._value=''; this._text=''; this.classList={add(){},remove(){},toggle(){}}; }
  get value(){return this._value;} set value(value){this._value=String(value ?? '');}
  get textContent(){return this._text + this.children.map(child=>child.textContent || '').join('');} set textContent(text){this._text=String(text);this.children=[];}
  get options(){return this.children;} get selectedOptions(){return this.children.filter(child=>child.value===this.value);}
  setAttribute(name,value){this.attributes[name]=String(value);} getAttribute(name){return this.attributes[name] ?? null;} removeAttribute(name){delete this.attributes[name];}
  append(...nodes){nodes.forEach(node=>{node.parent=this;this.children.push(node);});} replaceChildren(...nodes){this.children=[];this._text='';this.append(...nodes);}
  remove(){if(this.parent)this.parent.children=this.parent.children.filter(child=>child!==this);this.isConnected=false;}
  querySelector(selector){return this.querySelectorAll(selector)[0] ?? null;}
  querySelectorAll(selector){if(this.queries[selector])return this.queries[selector];if(selector==='[data-local-file]')return this.children.filter(child=>'localFile' in child.dataset);return [];}
  closest(selector){return this.parents[selector] ?? null;} focus(){this.focused=true;} scrollIntoView(){} addEventListener(){}
}
class DataTransfer {
  constructor(){this.files=[];this.items={add:file=>this.files.push(file)};}
}
class FormData {
  constructor(form){this.values=new Map();for(const field of form?.elements || [])if(field.name)this.set(field.name,field.value);}
  set(name,value){this.values.set(name,value);} get(name){return this.values.get(name) ?? null;}
}
function response(data,ok=true){return {ok,status:ok?200:422,headers:{get:()=> 'application/json'},json:async()=>data};}
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};}
function harness(fetch){
  const document=new Element();document.documentElement=new Element();document.createElement=tag=>new Element(tag);document.addEventListener=()=>{};
  const context=vm.createContext({document,fetch,FormData,DataTransfer,AbortController,URL,Option:function(text,value){const option=new Element('option');option.textContent=text;option.value=value;return option;},setTimeout,clearTimeout,location:{origin:'http://localhost',assign(){}},sessionStorage:{getItem:()=>null},matchMedia:()=>({matches:true})});
  const source=fs.readFileSync(path.resolve(__dirname,'../../public/assets/js/nir-admin.js'),'utf8').replace(/\}\)\(\);\s*$/, 'globalThis.nirTest={reverseSelection,loadProducts,submitForm,importLines,fetchRate,createInline,acceptFiles,locationsChanged,saveLocation,setLocationOwner(owner,opener){locationOwner=owner;locationOpener=opener;}};})();');
  context.window=context;
  vm.runInContext(source,context);return context.nirTest;
}
function form(values={}){
  const f=new Element('form');f.elements=[];f.reportValidity=()=>true;f.action='/save';
  for(const [name,value] of Object.entries(values)){const field=new Element('input');field.name=name;field.value=value;field.parents.form=f;f.elements.push(field);f.elements[name]=field;}
  const body=new Element(),errors=new Element(),submit=new Element('button');errors.hidden=true;
  f.queries['.nir-form__body']=[body];f.queries['[data-nir-errors]']=[errors];f.queries['button[type="submit"]']=[submit];
  return {f,body,errors,submit};
}

test('server field errors remain visible when the same response also contains warnings',async()=>{
  const api=harness(async()=>response({success:false,message:'Verifică datele',errors:{'lines.0.accepted_quantity':'Cantitatea este prea mare'},warnings:['Cod furnizor necompletat']},false));
  const {f,errors,submit}=form({'lines[0][accepted_quantity]':'5'}),warnings=new Element(),ack=new Element(),checkbox=new Element('input');
  warnings.hidden=true;ack.queries.input=[checkbox];f.queries['[data-nir-warnings]']=[warnings];f.queries['[data-nir-acknowledge]']=[ack];
  await api.submitForm(f,submit);
  assert.equal(errors.hidden,false);assert.match(errors.textContent,/Cantitatea este prea mare/);assert.equal(f.elements[0].getAttribute('aria-invalid'),'true');
  assert.match(warnings.textContent,/Cod furnizor necompletat/);assert.equal(warnings.hidden,false);assert.equal(checkbox.required,true);assert.equal(submit.disabled,false);
});

test('an in-flight import prevents saving and restores controls after a network failure',async()=>{
  const pending=deferred();let requests=0;const api=harness(()=>{requests++;return pending.promise;});
  const {f,body,submit}=form({_csrf:'token',supplier_id:'1'}),input=new Element('input'),status=new Element();
  f.dataset.importUrl='/import';input.parents.form=f;input.files=[{name:'invoice.xml',size:100}];f.queries['[data-import-status]']=[status];
  const importing=api.importLines(input);assert.equal(f.getAttribute('aria-busy'),'true');assert.equal(body.inert,true);assert.equal(submit.disabled,true);
  await api.submitForm(f,submit);assert.equal(requests,1,'save must not race ahead of import');
  pending.reject(new Error('Conexiune întreruptă'));await importing;
  assert.equal(f.getAttribute('aria-busy'),null);assert.equal(body.inert,false);assert.equal(submit.disabled,false);assert.equal(input.disabled,false);assert.match(status.textContent,/Conexiune întreruptă/);
});

for(const changed of ['currency','exchange_rate_date','exchange_rate'])test('late BNR response cannot overwrite a changed '+changed,async()=>{
  const pending=deferred(),api=harness(()=>pending.promise),{f}=form({currency:'EUR',exchange_rate_date:'2026-11-10',exchange_rate:'1'}),button=new Element('button'),status=new Element();
  f.dataset.rateUrl='/rate';button.parents.form=f;f.queries['[data-rate-message]']=[status];
  const fetching=api.fetchRate(button);f.elements[changed].value=changed==='currency'?'USD':changed==='exchange_rate_date'?'2026-11-11':'6.12345678';
  pending.resolve(response({rate:'4.9999',date:'2026-11-10'}));await fetching;
  assert.equal(f.elements.exchange_rate.value,changed==='exchange_rate'?'6.12345678':'1');assert.equal(button.disabled,false);assert.match(status.textContent,/Datele au fost schimbate/);
});

test('late location creation stays attached to its original warehouse',async()=>{
  const pending=deferred(),api=harness(()=>pending.promise),{f}=form({_csrf:'token',warehouse_id:'1'}),button=new Element('button'),details=new Element('details'),select=new Element('select'),placeholder=new Element('option'),other=new Element('option');
  f.dataset.locationUrl='/location';f.elements.location_id=select;placeholder.value='';other.value='9';other.dataset.warehouse='2';select.append(placeholder,other);select.value='9';button.parents={form:f,details};
  const creating=api.createInline(button,'location');f.elements.warehouse_id.value='2';
  pending.resolve(response({location:{id:88,warehouse_id:1,name:'Recepție A'}}));await creating;
  const created=select.options.find(option=>option.value==='88');assert.equal(created.dataset.warehouse,'1');assert.equal(created.disabled,true);assert.equal(select.value,'9');assert.equal(button.disabled,false);
});

test('additional file selections retain earlier files and deduplicate the same source file',()=>{
  const api=harness(async()=>{throw new Error('No request expected');}),{f}=form(),input=new Element('input'),list=new Element();
  input.files=[];f.queries['[data-nir-files]']=[input];f.queries['[data-nir-file-list]']=[list];
  const first={name:'catalog.xlsx',size:32*1024*1024,lastModified:1},second={name:'detail.png',size:200,lastModified:2};
  api.acceptFiles(f,[first]);input.files=[second];api.acceptFiles(f,input.files);api.acceptFiles(f,[first]);
  assert.deepEqual(input.files,[first,second]);assert.equal(list.children.length,2);
});

test('different files with identical names, sizes and timestamps are never silently dropped',()=>{
  const api=harness(async()=>{throw new Error('No request expected');}),{f}=form(),input=new Element('input'),list=new Element();
  input.files=[];f.queries['[data-nir-files]']=[input];f.queries['[data-nir-file-list]']=[list];
  const first={name:'factura.pdf',size:123,lastModified:1},second={name:'factura.pdf',size:123,lastModified:1};
  api.acceptFiles(f,[first]);api.acceptFiles(f,[second]);
  assert.deepEqual(input.files,[first,second]);assert.equal(list.children.length,2);
});

test('an incomplete draft may be saved while a correction still requires browser validation',async()=>{
  let requests=0;const api=harness(async()=>{requests++;return response({success:true,redirect:'/draft/1/'});});
  const {f,submit}=form({supplier_id:'',invoice_number:''});let validations=0;f.reportValidity=()=>{validations++;return false;};
  submit.formNoValidate=true;await api.submitForm(f,submit);assert.equal(requests,1);assert.equal(validations,0);
  submit.formNoValidate=false;await api.submitForm(f,submit);assert.equal(requests,1,'correction must not POST missing required fields');assert.equal(validations,1);
});

test('import at the attachment limit cannot lose its source document or modify existing lines',async()=>{
  let requests=0;const api=harness(async()=>{requests++;return response({lines:[]});}),{f,body,submit}=form({_csrf:'token',supplier_id:'1'}),attachments=new Element('input'),list=new Element(),input=new Element('input'),status=new Element(),existingLine=new Element();
  attachments.files=[];f.queries['[data-nir-files]']=[attachments];f.queries['[data-nir-file-list]']=[list];f.queries['[data-import-status]']=[status];f.queries['[data-nir-line]']=[existingLine];
  f.dataset.importUrl='/import';input.parents.form=f;const files=Array.from({length:30},(_,index)=>({name:'source-'+index+'.xml',size:100+index,lastModified:index}));api.acceptFiles(f,files);
  input.files=[{name:'new-invoice.xml',size:200,lastModified:40}];await api.importLines(input);
  assert.equal(requests,0);assert.equal(attachments.files.length,30);assert.equal(existingLine.isConnected,true);assert.match(status.textContent,/documentul sursă trebuie păstrat/);assert.equal(body.inert,false);assert.equal(submit.disabled,false);
  input.files=[files[0]];await api.importLines(input);assert.equal(requests,1,'already attached source remains importable without adding a 31st file');assert.equal(attachments.files.length,30);
});


test('changing warehouse selects its default location without overwriting an existing choice',()=>{
  const api=harness(async()=>{}),{f}=form({warehouse_id:'2'}),select=new Element('select');f.elements.location_id=select;
  for(const [id,warehouse,preferred] of [['1','1','1'],['2','2','0'],['3','2','1']]){const option=new Element('option');option.value=id;option.dataset={warehouse,default:preferred};select.append(option);}
  select.value='1';api.locationsChanged(f);assert.equal(select.value,'3');assert.equal(select.options[0].disabled,true);
  select.value='2';api.locationsChanged(f);assert.equal(select.value,'2');
});

test('location modal saves once and updates only the location selector in the NIR form',async()=>{
  const pending=deferred();let requests=0;const api=harness(()=>{requests++;return pending.promise;});
  const {f:owner}=form({_csrf:'token',warehouse_id:'1',invoice_number:'Factura nesalvată','lines[0][supplier_name]':'Produs păstrat'});
  owner.dataset.locationUrl='/location';owner.elements.location_id=new Element('select');
  const {f:location,submit}=form({name:'Recepție nouă',is_default:'1'}),dialog=new Element('dialog'),opener=new Element('button');
  dialog.open=true;dialog.close=()=>dialog.open=false;location.parents.dialog=dialog;location.queries.button=[submit];api.setLocationOwner(owner,opener);
  const saving=api.saveLocation(location);await api.saveLocation(location);assert.equal(requests,1);
  pending.resolve(response({success:true,location:{id:3},locations:[{id:1,name:'Veche',warehouse_id:1,is_default:0},{id:3,name:'Recepție nouă',warehouse_id:1,is_default:1}]}));await saving;
  assert.equal(owner.elements.location_id.value,'3');assert.equal(owner.elements.location_id.options[2].dataset.default,'1');
  assert.equal(owner.elements.invoice_number.value,'Factura nesalvată');assert.equal(owner.elements['lines[0][supplier_name]'].value,'Produs păstrat');
  assert.equal(dialog.open,false);assert.equal(opener.focused,true);assert.equal(submit.disabled,false);
});

test('location validation failure leaves both forms and the selected location intact',async()=>{
  const api=harness(async()=>response({success:false,message:'Denumire duplicată',errors:{name:'Există deja o locație.'}},false));
  const {f:owner}=form({_csrf:'token',warehouse_id:'1',invoice_number:'Date păstrate'});owner.dataset.locationUrl='/location';owner.elements.location_id=new Element('select');owner.elements.location_id.value='7';
  const {f:location,errors,submit}=form({name:'Sediu'}),dialog=new Element('dialog');dialog.open=true;location.parents.dialog=dialog;location.queries.button=[submit];api.setLocationOwner(owner,new Element('button'));
  await api.saveLocation(location);assert.equal(dialog.open,true);assert.equal(owner.elements.location_id.value,'7');assert.equal(location.elements.name.value,'Sediu');assert.equal(errors.hidden,false);assert.equal(submit.disabled,false);
});


test('a late catalog response cannot replace the newest query results',async()=>{
  const older=deferred(),newer=deferred();let count=0;
  const api=harness(()=>++count===1?older.promise:newer.promise),{f}=form({supplier_id:'1'}),results=new Element(),status=new Element();
  f.dataset.productUrl='/products';f.queries['[data-product-results]']=[results];f.queries['[data-product-status]']=[status];
  const first=api.loadProducts(f,'cardigan'),second=api.loadProducts(f,'LM-01');
  newer.resolve(response({items:[{product_name:'Produs exact',sku:'LM-01',search_match:'exact'}]}));await second;
  older.resolve(response({items:[{product_name:'Rezultat vechi',sku:'OLD'},{product_name:'Alt rezultat',sku:'OLD-2'}]}));await first;
  assert.equal(results.children.length,1);assert.match(results.children[0].innerHTML,/Produs exact/);
  assert.match(status.textContent,/Potrivire exactă/);
});

test('catalog response is discarded if supplier changes while searching',async()=>{
  const pending=deferred(),api=harness(()=>pending.promise),{f}=form({supplier_id:'1'}),results=new Element();
  f.dataset.productUrl='/products';f.queries['[data-product-results]']=[results];
  const loading=api.loadProducts(f,'FURN-01');f.elements.supplier_id.value='2';
  pending.resolve(response({items:[{product_name:'Wrong supplier',sku:'SKU'}]}));await loading;
  assert.equal(results.children.length,0);
});


test('return quantities activate only for selected products and preserve a partial quantity when toggled',()=>{
  const api=harness(async()=>{}),{f}=form(),row=new Element(),toggle=new Element('input'),quantity=new Element('input'),hint=new Element(),summary=new Element();
  row.parents.form=f;row.queries['[data-reverse-select]']=[toggle];row.queries['[data-reverse-quantity]']=[quantity];row.queries['[data-reverse-hint]']=[hint];
  f.queries['[data-reverse-select]']=[toggle];f.queries['[data-reverse-summary]']=[summary];
  quantity.max='2.5000';quantity.value='0';toggle.checked=false;
  api.reverseSelection(row);assert.equal(quantity.disabled,true);assert.equal(quantity.required,false);
  toggle.checked=true;api.reverseSelection(row,true);assert.equal(quantity.disabled,false);assert.equal(quantity.required,true);assert.equal(quantity.value,'2.5');assert.equal(quantity.min,'0.0001');assert.equal(summary.textContent,'1 produs selectat');
  quantity.value='0.75';toggle.checked=false;api.reverseSelection(row,true);assert.equal(quantity.disabled,true);assert.equal(quantity.value,'0');
  toggle.checked=true;api.reverseSelection(row,true);assert.equal(quantity.value,'0.75');
  quantity.max='0';api.reverseSelection(row);assert.equal(toggle.disabled,true);assert.equal(toggle.checked,false);assert.equal(quantity.disabled,true);
});

test('alternative stock needs acknowledgement only beyond the original receipt availability',()=>{
  const api=harness(async()=>{}),{f}=form(),row=new Element(),toggle=new Element('input'),quantity=new Element('input'),box=new Element(),ack=new Element('input');
  row.parents.form=f;row.queries['[data-reverse-select]']=[toggle];row.queries['[data-reverse-quantity]']=[quantity];
  f.queries['[data-reverse-select]']=[toggle];f.queries['[data-reverse-quantity]']=[quantity];f.queries['[data-reverse-alternative]']=[box];box.queries.input=[ack];
  quantity.max='2';quantity.value='1';quantity.dataset.originalAvailable='1';quantity.dataset.unit='buc';quantity.setCustomValidity=text=>quantity.validationMessage=text;toggle.checked=true;
  api.reverseSelection(row);assert.equal(box.hidden,true);assert.equal(ack.required,false);
  quantity.value='2';api.reverseSelection(row);assert.equal(box.hidden,false);assert.equal(ack.required,true);assert.equal(quantity.validationMessage,'');
  quantity.value='3';api.reverseSelection(row);assert.match(quantity.validationMessage,/cel mult 2 buc/);
  ack.checked=true;quantity.value='1';api.reverseSelection(row);assert.equal(box.hidden,true);assert.equal(ack.checked,false);assert.equal(quantity.validationMessage,'');
});
