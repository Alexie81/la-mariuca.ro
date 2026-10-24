# Arhitectură

## Vedere generală

Aplicația este un monolit modular Slim 4. `public/index.php` construiește kernelul,
middleware-urile și rutele. Dependențele sunt create explicit de `AppFactory` și injectate
în controllere/servicii. Twig primește numai view-model-uri; accesul SQL rămâne în repository-uri.

## Module

- **Catalog/Content**: categorii ierarhice, produse, variante, colecții, meniu, CMS și SEO.
- **Inventory**: stoc fizic/rezervat, mișcări și operații condiționate.
- **Cart/Checkout/Orders**: coș server-side, totaluri, idempotency, snapshoturi și statusuri.
- **Payments/Shipping**: interfețe pentru furnizori, ramburs și adaptor Stripe opțional.
- **Customers**: autentificare, adrese, favorite și drepturi de acces.
- **Notifications**: outbox durabil și procesare CLI.
- **Media/Settings**: derivate publice validate și configurări editabile ne-secrete.

## Dependențe

`Checkout` orchestrează `Cart`, `Inventory`, `Orders`, `Coupons` și `Shipping` prin servicii.
`Catalog` nu depinde de comenzi; comenzile păstrează snapshoturi și referințe opționale.
Integrarea Stripe și SMTP se execută după commit, prin idempotency/outbox.

## Rute publice

`/`, `/magazin/`, `/categorie/{categorie}/{subcategorie}/...`, `/colectie/{slug}/`, `/produs/{slug}/`, `/cautare/`,
`/favorite/`, `/cos/`, `/finalizare-comanda/`, `/comanda/{token}/`, `/cont/`,
`/pagina/{slug}/`, `/sitemap.xml`, `/robots.txt` și `/stripe/webhook`.

## Administrare

`/admin/` și subrutele sale folosesc autentificare + autorizare pe rol și CSRF.
Acțiunile de comandă trec prin servicii de tranziție; nu există update arbitrar de status.

## Baza de date

Schema versionată include categorii, produse/variante/atribute, media, inventar,
coșuri, cupoane, comenzi, plăți, clienți, conținut, setări, redirecturi, outbox și audit.
SQLite este adaptorul local de test. MySQL/MariaDB InnoDB + utf8mb4 este ținta de producție.

URL-ul canonic al unei categorii conține toate slugurile din lanțul de părinți. Resolverul
validează întregul lanț publicat, iar vechile URL-uri plate ale subcategoriilor răspund cu 301.
