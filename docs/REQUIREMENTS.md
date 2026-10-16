# Cerințe — La Măriuca Boutique

Acest document păstrează cerințele complete, normalizate, din specificația furnizată de beneficiar.
Starea implementării este separată în `docs/PROGRESS.md`; prezența unei cerințe aici nu înseamnă
că a fost deja implementată sau verificată.

## Produs și piață

- Magazin online pentru copii de la naștere la 8 ani, în limba română, moneda RON.
- Catalog extensibil: nou-născuți, fetițe, băieți, botez, trusouri, încălțăminte și accesorii.
- Fără categorii pentru adulți în versiunea curentă.
- Date persistate în UTC și afișate în fusul `Europe/Bucharest`.
- Domeniul, URL-ul și instalarea într-un subdirector sunt configurabile.

## Platformă

- PHP suportat, Slim 4, Twig 3, PDO, MySQL/MariaDB, Apache și shared hosting.
- HTML server-rendered, CSS modular și JavaScript vanilla progresiv.
- Monolit modular; fără SPA, microservicii sau infrastructură permanentă suplimentară.
- Webroot exclusiv `public/`; secretele și originalele media rămân private.

## Fluxuri obligatorii

- Categorii ierarhice, produse, variante, atribute, media, inventar și conținut editabil.
- URL permanent și pagină HTML proprie pentru fiecare produs/categorie/colecție publicată.
- Căutare, filtre, favorite, coș server-side și checkout fără cont.
- Comenzi idempotente, rezervări atomice de stoc, istoric și tranziții controlate.
- Ramburs funcțional; Stripe hosted opțional și dezactivat până la configurare validă.
- Conturi, roluri admin/operator, TOTP, resetare parole și acces sigur la comenzi.
- Outbox email, cron/CLI, cupoane, CSV dry-run/import/export și CMS.
- SEO tehnic, sitemap, canonical, redirecturi de slug și indexare controlată.

## Calitate și limite

- Sume în bani întregi, fără `float`; calcule și rotunjiri deterministe.
- Validare server-side, CSRF, autorizare, rate limiting, CSP și uploaduri re-encodate.
- Teste unitare/integrări pentru SEO, checkout, stoc, concurență, webhookuri și securitate.
- Designul și fotografiile finale sunt un handoff ulterior. Interfața curentă rămâne neutră.
- Pagini juridice sunt drafturi de verificat; nicio afirmație de conformitate garantată.
- Nu se efectuează deployment de producție, plăți live sau conectări externe fără aprobare.

## Arhitectură și separarea responsabilităților

- Monolit modular cu o aplicație și o bază: Shared, Catalog, Inventory, Cart, Checkout, Orders,
  Payments, Shipping, Customers, Content, Media, Notifications și Settings.
- Controllerul adaptează HTTP; serviciul conține reguli comerciale; repository-ul deține SQL;
  template-ul nu interoghează baza și nu calculează prețuri, taxe sau stocuri.
- Adminul și storefront-ul folosesc aceleași servicii. Dependențele sunt injectate explicit;
  nu există variabile globale, service locator ascuns, SQL duplicat în checkout sau clase goale.
- Schema definește chei externe, unicitate și indici pentru catalog, variante, atribute, media,
  inventar, rezervări, coșuri, cupoane, comenzi, plăți, clienți, conținut, outbox și audit.
- CSS este separat în tokens/base/layout/componente/pagini; adminul are stiluri separate.
  Comportamentul JS folosește `data-*`, iar conținutul principal funcționează fără JavaScript.

## Taxonomie, meniu și colecții

- Categorii ierarhice cu părinte, nume, slug unic, descriere, imagine, ordine, stare,
  vizibilitate și SEO. Se previn ciclurile și mutarea sub un descendent.
- Produsele pot aparține mai multor categorii și au una principală pentru breadcrumbs; stocul nu
  se duplică. Arhivarea păstrează istoricul.
- Seed editabil: Nou-născuți (seturi, body-uri, salopete, compleuri, pijamale, bonete, botoșei),
  Fetițe (rochițe zi/ocazie, seturi, bluze, fuste, pantaloni, treninguri, pulovere, geci, pijamale),
  Băieți (costumașe, seturi, cămăși, tricouri, pantaloni, treninguri, pulovere, geci, pijamale),
  Botez (rochii/costumașe, trusouri, lumânări, cutii, accesorii), Încălțăminte și Accesorii.
- Denumirile repetate nu obligă duplicarea categoriei; meniul este separat de taxonomie și poate
  avea mai multe linkuri către aceeași destinație.
- Noutăți/Reduceri sunt colecții dinamice bazate pe reguli; colecțiile editoriale sunt manuale.
- Mega-menu configurabil: categorii, coloane, maximum două promo-uri; desktop hover/click/tastatură
  și Escape; mobil drawer pe niveluri cu back/focus. Categoriile fără ofertă nu apar automat.
- Vârsta, mărimea, culoarea și materialul sunt atribute/filtre, nu ramuri obligatorii în arbore.

## Pagini și SEO

- Rute: `/`, `/categorie/{slug}/`, `/colectie/{slug}/`, `/produs/{slug}/`, `/cautare/`,
  `/favorite/`, `/cos/`, `/finalizare-comanda/`, `/cont/`, `/admin/` și pagini CMS.
- Fiecare entitate publicată/indexabilă are URL permanent, HTTP 200, H1 și conținut specific în
  HTML, canonical propriu, linkuri reale, breadcrumbs și sitemap. Subcategoria nu este doar filtru.
- Publicarea din admin nu necesită cod. Părinții pot agrega descendenții deduplicat și eficient.
- Sluguri fără diacritice; schimbarea produce 301 fără bucle. URL-urile respectă `APP_URL` și
  `APP_BASE_PATH`. Un produs are un singur URL principal, indiferent de categorii.
- Metadate, Open Graph, Product/BreadcrumbList din date reale; fără rating/review/ofertă inventată.
- Sitemap cu `lastmod` real; separat pe tip la volum mare. Draftul este privat/404. Categoria fără
  ofertă utilă primește noindex și nu intră în sitemap. Stocul zero nu șterge produsul.
- Produsele retrase au politică 404/410/redirect; căutarea, contul, coșul, checkoutul și adminul sunt
  noindex. Filtrele nu creează infinit URL-uri; paginarea are linkuri/canonical corecte.
- Local/staging rămân neindexabile. Lansarea include verificare explicită; nu se promit poziții.

## Produse, variante, prețuri și personalizare

- Admin produse: creare/editare, căutare, filtre, paginare, duplicare draft, publicare și arhivare.
  Câmpuri: texte, categorii, brand opțional, galerie, SEO, mărimi, compoziție, întreținere,
  specificații și FAQ opțional.
- Produsul simplu are variantă implicită; coșul folosește mereu `variant_id`. Varianta are SKU unic,
  EAN text opțional, opțiuni, preț, greutate/imagine opționale și stare.
- Atributele/valorile sunt configurabile; combinațiile duplicate sunt respinse. Atributele de
  filtrare nu sunt obligatoriu variante; vârsta nu este mapată universal la mărime.
- Draftul poate fi incomplet, dar publicarea/vânzarea au validări. Lipsa nu devine zero implicit.
- Sumele sunt bani întregi; procentele și rotunjirile sunt deterministe. Taxele și afișarea sunt
  configurabile și necesită confirmare. Istoricul prețurilor/reducerilor este real, nu inventat.
- Fără stoc negativ sau nelimitat; precomanda/producția la comandă au reguli distincte.
- Trusourile au conținut structurat și accesorii neincluse. Setul vândut are SKU/stoc propriu;
  bundle component-level este în afara primei versiuni.
- Personalizare text/select/checkbox cu allowlist, max length, required și supliment. Serverul
  validează/calculează; opțiuni diferite sunt linii distincte și devin snapshot în comandă.

## Media, storefront, homepage și CMS

- Upload JPG/PNG/WebP; AVIF numai dacă mediul permite. Se verifică MIME real, decodare, dimensiune,
  pixeli; se re-encodează și se produc derivate. Originalul este privat, publicul nu execută fișiere.
- Fără SVG arbitrar, PHP/HTML, extensii duble, hotlink/import URL. Imaginile au alt, dimensiuni,
  `srcset` și lazy loading sub primul ecran; imaginea principală nu este întârziată inutil.
- Storefront: homepage, categorie/colecție/produs, search, favorite, cart, checkout, confirmare,
  cont și informații. Filtrele trebuie să corespundă aceleiași variante eligibile.
- Căutare după nume/SKU/categorie, strategie diacritice, sugestii cu debounce și rate limiting.
- Favorite sesiune pentru guest, persistente pentru cont, merge fără duplicate la autentificare.
- Homepage folosește secțiuni predefinite: hero max. 3, categorii, produse, banner, editorial, FAQ;
  activare, ordine, texte, imagini, linkuri validate, desktop/mobil și focal point opțional.
- Fără bestseller/statistici/review/certificări/promisiuni nefondate.
- CMS: Despre, Contact, Livrare/retur, Termeni, Confidențialitate, Cookies, Ghid mărimi. Juridicele
  sunt drafturi marcate pentru verificare. Datele comerciantului/logo/favicon sunt editabile;
  secretele nu apar în admin sau frontend.

## Coș, cupoane, checkout și comenzi

- Coș server-side cu add/update/remove/persistență. Browserul nu este sursa prețului, discountului,
  taxei, transportului sau stocului.
- Checkout guest: contact, livrare, facturare separată opțională, persoană/firma, metode și sumar.
  Fără CNP implicit; toate costurile apar înainte; butonul indică obligația de plată.
- Acceptarea termenilor este separată de marketing; marketingul nu este prebifat/obligatoriu.
- Cupoane procent/fix: perioadă, prag, eligibilitate, limite, fără cumul implicit; utilizarea este
  rezervată/confirmată/eliberată concurent. Ordinea calculului și rotunjirea sunt documentate.
- Plasarea este idempotentă; retry/refresh/dublu-click nu dublează comanda sau rezervarea.
- Inventarul ține fizic, rezervat, disponibil și jurnal. Checkoutul rezervă atomic; rambursul ține
  rezervarea până la expediere/anulare; cardul o corelează cu sesiunea de plată.
- Expedierea scade fizic+rezervat exact o dată; anularea eliberează exact o dată; returul restochează
  numai după recepție confirmată, nu automat la refund.
- Nu există tranzacție DB deschisă în apel Stripe/SMTP. Webhookul întârziat nu eliberează singur
  rezervarea; plățile tardive/ambigue intră în verificare manuală.
- Comanda păstrează snapshot complet pentru produse, SKU, prețuri, taxe, discount, personalizare,
  transport și adrese. Starea comercială/logistică este separată de plata efectivă.
- Statusuri comandă: nouă, confirmată, pregătire, expediată, livrată, anulată, retur; plăți:
  pending, paid, failed, partial/full refunded. Tranzițiile sunt validate și auditate.
- Admin comenzi: listă/filtre/search/detalii/note/istoric/acțiuni/AWB manual. Documentul HTML de
  pregătire nu este factură fiscală.

## Plăți și livrare

- Ramburs și Stripe Checkout hosted. Stripe este dezactivat fără configurație validă; secretele vin
  din mediu, aplicația nu stochează date de card.
- Sesiunea folosește totalul serverului și idempotency. Webhookul verifică semnătura pe corpul brut,
  mediul, moneda, suma și identitatea; persistă evenimentul înainte de procesare și este idempotent.
- Plata nu se confirmă din redirect/browser. Evenimentele vechi nu regresează plata. Operatorul nu
  falsifică plata Stripe. Refundul se inițiază în dashboard și se sincronizează real, inclusiv parțial.
- Fără chei se testează numai provider simulat; fără plăți/webhook live sau schimbări externe.
- Livrare manuală: zone, tarife, prag gratuit, tracking/AWB admin; recalcul server-side.
  `ShippingProvider` este extensie pentru curier; nu se inventează integrări/AWB automate.

## Clienți, administrare, email și CLI

- Înregistrare, verificare email, login/logout, resetare, adrese, favorite, comenzi proprii.
  Comenzile guest nu se asociază după email neverificat. Acces guest prin token sigur/expirabil.
- Admin separat, roluri administrator/operator, autorizare per acțiune. Operatorul nu gestionează
  secrete sau privilegii. Meniu: dashboard, catalog, atribute, stoc, comenzi, clienți, cupoane,
  homepage, meniu, media, pagini, livrare, plăți, setări.
- Dashboard numai cu definiții reale; comenzile nu sunt etichetate profit/încasări.
- Administrator creat prin CLI, fără cont implicit/parolă în log; rate limiting și TOTP cu recovery.
- Mailer întreținut și SMTP configurabil. Outboxul se scrie în aceeași tranzacție cu operația;
  eșecul SMTP nu pierde comanda. Procesare CLI/cron cu retry/backoff/lock și erori vizibile.
- Dev folosește transport local privat și nu trimite accidental emailuri reale; logurile redactează.
- CLI pentru migrări, seed, admin, outbox, plăți/reconciliere, expirări, cache și cron.

## CSV, securitate, performanță și accesibilitate

- CSV UTF-8 compatibil Excel, separator documentat, exemplu; un rând/variantă, SKU și părinte
  explicit. Preview/dry-run, erori per rând, create/update, reimport idempotent, fără autopublish.
- Golul nu șterge implicit; SKU/EAN sunt text; exportul neutralizează formule. Stocul trece prin
  InventoryService și nu coboară sub rezervări.
- Validare server, prepared statements, allowlist SQL dinamic, autoescape și HTML sanitizat.
- CSRF pentru mutații, exceptând webhookul semnat; fără mutații GET. Password hash, tokenuri CSPRNG,
  sesiuni stricte/regenerate, cookie HttpOnly/SameSite/Secure și reset token hash/one-time/expirabil.
- Rate limit login/reset/search/checkout, erori nedivulgative, autorizare obiect, upload sigur,
  secrete private, debug off, log privat, ID eroare, CSP/headere și proxy/Host trust explicit.
- Analytics/marketing off implicit; accept/refuz/retragere, nimic neesențial înainte de consimțământ.
- Date minime, acces/export/retenție documentate; niciun banner nu certifică conformitatea.
- Paginare/indexuri/fără N+1; cache numai public și invalidat, niciodată global pentru cont/cart.
- Responsive ~360/390/768/1280/1440 fără overflow; semantic, labels, focus, erori asociate,
  contrast și tastatură/touch.

## Migrări, seed, teste și livrare

- Migrări versionate cu indici/constrângeri și recuperare; DB de test separată și protecție producție.
- Seed explicit 10–15 produse: simplu, variante, trusou/personalizare, stoc zero, fără clienți/review
  fictive prezentate ca reale.
- Teste pentru categorii/meniu, URL+canonical+sitemap, draft/noindex/redirect/base path, produs în
  categorii multiple, variante/filtre, manipulare preț/cantitate, personalizare snapshot,
  double-submit, concurență ultima bucată, expediere/anulare repetată, webhook duplicat/tardiv/invalid,
  cupoane/taxe/rotunjire/transport, auth/CSRF/reset, upload, SMTP/outbox și CSV.
- Concurența se testează pe motorul țintă, nu doar SQLite/mock. Smoke complet admin→catalog→public→
  variantă→cart→COD checkout→admin→ship/cancel→stock, plus instalare goală și mobil.
- Se separă clar verificarea HTTP, vizuală, locală și externă.
- Documentație localhost + shared hosting cu `public/` unic webroot, inclusiv hosting fără SSH/
  Composer, vendor pregătit, SQL inițial privat, migrări ulterioare, VirtualHost/.htaccess,
  permisiuni minime, HTTPS/cookies/cache/cron/SMTP/Stripe, backup/restore și launch checklist.
- Livrarea finală raportează ce funcționează, pornirea, adminul sigur, verificările reale,
  incompletele/blocajele și fișierele pentru design/navbar/fotografii. Fără afirmație production-ready
  până când verificările critice sunt completate.
