const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');

function fixture(){
  const label={},amount={},bar={},classes=new Set();
  const box={hidden:true,classList:{add:v=>classes.add(v),remove:v=>classes.delete(v)},querySelector:s=>({'[data-upload-label]':label,'[data-upload-amount]':amount,progress:bar}[s])};
  const form={querySelector:()=>box};let xhr,fetchCalls=0,finished=0;const updates=[];
  class FakeXHR{constructor(){xhr=this;this.upload={};this.headers={};}open(method,url){this.method=method;this.url=url;}setRequestHeader(k,v){this.headers[k]=v;}getResponseHeader(){return 'application/json';}send(data){this.data=data;}}
  const context=vm.createContext({XMLHttpRequest:FakeXHR,Response,fetch:async()=>{fetchCalls++;return new Response('{}');},document:{}});context.window=context;
  context.NirTransfer={upload:()=>({update:(...args)=>updates.push(args),finish:()=>finished++})};
  vm.runInContext(fs.readFileSync(path.resolve(__dirname,'../../public/assets/js/nir-upload.js'),'utf8'),context);
  return {form,box,label,amount,bar,classes,updates,get finished(){return finished;},post:context.NirUpload.post,get xhr(){return xhr;},get fetchCalls(){return fetchCalls;}};
}
function data(withFile=true){return {values:()=>withFile?['csrf',{size:33*1024*1024}]:['csrf','reason']};}

test('real byte progress stays pending at 100% until the server responds',async()=>{
  const f=fixture();let done=false;const payload=data();const pending=f.post(f.form,'/upload',payload).then(r=>{done=true;return r;});
  assert.equal(f.box.hidden,false);assert.equal(f.xhr.headers['X-Requested-With'],'XMLHttpRequest');assert.equal(f.xhr.data,payload);
  f.xhr.upload.onprogress({lengthComputable:true,loaded:10,total:40});assert.equal(f.bar.value,25);assert.match(f.amount.textContent,/25%/);assert.equal(f.updates[0][0],25);
  f.xhr.upload.onload();await Promise.resolve();assert.equal(done,false);assert.equal(f.box.hidden,false);assert.equal(f.classes.has('is-processing'),true);
  f.xhr.status=200;f.xhr.responseText='{"success":true}';f.xhr.onload();const result=await pending;
  assert.deepEqual(await result.json(),{success:true});assert.equal(f.box.hidden,true);assert.equal(f.finished,1);
});
test('upload response preserves server validation errors instead of reporting success',async()=>{
  const f=fixture(),pending=f.post(f.form,'/upload',data());f.xhr.status=422;f.xhr.responseText='{"success":false,"errors":{"attachments":"Invalid"}}';f.xhr.onload();
  const result=await pending;assert.equal(result.ok,false);assert.equal(result.status,422);assert.deepEqual((await result.json()).errors,{attachments:'Invalid'});
});
test('network failure retains the supplied files for retry',async()=>{
  const f=fixture(),payload=data(),pending=f.post(f.form,'/upload',payload);f.xhr.onerror();
  await assert.rejects(pending,/Fișierele rămân selectate/);assert.equal(f.xhr.data,payload);assert.equal(f.box.hidden,true);assert.equal(f.finished,1);
});
test('invalid server response rejects without leaving the form waiting forever',async()=>{
  const f=fixture(),pending=f.post(f.form,'/upload',data());f.xhr.status=0;f.xhr.responseText='';f.xhr.onload();
  await assert.rejects(pending,/Serverul nu a răspuns/);assert.equal(f.box.hidden,true);
});
test('forms without attachments use the ordinary request without a progress panel',async()=>{
  const f=fixture();const result=await f.post(f.form,'/save',data(false));assert.equal(result.status,200);assert.equal(f.fetchCalls,1);assert.equal(f.box.hidden,true);assert.equal(f.xhr,undefined);
});
