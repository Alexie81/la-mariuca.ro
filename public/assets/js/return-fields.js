(() => {
  'use strict';
  const initialized=new WeakSet();
  const money=value=>new Intl.NumberFormat('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100)+' Lei';
  const bani=value=>{const match=/^(\d{1,9})(?:[.,](\d{1,2}))?$/.exec(value.trim());return match?Number(match[1])*100+Number((match[2]||'').padEnd(2,'0')):0;};
  const initialize=form=>{
    if(initialized.has(form)||!form.querySelector('[data-refund-destination],[data-return-settlement]'))return;
    initialized.add(form);
    const panel=form.querySelector('[data-return-settlement]'),destination=form.querySelector('[data-refund-destination]');
    let nextRow=3,previousCustom=false;
    const template=panel?.querySelector('[data-cost-row]')?.cloneNode(true);
    if(panel) [...panel.querySelectorAll('[data-cost-row]')].slice(1).forEach(row=>row.remove());
    const sync=()=>{
      if(destination){const bank=destination.querySelector('[name="refund_method"]:checked')?.value==='bank';const fields=destination.querySelector('[data-bank-fields]');if(fields){fields.hidden=!bank;fields.querySelectorAll('input').forEach(input=>{input.disabled=!bank;input.required=bank;});}}
      if(!panel)return;
      const enabled=name=>!!panel.querySelector(`[data-return-toggle="${name}"]`)?.checked;
      panel.querySelectorAll('[data-return-section]').forEach(section=>{const active=enabled(section.dataset.returnSection);section.hidden=!active;section.querySelectorAll('input').forEach(input=>{input.disabled=!active;input.required=active&&(input.matches('[data-positive-cost]')||input.name==='refund_total');});});
      let base=0;
      form.querySelectorAll('[data-return-quantity]').forEach(select=>base+=Number(select.selectedOptions[0]?.dataset.returnBani)||0);
      form.querySelectorAll('[data-return-value]').forEach(input=>{const units=Math.round(Number(input.value||0)*10000),total=Number(input.dataset.units),already=Number(input.dataset.already||0),gross=Number(input.dataset.gross);if(units>0&&total>0)base+=Math.round(gross*(already+units)/total)-Math.round(gross*already/total);});
      form.querySelectorAll('[data-return-shipping]:checked').forEach(input=>base+=Number(input.dataset.gross)||0);
      let costs=enabled('transport')?bani(panel.querySelector('[name="transport_cost"]').value):0;
      if(enabled('other'))panel.querySelectorAll('[data-cost-row] input[name$="[amount]"]').forEach(input=>costs+=bani(input.value));
      const automatic=Math.max(0,base-costs),custom=enabled('custom'),field=panel.querySelector('[name="refund_total"]');
      if(custom&&!previousCustom&&field.value==='')field.value=(automatic/100).toFixed(2).replace('.',',');
      previousCustom=custom;
      const net=custom?bani(field.value):automatic;
      field.setCustomValidity(custom&&net>base?'Suma nu poate depăși valoarea selectată.':'');
      panel.querySelector('[data-return-base]').textContent=money(base);
      panel.querySelector('[data-return-costs]').textContent='− '+money(costs);
      panel.querySelector('[data-return-net]').textContent=money(net);
      const adjustment=panel.querySelector('[data-return-adjustment]');adjustment.hidden=!custom||net===automatic;adjustment.textContent='Ajustare manuală: '+(net>=automatic?'+ ':'− ')+money(Math.abs(net-automatic));
    };
    form.addEventListener('input',sync);form.addEventListener('change',()=>queueMicrotask(sync));
    form.addEventListener('click',event=>{
      if(event.target.closest('[data-add-cost]')&&template){const rows=panel.querySelector('[data-cost-rows]');if(rows.children.length>=10)return;const row=template.cloneNode(true);row.querySelectorAll('input').forEach(input=>{input.name=input.name.replace(/\[\d+\]/,`[${nextRow}]`);input.value='';input.disabled=false;});nextRow++;rows.append(row);row.querySelector('input').focus();sync();}
      if(event.target.closest('[data-remove-cost]')){event.target.closest('[data-cost-row]').remove();sync();}
    });
    sync();
  };
  const scan=root=>{if(root instanceof Element){if(root.matches('form'))initialize(root);const form=root.closest('form');if(form)initialize(form);}root.querySelectorAll?.('form').forEach(initialize);};
  scan(document);new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(scan))).observe(document.body,{childList:true,subtree:true});
})();
