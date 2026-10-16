# Handoff de design

Interfața actuală este intenționat neutră. Logo-urile și machetele furnizate nu sunt
modificate și nu sunt copiate automat în storefront.

## Puncte de schimbare

- Tokeni: `public/assets/css/storefront/tokens.css`.
- Tipografie și reset: `base.css`; structură: `layout.css`.
- Componente: `components.css`; pagini: `pages.css`.
- Header/meniu/footer: `resources/views/storefront/layouts/base.twig` și `components/`.
- Homepage: `resources/views/storefront/pages/home.twig` și secțiunile CMS persistate.
- Card produs unic: `resources/views/storefront/components/product-card.twig`.
- Logo/favicon: setările `logo_media_id` și `favicon_media_id`; fallbackul este text.
- Fotografii: modulul Media produce derivate publice; template-urile consumă `srcset`/dimensiuni.

## Contracte vizuale stabile

Cardul primește `product` cu `name`, `slug`, `price_bani`, `compare_price_bani`,
`image`, `stock_state` și `badges`. Headerul primește meniul ierarhic deja filtrat.
Template-urile nu calculează preț, stoc sau eligibilitate SEO.

Comportamentul JS folosește `data-*` (`data-menu-toggle`, `data-cart-form`,
`data-variant-select`), astfel încât clasele CSS pot fi schimbate fără rescrierea logicii.
