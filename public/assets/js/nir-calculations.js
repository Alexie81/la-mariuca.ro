/* Exact decimal preview. The server remains authoritative for every saved amount. */
(() => {
  'use strict';
  const pow = scale => 10n ** BigInt(scale);
  const parse = (value, scale) => {
    const text = String(value ?? '0').trim().replace(',', '.');
    if (!new RegExp('^\\d{1,10}(?:\\.\\d{1,' + scale + '})?$').test(text)) throw new Error('Număr invalid');
    const [whole, fraction = ''] = text.split('.');
    return BigInt(whole) * pow(scale) + BigInt(fraction.padEnd(scale, '0'));
  };
  const round = (numerator, denominator) => (numerator + denominator / 2n) / denominator;
  const fixed = (value, scale) => {
    const text = value.toString().padStart(scale + 1, '0');
    return text.slice(0, -scale) + '.' + text.slice(-scale);
  };
  function line(input, rate = '1', vatInCost = false) {
    const quantity = parse(input.accepted_quantity ?? '1', 4);
    const factor = parse(input.conversion_factor ?? '1', 4);
    const price = parse(input.unit_price ?? '0', 6);
    const vat = parse(input.vat_percent ?? '21', 6);
    const discount = parse(input.discount_percent ?? '0', 6);
    const extra = round(parse(input.extra_cost_ron ?? '0', 6), 10000n);
    const exchange = parse(rate, 8);
    if (!factor || !exchange || vat > 100000000n || discount > 100000000n || quantity * factor % 10000n) throw new Error('Verifică valorile');
    const units = quantity * factor / 10000n;
    const mode = input.price_mode || 'unit';
    let net = round(quantity * price * (100000000n - discount), 10000000000000000n);
    let discountBani = round(quantity * price * discount, 10000000000000000n);
    let tax, total;
    if (mode === 'line_net' || mode === 'exact_vat') { net = parse(input.net_override ?? '0', 2); discountBani = 0n; }
    if (mode === 'gross') {
      total = parse(input.gross_override ?? '0', 2);
      net = round(total * 100000000n, 100000000n + vat);
      tax = total - net; discountBani = 0n;
    } else if (['unit', 'line_net', 'exact_vat'].includes(mode)) {
      tax = mode === 'exact_vat' ? parse(input.tax_override ?? '0', 2) : round(net * vat, 100000000n);
      total = net + tax;
    } else throw new Error('Mod de calcul invalid');
    const netRon = round(net * exchange, 100000000n);
    const totalRon = round(total * exchange, 100000000n);
    const inventory = input.tracked === false || input.tracked === '0' || input.tracked === 0 ? 0n : (vatInCost ? totalRon : netRon) + extra;
    for (const value of [units, net, tax, total, discountBani, netRon, totalRon, inventory]) if(value > 9223372036854775807n) throw new Error('Valoarea depășește limita documentului');
    return Object.fromEntries(Object.entries({
      stock_quantity: fixed(units, 4), stock_quantity_units: units,
      net_bani: net, tax_bani: tax, total_bani: total, discount_bani: discountBani,
      net_ron_bani: netRon, tax_ron_bani: totalRon - netRon, total_ron_bani: totalRon,
      inventory_bani: inventory, unit_cost_ron: fixed(units ? round(inventory * 100000000n, units) : 0n, 6)
    }).map(([key, value]) => [key, String(value)]));
  }
  globalThis.NirAmounts = Object.freeze({line});
})();
