const productPage = document.querySelector('[data-product-view-url]');

if (productPage) {
  const body = new URLSearchParams({ _csrf: productPage.dataset.productViewCsrf || '' });
  fetch(productPage.dataset.productViewUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
    body,
    credentials: 'same-origin',
    keepalive: true,
  }).catch(() => {});
}
