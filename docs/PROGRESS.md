# Progres

Actualizat: 2026-10-16

## VERIFIED

- Repository-ul inițial a fost inspectat; logo-urile sursă au rămas neatinse.
- PHP CLI 8.4.25 și extensiile locale necesare au fost identificate; wrapperul activează DLL-urile
  fără modificarea configurației globale.
- Composer a instalat lockfile-ul real fără `--ignore-platform-reqs`; auditul nu a raportat
  advisories la ultima rulare.
- Migrările SQLite `001` și `002` au fost aplicate pe baza locală; seed-ul demo încarcă 12 produse.
- Lint PHP: fără erori. Cele 29 de șabloane Twig compilează. PHPStan nivel 5: fără erori.
- PHPUnit: 16 teste, 39 de aserțiuni, toate trecute. Sunt acoperite slug/money, cicluri categorie,
  pagini/sitemap, redirecturi, preț server-side, idempotency, rezervări/anulare, cupoane,
  re-encodare media și TOTP/recovery.
- Smoke HTTP: `/`, categorie, produs, coș, checkout, sitemap și robots au răspuns 200.
- Smoke browser desktop/mobil: fără scroll orizontal la 390 px, meniu mobil funcțional, produs
  adăugat în coș, checkout ramburs finalizat, admin autentificat și dashboard afișat.
- Contul QA temporar a fost șters. Comanda demo `LM-2026-000001` rămâne în baza locală ca dovadă
  a smoke testului și nu conține date reale.

## IMPLEMENTED_UNVERIFIED

- Schema MySQL/MariaDB InnoDB/utf8mb4 și query-urile portabile; motorul real nu este disponibil local.
- Stripe Checkout hosted + webhook semnat/idempotent pentru confirmarea plății; lipsesc chei test.
- SMTP Symfony Mailer în producție și cron pentru outbox/expirări; local s-a verificat transportul
  privat prin fișiere, nu livrarea SMTP.
- Configurarea `APP_BASE_PATH` și `APP_ROOT` pentru shared hosting; Apache nu este disponibil în PATH.
- TOTP UI/admin, secret criptat și recovery codes; logica a fost testată automat, nu cu o aplicație
  Authenticator reală în browser.

## BLOCKED_EXTERNAL_CONFIG

- Versiunea PHP și extensiile hostingului, Apache `mod_rewrite`/`AllowOverride`, DocumentRoot,
  permisiunile, cron și structura `public_html`/`httpdocs`.
- MySQL/MariaDB real și testele cu două conexiuni concurente pentru ultima bucată/cupoane.
- Chei Stripe test/live, endpoint HTTPS public și configurarea webhookului.
- SMTP, identitatea expeditorului și politica de retry/monitorizare a hostingului.
- Date comerciant, taxe, tarife/zone curier, prag transport, politici juridice și retenție.
- Logo/favicon/fotografii finale, asocieri media și aprobarea designului.

## Incomplet față de specificația integrală

- UI complet CRUD pentru variante/atribute, filtre multi-atribut și ghiduri pe gamă;
- configurator de meniu/mega-menu și carduri promoționale;
- asociere media cu produse/categorii, seturi responsive `srcset` și focal point;
- verificare email/resetare parolă, adrese client, merge favorite și retrimitere token comandă guest;
- interfață admin pentru clienți, atribute, livrare, plăți și setări sensibile pe roluri;
- import CSV cu scriere (există export și dry-run/reimport validation, scrierea este blocată explicit);
- refund sync Stripe, reconciliere completă a evenimentelor out-of-order și plăți tardive;
- stoc retur după recepție, cupoane editabile/arhivabile și colecții dinamice calculate automat;
- consimțământ cookies/analytics complet, export/ștergere date și politică de retenție aplicată;
- suită completă de acceptanță cerută, inclusiv upload malițios HTTP, reset token și SMTP failure.

## Următorul pas exact

Configurează un MySQL/MariaDB local de test și rulează instalarea din bază goală, apoi adaugă testul
concurent cu două conexiuni pentru ultima bucată. După aceea se poate continua UI-ul de variante,
atribute și filtre fără schimbarea contractelor existente.
