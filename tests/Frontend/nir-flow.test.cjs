const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');

// Small DOM fixtures test navigation without replacing fields or losing unsaved data.
class Node {
  constructor(){this.dataset={};this.attributes={};this.queries={};this.parents={};this.value='';this.hidden=false;this.classList={add(){},toggle(){}};}
  matches(){return this.editor===true;}
  querySelectorAll(selector){return this.queries[selector]||[];}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){return this.parents[selector]||null;}
  setAttribute(name,value){this.attributes[name]=value;}
  getAttribute(name){return this.attributes[name]||null;}
  removeAttribute(name){delete this.attributes[name];}
  hasAttribute(name){return name in this.attributes;}
  focus(){this.focused=true;}
  checkValidity(){return this.valid!==false;}
  reportValidity(){this.reported=true;return this.checkValidity();}
}
function fixture(){
  const listeners={},document={addEventListener(type,fn){listeners[type]=fn;}};
  const window={},context=vm.createContext({document,window,queueMicrotask});
  vm.runInContext(fs.readFileSync(path.resolve(__dirname,'../../public/assets/js/nir-flow.js'),'utf8'),context);
  const form=new Node();form.editor=true;const fields={};form.elements={namedItem:name=>fields[name]};
  const sections=[1,2,3,4].map(step=>{const section=new Node();section.dataset.flowStep=String(step);return section;});
  const buttons=sections.map(section=>{const b=new Node();b.dataset.flowGo=section.dataset.flowStep;b.parents['[data-nir-editor]']=form;return b;});
  const back=new Node(),next=new Node();next.attributes['data-flow-next']='';next.parents['[data-nir-editor]']=form;
  form.queries['[data-flow-step]']=sections;form.queries['.nir-flow-nav [data-flow-go]']=buttons;
  form.queries['[data-flow-back]']=[back];form.queries['[data-flow-next]']=[next];
  const reviews=['invoice','products','destination','files'].map(key=>{const node=new Node();node.dataset.flowReview=key;return node;});
  form.queries['[data-flow-review]']=reviews;
  function input(name,value,step){const node=new Node();node.value=value;fields[name]=node;node.parents['[data-nir-editor]']=form;node.parents['[data-flow-step]']=sections[step-1];return node;}
  function click(button){listeners.click({target:{closest:()=>button},preventDefault(){}});}
  function required(step,nodes){form.queries[`[data-flow-step="${step}"] input[required], [data-flow-step="${step}"] select[required], [data-flow-step="${step}"] textarea[required]`]=nodes;}
  return {api:window.NirFlow,form,fields,sections,buttons,next,back,reviews,input,click,required,listeners};
}

test('moving between steps preserves unsaved invoice values and file inputs',()=>{
  const f=fixture(),invoice=f.input('invoice_number','FACT-123',1),upload=new Node();upload.files=[{name:'factura.pdf'}];
  f.form.queries['[data-nir-files]']=[upload];f.api.init(f.form);
  f.click(f.buttons[3]);assert.equal(f.next.hidden,true);assert.equal(f.sections[0].hidden,true);
  f.click(f.buttons[0]);assert.equal(f.back.disabled,true);assert.equal(invoice.value,'FACT-123');assert.equal(upload.files.length,1);
  assert.equal(f.reviews[3].textContent,'1 fișier');
});
test('continue stays on the current step and exposes an invalid required field',()=>{
  const f=fixture(),invoice=f.input('invoice_number','',1);invoice.valid=false;
  f.required(1,[invoice]);f.api.init(f.form);f.click(f.next);
  assert.equal(f.form.dataset.flowCurrent,'1');assert.equal(invoice.reported,true);
  invoice.valid=true;invoice.value='42';f.click(f.next);assert.equal(f.form.dataset.flowCurrent,'2');
});
test('an unmatched stock product directs the user to the product picker',()=>{
  const f=fixture(),line=new Node(),tracked=new Node(),variant=new Node(),picker=new Node(),error=new Node();tracked.checked=true;
  line.queries['[data-line-tracked]']=[tracked];line.queries['[name$="[variant_id]"]']=[variant];
  line.queries['[data-nir-product-picker]']=[picker];line.queries['[data-line-error]']=[error];f.form.queries['[data-nir-line]']=[line];
  f.api.init(f.form);f.click(f.buttons[1]);f.click(f.next);
  assert.equal(f.form.dataset.flowCurrent,'2');assert.equal(picker.focused,true);assert.match(error.textContent,/Alege produsul/);
  tracked.checked=false;f.click(f.next);assert.equal(f.form.dataset.flowCurrent,'3','documentary lines remain supported');
});
test('server errors reveal the correct step and nested disclosure without losing other data',()=>{
  const f=fixture(),field=f.input('lines[0][supplier_name]','Păstrat',2),details=new Node();details.tagName='DETAILS';details.parentElement=f.form;field.parentElement=details;
  f.form.dataset.nirErrorFields='["lines.0.supplier_name"]';f.api.init(f.form);
  assert.equal(f.form.dataset.flowCurrent,'2');assert.equal(details.open,true);assert.equal(field.attributes['aria-invalid'],'true');assert.equal(field.value,'Păstrat');
});
test('navigation waits while the form is saving or importing',()=>{
  const f=fixture();f.api.init(f.form);f.form.setAttribute('aria-busy','true');f.click(f.buttons[3]);f.click(f.next);
  assert.equal(f.form.dataset.flowCurrent,'1');
});
test('initialization does not reset the current step when supplementary controls are refreshed',()=>{
  const f=fixture();f.api.init(f.form);f.click(f.buttons[2]);f.api.init(f.form);
  assert.equal(f.form.dataset.flowCurrent,'3');
});
