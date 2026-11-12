const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/js/nir-calculations.js'), 'utf8'), context);
const fixtures = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/nir-amounts.json'), 'utf8'));
for (const fixture of fixtures) test(fixture.name + ' matches server decimal amounts', () => {
  const actual = context.NirAmounts.line(fixture.line, fixture.rate, fixture.vat_in_cost);
  for (const [key, value] of Object.entries(actual)) assert.equal(value, String(fixture.expected[key]), key);
});
test('Romanian comma input and invalid precision', () => {
  assert.equal(context.NirAmounts.line({accepted_quantity: '2,5', unit_price: '1,2'}).net_bani, '300');
  for (const input of [{accepted_quantity: '-1'}, {vat_percent: '101'}, {conversion_factor: '0'}, {accepted_quantity: '0.0001', conversion_factor: '0.0001'}, {unit_price: 'NaN'}, {price_mode: 'other'}, {tracked:false,extra_cost_ron:'invalid'}, {accepted_quantity:'9999999999',unit_price:'9999999999'}]) assert.throws(() => context.NirAmounts.line(input));
});
