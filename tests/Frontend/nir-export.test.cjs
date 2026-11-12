const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};

function fixture(){
  const elements={};
  for(const key of ['title','copy','cancel','retry','progress','bar','amount'])elements[key]={hidden:false,listeners:{},addEventListener(type,fn){this.listeners[type]=fn;}};
  const selectors={'[data-export-title]':'title','[data-export-copy]':'copy','[data-export-cancel]':'cancel','[data-export-retry]':'retry','[data-transfer-progress]':'progress','[data-transfer-bar]':'bar','[data-transfer-amount]':'amount'};
  const classes=new Set(),timers=new Map(),requests=[],downloads=[],notifications=[],revoked=[];
  let nextTimer=0,opened=0,focusCount=0;
  const dialog={open:false,listeners:{},classList:{add:v=>classes.add(v),remove:v=>classes.delete(v)},querySelector:s=>elements[selectors[s]],showModal(){this.open=true;opened++;},close(){this.open=false;},addEventListener(type,fn){this.listeners[type]=fn;}};
  const trigger={isConnected:true,attrs:{},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},focus(){focusCount++;}};
  class TestURL extends URL {static createObjectURL(){return 'blob:local-test';}static revokeObjectURL(url){revoked.push(url);}}
  const context=vm.createContext({URL:TestURL,AbortController,location:{href:'http://localhost/admin/nir-uri/'},FormData:class{constructor(form){return form.data;}},
    CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
    setTimeout(fn,delay){const id=++nextTimer;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id),
    document:{querySelector:()=>dialog,body:{append(){}},createElement(){return {click(){downloads.push({href:this.href,name:this.download});},remove(){}};},dispatchEvent:event=>notifications.push(event)},
    fetch(url,options){const request={url,options,...deferred()};requests.push(request);options.signal.addEventListener('abort',()=>request.reject(new Error('Aborted')));return request.promise;}
  });context.window=context;
  vm.runInContext(fs.readFileSync(path.resolve(__dirname,'../../public/assets/js/nir-export.js'),'utf8'),context);
  return {elements,dialog,classes,timers,requests,downloads,notifications,revoked,trigger,download:context.NirExports.download,fromForm:context.NirExports.fromForm,upload:context.NirTransfer.upload,
    get opened(){return opened;},get focusCount(){return focusCount;},tick(delay){for(const [id,timer] of [...timers])if(timer.delay===delay){timers.delete(id);timer.fn();}}};
}
function response({type='application/zip',name="attachment; filename*=UTF-8''Dosar%20NIR%20%C4%83.zip",body=Promise.resolve(new Blob(['data'])),ok=true,status=200,json={}}={}){
  return {ok,status,headers:{get:key=>key==='Content-Type'?type:name},blob:()=>body,json:async()=>json};
}

test('fast download never flashes a modal and preserves the UTF-8 filename',async()=>{
  const f=fixture(),pending=f.download('/export/zip',f.trigger);f.requests[0].resolve(response());await pending;f.tick(300);
  assert.equal(f.opened,0);assert.equal(f.downloads[0].name,'Dosar NIR ă.zip');assert.equal(f.notifications.length,1);assert.equal(f.trigger.attrs['aria-busy'],undefined);f.tick(60000);assert.equal(f.revoked.length,1);
});
test('after 300ms the loader stays visible until the entire file body arrives',async()=>{
  const f=fixture(),body=deferred(),pending=f.download('/export/zip',f.trigger);f.tick(300);assert.equal(f.dialog.open,true);
  f.requests[0].resolve(response({body:body.promise}));await Promise.resolve();assert.equal(f.dialog.open,true);assert.equal(f.downloads.length,0);
  body.resolve(new Blob(['zip']));await pending;assert.equal(f.dialog.open,false);assert.equal(f.downloads.length,1);assert.equal(f.focusCount,1);
});
test('server validation error stays in a retryable modal without downloading JSON',async()=>{
  const f=fixture(),pending=f.download('/export',f.trigger);f.requests[0].resolve(response({ok:false,status:422,type:'application/json',json:{message:'Alege perioada.'}}));await pending;
  assert.equal(f.dialog.open,true);assert.equal(f.elements.copy.textContent,'Alege perioada.');assert.equal(f.elements.retry.hidden,false);assert.equal(f.downloads.length,0);
  f.elements.retry.listeners.click();f.requests[1].resolve(response());await new Promise(setImmediate);assert.equal(f.dialog.open,false);assert.equal(f.downloads.length,1);
});
test('login HTML is never saved as a PDF or spreadsheet',async()=>{
  const f=fixture(),pending=f.download('/export/pdf',f.trigger);f.requests[0].resolve({...response({type:'text/html'}),redirected:true});await pending;
  assert.equal(f.downloads.length,0);assert.match(f.elements.copy.textContent,/Sesiunea a expirat/);
});
test('cancel aborts the request and repeated clicks do not start duplicate downloads',async()=>{
  const f=fixture(),pending=f.download('/zip',f.trigger);await f.download('/zip',f.trigger);assert.equal(f.requests.length,1);f.tick(300);
  f.elements.cancel.listeners.click();await pending;assert.equal(f.requests[0].options.signal.aborted,true);assert.equal(f.dialog.open,false);assert.equal(f.downloads.length,0);assert.equal(f.trigger.attrs['aria-busy'],undefined);
});
test('registry export keeps the selected format and date filters',async()=>{
  const f=fixture(),pending=f.fromForm({action:'/admin/nir-uri/export/',data:new Map([['format','zip'],['period','custom'],['from','2026-11-01'],['to','2026-11-12']]),querySelector:()=>f.trigger});
  const url=new URL(f.requests[0].url);assert.equal(url.searchParams.get('format'),'zip');assert.equal(url.searchParams.get('from'),'2026-11-01');assert.equal(url.searchParams.get('to'),'2026-11-12');
  f.requests[0].resolve(response());await pending;
});
test('empty files are reported as errors and unsafe filename separators are removed',async()=>{
  const f=fixture(),pending=f.download('/export',f.trigger);f.requests[0].resolve(response({body:Promise.resolve(new Blob([]))}));await pending;assert.equal(f.downloads.length,0);assert.match(f.elements.copy.textContent,/gol/);
  const next=f.download('/export',f.trigger);f.requests[1].resolve(response({name:'attachment; filename="../document.xlsx"'}));await next;assert.equal(f.downloads[0].name,'.._document.xlsx');
});
test('upload waits 300ms, displays actual progress, and remains open at 100% until finish',()=>{
  const f=fixture(),job=f.upload();assert.equal(f.dialog.open,false);f.tick(300);assert.equal(f.dialog.open,true);assert.equal(f.elements.cancel.hidden,true);
  job.update(37,'37% · 12 / 33 MB');assert.equal(f.elements.bar.value,37);assert.equal(f.elements.amount.textContent,'37% · 12 / 33 MB');
  job.update(100,'100%',true);assert.match(f.elements.title.textContent,/Verificăm/);let prevented=false;f.dialog.listeners.cancel({preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(f.dialog.open,true);
  job.finish();assert.equal(f.dialog.open,false);
});
test('fast upload finishes without ever opening its modal',()=>{
  const f=fixture(),job=f.upload();job.finish();f.tick(300);assert.equal(f.opened,0);
});
