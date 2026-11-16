const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup(initial = 'not_sent', responses = []) {
  const timers = new Map(), events = {}, calls = [];
  const deleteAction = {removed:false, remove() {this.removed = true;}};
  const lockNotice = {textContent:'Starea transmiterii este afișată în panoul SPV.'};
  let sequence = 0, current;
  const makePanel = (state, disabled = false) => {
    const operation = state === 'submitted' ? 'verifica' : 'trimite';
    const button = {disabled, setAttribute() {}, removeAttribute() {}};
    const message = {hidden:true, textContent:'', classList:{toggle() {}}};
    const form = {
      action:`/admin/spv-efactura/facturi/1/${operation}/`, dataset:{spvOperation:operation},
      querySelector:() => button,
      matches:() => state === 'submitted' && !disabled,
      closest:() => form,
    };
    const locked = !['not_sent','queued','scheduled','unconfigured','awaiting_configuration'].includes(state);
    return {state, form, button, message, dataset:{spvCanDelete:locked?'0':'1',spvLocked:locked?'1':'0'}, replaceWith(next) {current = next;}, querySelector(selector) {
      if (selector === '[data-spv-feedback]') return message;
      return state === 'submitted' && !disabled ? form : null;
    }};
  };
  current = makePanel(initial);
  const document = {hidden:false,
    querySelector:selector => selector === '[data-invoice-fiscal-lock]' ? lockNotice : current,
    querySelectorAll:selector => selector === '[data-invoice-delete]' ? [deleteAction] : [],
    addEventListener:(name, handler) => {events[name] = handler;},
  };
  const context = vm.createContext({
    document, AbortController,
    setTimeout(fn, ms) {const id = ++sequence; timers.set(id, {fn, ms}); return id;},
    clearTimeout:id => timers.delete(id),
    FormData:class {constructor(form) {this.csrf = 'csrf-from-form'; this.form = form;}},
    DOMParser:class {parseFromString(state) {return {querySelector:() => makePanel(state)};}},
    fetch:async (url, options) => {
      calls.push({url, options});
      const item = responses.shift();
      if (item instanceof Error) throw item;
      if (!item) throw new Error('Unexpected request');
      return {ok:true, status:200, redirected:false, headers:{get:() => 'application/json'}, json:async () => item, ...item.http};
    },
  });
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/invoice-spv.js'), 'utf8'), context);
  const flush = () => new Promise(resolve => setImmediate(resolve));
  return {
    calls, timers, document, deleteAction, lockNotice, get panel() {return current;},
    async submit() {events.submit({target:current.form, preventDefault() {}}); await flush();},
    async tick() {const [id, timer] = [...timers.entries()].sort((a, b) => a[1].ms - b[1].ms)[0] || []; if (!timer) return; timers.delete(id); timer.fn(); await flush();},
  };
}

test('manual send happens once, then automatic requests only check the response until accepted', async () => {
  const fixture = setup('not_sent', [
    {ok:true, state:'submitted', html:'submitted', message:'Încărcată'},
    {ok:true, state:'submitted', html:'submitted', message:'În verificare'},
    {ok:true, state:'accepted', html:'accepted', message:'Acceptată'},
  ]);
  assert.equal(fixture.timers.size, 0, 'an unsent invoice is never uploaded automatically by its page');
  await fixture.submit();
  assert.equal(fixture.panel.state, 'submitted');
  assert.equal(fixture.deleteAction.removed, true, 'an invoice becomes undeletable immediately after its first upload');
  assert.match(fixture.lockNotice.textContent, /blocat/);
  await fixture.tick(); await fixture.tick();
  assert.deepEqual(fixture.calls.map(call => call.url.split('/').at(-2)), ['trimite', 'verifica', 'verifica']);
  assert.equal(fixture.calls[0].options.method, 'POST');
  assert.equal(fixture.calls[0].options.body.csrf, 'csrf-from-form');
  assert.equal(fixture.panel.state, 'accepted');
  assert.equal(fixture.timers.size, 0);
});

test('a preflight failure keeps an unsent invoice editable and deletable', async () => {
  const fixture = setup('not_sent', [{ok:false, state:'not_sent', html:'not_sent', message:'Lipsește autorizarea ANAF.'}]);
  await fixture.submit();
  assert.equal(fixture.deleteAction.removed, false);
  assert.doesNotMatch(fixture.lockNotice.textContent, /blocat/);
  assert.equal(fixture.timers.size, 0);
});

test('rejected or uncertain documents stop automatic checks and never trigger another upload', async () => {
  for (const state of ['rejected', 'uncertain']) {
    const fixture = setup('submitted', [{ok:false, state, html:state, message:'Verifică răspunsul'}]);
    await fixture.tick(); await fixture.tick();
    assert.equal(fixture.panel.state, state);
    assert.equal(fixture.calls.length, 1);
    assert.equal(fixture.timers.size, 0);
  }
});

test('hidden tabs make no requests and a connection failure retries only status with backoff', async () => {
  const fixture = setup('submitted', [new Error('Connection unavailable'), {ok:true, state:'accepted', html:'accepted', message:'Acceptată'}]);
  fixture.document.hidden = true;
  await fixture.tick(); assert.equal(fixture.calls.length, 0);
  fixture.document.hidden = false;
  await fixture.tick(); assert.equal(fixture.calls.length, 1);
  assert.equal([...fixture.timers.values()][0].ms, 30000);
  await fixture.tick(); assert.equal(fixture.calls.length, 2);
  assert.ok(fixture.calls.every(call => call.url.endsWith('/verifica/')));
  assert.equal(fixture.timers.size, 0);
});

test('expired session stops polling without replacing the invoice with a login page', async () => {
  const fixture = setup('submitted', [{http:{status:419}}]);
  await fixture.tick();
  assert.equal(fixture.panel.state, 'submitted');
  assert.equal(fixture.timers.size, 0);
  assert.match(fixture.panel.message.textContent, /Sesiunea/);
});
