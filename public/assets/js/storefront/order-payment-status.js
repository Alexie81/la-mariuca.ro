// The return query only starts a read-only watch. Payment truth always comes from the server.
const result = document.querySelector('[data-order-result="card_pending"]');
if (result && new URL(window.location.href).searchParams.get('plata') === 'verificare') {
  const status = document.querySelector('[data-order-payment-watch]');
  const originalMessage = status?.textContent;
  const signature = (element) => [element.dataset.orderStatus, element.dataset.paymentStatus, element.dataset.paymentRecordStatus].join(':');
  const initialSignature = signature(result);
  const deadline = Date.now() + 60000;
  let timer;
  let controller;
  let stopped = false;
  const stop = () => {
    stopped = true;
    clearTimeout(timer);
    controller?.abort();
    if (status) status.textContent = originalMessage;
  };
  const check = async () => {
    if (stopped || Date.now() >= deadline) { stop(); return; }
    if (document.visibilityState === 'hidden') { timer = setTimeout(check, 3000); return; }
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch(window.location.href, {
        credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        referrerPolicy: 'no-referrer', signal: controller.signal, headers: { Accept: 'text/html' },
      });
      if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) return;
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updated = page.querySelector('[data-order-result]');
      if (!stopped && updated && signature(updated) !== initialSignature) {
        stop();
        window.location.reload();
        return;
      }
    } catch {
      // Network failures leave the existing manual refresh available.
    } finally {
      clearTimeout(timeout);
      if (!stopped) timer = setTimeout(check, 3000);
    }
  };
  if (status) status.textContent = 'Verificăm automat confirmarea plății. Pagina se actualizează când primim răspunsul. Nu este nevoie să plasezi încă o comandă.';
  timer = setTimeout(check, 1500);
  window.addEventListener('pagehide', stop, { once: true });
}
