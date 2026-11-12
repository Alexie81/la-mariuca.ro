const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup() {
  const fields = [{name:'invoice_number',type:'text',value:'123'}, {name:'_csrf',type:'hidden',value:'old'}];
  const form = {elements:fields};
  const events = {}, dialogEvents = {}, buttons = {};
  let focusCount = 0, openCount = 0;
  for (const name of ['keep','discard']) buttons[name] = {addEventListener(type,fn){this[type]=fn;},focus(){focusCount++;}};
  const dialog = {open:false,showModal(){this.open=true;openCount++;},close(){this.open=false;},
    querySelector(selector){return buttons[selector.includes('keep')?'keep':'discard'];},addEventListener(type,fn){dialogEvents[type]=fn;}};
  const opener = {isConnected:true,focus(){focusCount++;}};
  const context = vm.createContext({document:{querySelector:()=>dialog,querySelectorAll:()=>[form],activeElement:opener},addEventListener(type,fn){events[type]=fn;}});
  context.window=context;
  vm.runInContext(fs.readFileSync(path.resolve(__dirname,'../../public/assets/js/nir-unsaved.js'),'utf8'),context);
  const api=context.NirUnsaved;api.track(form);
  return {api,form,fields,buttons,dialog,events,dialogEvents,get focusCount(){return focusCount;},get openCount(){return openCount;}};
}

test('unchanged or reverted data closes directly; search/UI and CSRF are ignored',()=>{
  const f=setup();assert.equal(f.api.requestClose(f.form,()=>assert.fail()),true);
  f.fields.push({type:'search',value:'catalog query'});f.fields[1].value='renewed';assert.equal(f.api.isDirty(f.form),false);
  f.fields[0].value='124';assert.equal(f.api.isDirty(f.form),true);
  f.fields[0].value='123';assert.equal(f.api.isDirty(f.form),false);
});
test('keep and Escape preserve all values and do not discard; duplicate close requests reuse the prompt',()=>{
  const f=setup();f.fields[0].value='Draft nou';let discarded=0;
  const callback=()=>discarded++;
  assert.equal(f.api.requestClose(f.form,callback),false);f.api.requestClose(f.form,callback);assert.equal(f.openCount,1);
  f.buttons.keep.click();assert.equal(f.dialog.open,false);assert.equal(f.fields[0].value,'Draft nou');assert.equal(discarded,0);assert.equal(f.api.isDirty(f.form),true);
  f.api.requestClose(f.form,callback);let prevented=false,stopped=false;
  f.dialogEvents.cancel({preventDefault(){prevented=true;},stopPropagation(){stopped=true;}});
  assert.equal(prevented,true);assert.equal(stopped,true);assert.equal(discarded,0);assert.equal(f.dialog.open,false);assert.equal(f.focusCount,4);
});
test('explicit discard runs once and clears the unload protection for the closed form',()=>{
  const f=setup();f.fields[0].value='Changed';let discarded=0;
  f.api.requestClose(f.form,()=>discarded++);f.buttons.discard.click();f.buttons.discard.click();
  assert.equal(discarded,1);assert.equal(f.api.isDirty(f.form),false);
  let prevented=false;f.events.beforeunload({preventDefault(){prevented=true;}});assert.equal(prevented,false);
});
test('programmatic product selection, row addition/removal and attachments are tracked without input events',()=>{
  const f=setup();const product={name:'lines[0][variant_id]',type:'hidden',value:'2'};
  f.fields.push(product);assert.equal(f.api.isDirty(f.form),true);f.api.markSaved(f.form);
  product.value='3';assert.equal(f.api.isDirty(f.form),true);product.value='2';assert.equal(f.api.isDirty(f.form),false);
  f.fields.pop();assert.equal(f.api.isDirty(f.form),true);f.api.markSaved(f.form);
  const attachment={name:'attachments[]',type:'file',files:[]};f.fields.push(attachment);f.api.markSaved(f.form);
  attachment.files=[{name:'factura.pdf',size:5300,lastModified:100}];assert.equal(f.api.isDirty(f.form),true);
  f.api.markSaved(f.form);attachment.files=[{name:'factura.pdf',size:5300,lastModified:100}];assert.equal(f.api.isDirty(f.form),true,'another file with matching metadata is still a change');
  f.api.markSaved(f.form);attachment.files=[];assert.equal(f.api.isDirty(f.form),true);
});
test('return selections and quantities are tracked; disabled quantities do not create spurious changes',()=>{
  const f=setup();const quantity={name:'quantities[1]',type:'number',value:'0',disabled:true};f.fields.push(quantity);f.api.markSaved(f.form);
  quantity.disabled=false;quantity.value='1';assert.equal(f.api.isDirty(f.form),true);
  quantity.disabled=true;assert.equal(f.api.isDirty(f.form),false);
  const consent={name:'accept_alternative_stock',type:'checkbox',value:'1',checked:false};f.fields.push(consent);f.api.markSaved(f.form);consent.checked=true;assert.equal(f.api.isDirty(f.form),true);
});
test('repeated initialization cannot erase dirty state; only a successful save clears it',()=>{
  const f=setup();f.fields[0].value='Unsaved';f.api.track(f.form);assert.equal(f.api.isDirty(f.form),true);
  let prevented=false;const event={preventDefault(){prevented=true;}};f.events.beforeunload(event);assert.equal(prevented,true);assert.equal(event.returnValue,'');
  f.api.markSaved(f.form);assert.equal(f.api.requestClose(f.form,()=>assert.fail()),true);
});
