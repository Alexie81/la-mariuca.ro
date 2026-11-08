'use strict';

// Executes the actual service-worker handlers without a browser, subscription or network.
// Run: node --test tests/js/admin-service-worker.test.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../public/admin-service-worker.js'), 'utf8');

async function clickPush(scope, target, existingWindow) {
  const handlers = new Map();
  const actions = [];
  let notification;
  const window = {
    navigate: async (url) => { actions.push(['navigate', url]); },
    focus: async () => { actions.push(['focus']); },
  };
  const context = vm.createContext({
    URL,
    self: {
      registration: {
        scope,
        showNotification: async (title, options) => { notification = { title, ...options }; },
      },
      addEventListener: (name, handler) => handlers.set(name, handler),
    },
    clients: {
      matchAll: async () => existingWindow ? [window] : [],
      openWindow: async (url) => { actions.push(['open', url]); },
    },
  });
  vm.runInContext(source, context);
  let pending;
  handlers.get('push')({
    data: { json: () => ({ title: 'Cerere de anulare · LM-2026-000042', type: 'comenzi', target }) },
    waitUntil: (work) => { pending = work; },
  });
  await pending;
  assert.equal(notification.requireInteraction, true);
  assert.equal(notification.data.target, target || 'admin/notificari/');
  handlers.get('notificationclick')({
    notification: { ...notification, close: () => actions.push(['close']) },
    waitUntil: (work) => { pending = work; },
  });
  await pending;
  return actions;
}

for (const base of ['', '/boutique']) {
  for (const existingWindow of [false, true]) {
    test(`production cancellation link preserves order and anchor (${base || 'root'}, ${existingWindow ? 'existing window' : 'new window'})`, async () => {
      const target = `${base}/admin/comenzi/42/#cerere-anulare`;
      const actions = await clickPush(`https://la-mariuca.ro${base}/`, target, existingWindow);
      assert.deepEqual(actions, existingWindow
        ? [['close'], ['navigate', `https://la-mariuca.ro${target}`], ['focus']]
        : [['close'], ['open', `https://la-mariuca.ro${target}`]]);
      assert.ok(!JSON.stringify(actions).includes('localhost'));
      assert.ok(!JSON.stringify(actions).includes('127.0.0.1'));
    });
  }

  test(`production new-order/payment link opens the matching order (${base || 'root'})`, async () => {
    const target = `${base}/admin/comenzi/42/`;
    assert.deepEqual(await clickPush(`https://la-mariuca.ro${base}/`, target, false),
      [['close'], ['open', `https://la-mariuca.ro${target}`]]);
  });

  test(`missing target stays in the production admin area (${base || 'root'})`, async () => {
    assert.deepEqual(await clickPush(`https://la-mariuca.ro${base}/`, undefined, false),
      [['close'], ['open', `https://la-mariuca.ro${base}/admin/notificari/`]]);
  });
}
