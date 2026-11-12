(() => {
  'use strict';
  const headers = {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'};
  const mb = bytes => (bytes / 1024 / 1024).toLocaleString('ro-RO', {maximumFractionDigits:1});

  function panel(form) {
    let box = form.querySelector('[data-nir-upload-progress]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'nir-upload-progress';
      box.dataset.nirUploadProgress = '';
      box.innerHTML = '<div><strong data-upload-label role="status" aria-live="polite"></strong><span data-upload-amount></span></div><progress max="100" value="0" aria-label="Progresul încărcării"></progress><small>Fișierele se trimit în siguranță. Păstrează formularul deschis.</small>';
      const footer = form.querySelector('.nir-form__footer');
      if (footer) footer.before(box);
      else form.append(box);
    }
    box.hidden = false;
    return box;
  }

  function post(form, url, data) {
    const files = Array.from(data.values()).filter(value => typeof value !== 'string' && value.size > 0);
    if (!files.length) return fetch(url, {method:'POST',body:data,headers});
    const box = panel(form), label = box.querySelector('[data-upload-label]'), amount = box.querySelector('[data-upload-amount]'), bar = box.querySelector('progress');
    label.textContent = 'Se încarcă documentele…';
    amount.textContent = '0% · ' + mb(files.reduce((total,file) => total + file.size,0)) + ' MB';
    bar.value = 0;
    box.classList.remove('is-processing');
    const modal=window.NirTransfer?.upload();
    const finish=()=>{box.hidden=true;modal?.finish();};
    return new Promise((resolve,reject) => {
      const xhr = new XMLHttpRequest();
      const processing = () => {
        label.textContent = 'Transfer încheiat. Verificăm documentele…';
        amount.textContent = '100%';
        bar.value = 100;
        box.classList.add('is-processing');
        modal?.update(100,'100% · Transfer încheiat',true);
      };
      xhr.open('POST',url);
      Object.entries(headers).forEach(([key,value]) => xhr.setRequestHeader(key,value));
      xhr.upload.onprogress = event => {
        if (!event.lengthComputable || !event.total) return;
        const percent = Math.min(100, Math.floor(event.loaded / event.total * 100));
        bar.value = percent;
        amount.textContent = percent + '% · ' + mb(event.loaded) + ' / ' + mb(event.total) + ' MB';
        modal?.update(percent,amount.textContent);
        if (percent === 100) processing();
      };
      xhr.upload.onload = processing;
      xhr.onload = () => {
        finish();
        try { resolve(new Response(xhr.responseText, {status:xhr.status,headers:{'Content-Type':xhr.getResponseHeader('Content-Type') || ''}})); }
        catch { reject(new Error('Serverul nu a răspuns. Fișierele rămân selectate; încearcă din nou.')); }
      };
      xhr.onerror = () => {finish(); reject(new Error('Conexiunea s-a întrerupt. Fișierele rămân selectate; încearcă din nou.'));};
      xhr.onabort = () => {finish(); reject(new Error('Încărcarea a fost întreruptă. Încearcă din nou.'));};
      try{xhr.send(data);}catch(error){finish();reject(error);}
    });
  }

  window.NirUpload = {post};
})();
