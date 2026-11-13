# Instalare și deployment

## Stare de compatibilitate

Verificat local la 16 octombrie 2026:

- PHP CLI 8.4.25;
- extensii folosite: ctype, curl, DOM/XML, fileinfo, GD, JSON, mbstring, OpenSSL, PDO,
  pdo_sqlite, pdo_mysql, session, sodium și zip (zip este necesar numai la instalarea Composer);
- Composer 2 local (`composer.phar`) și lockfile instalat fără ignorarea cerințelor;
- SQLite pentru dezvoltare/teste; serverul PHP integrat pentru smoke test HTTP.

Neverificat extern: PHP-ul Apache, `mod_rewrite`, `AllowOverride`, MySQL/MariaDB, structura reală
`public_html`/`httpdocs`, permisiunile, cron, SMTP pe hosting, Stripe live și backupul hostingului.
Stripe TEST a fost verificat local cu o plată sandbox și webhook semnat; aceasta nu verifică mediul de producție.
Ținta provizorie este PHP 8.4. Confirmă versiunea și extensiile hostingului înainte de upload.

## A. Localhost Apache / XAMPP / WAMP / Laragon

1. Instalează PHP 8.4 cu extensiile din secțiunea anterioară, Composer 2 și MySQL/MariaDB.
2. Creează o bază `utf8mb4` și un utilizator cu drepturi numai pe acea bază.
3. Copiază `.env.example` în `.env`; setează `DB_DRIVER=mysql`, conexiunea, `APP_URL`,
   `APP_BASE_PATH`, `APP_ENV=development`, `APP_DEBUG=true`, `APP_INDEXING=false`.
4. Rulează `php bin/generate-key.php`, `php composer.phar install`,
   `php bin/console.php migrate` și opțional `php bin/console.php seed:demo`.
5. Configurează DocumentRoot către directorul absolut `public/`.

VirtualHost orientativ (căile se adaptează):

```apache
<VirtualHost *:80>
    ServerName la-mariuca.test
    DocumentRoot "D:/cale/la-mariuca.ro/public"
    <Directory "D:/cale/la-mariuca.ro/public">
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>
</VirtualHost>
```

Pentru instalare în subdirector setează, de exemplu, `APP_URL=http://localhost`,
`APP_BASE_PATH=/la-mariuca` și mapează URL-ul exclusiv către `public/`. Nu expune rădăcina repo-ului.

## B. Apache shared hosting

### DocumentRoot configurabil (preferat)

Urcă proiectul prin SFTP/FTP, păstrează `.env`, `app`, `config`, `database`, `storage` și `vendor`
în afara zonei publice, apoi setează domeniul către `<proiect>/public`.

### Webroot fix `public_html` / `httpdocs`

Păstrează rădăcina privată, de exemplu `/home/cont/private/la-mariuca`, și copiază numai
conținutul `public/` în `/home/cont/public_html`. Definește în configurația Apache/hosting:

```apache
SetEnv APP_ROOT /home/cont/private/la-mariuca
```

`public/index.php` citește `APP_ROOT` înainte de autoload. Dacă hostingul interzice `SetEnv`, cere
suportului o variabilă de mediu sau un DocumentRoot sigur. Nu publica întregul repository ca soluție.

## Hosting fără Composer sau SSH

1. Rulează local `php composer.phar install --no-dev --optimize-autoloader` pe aceeași versiune
   PHP și cu aceleași extensii ca hostingul. Nu folosi `--ignore-platform-reqs`.
2. Generează schema pe o bază MySQL goală prin `bin/console.php migrate`, apoi exportă schema SQL
   din motorul țintă și import-o cu phpMyAdmin. Nu importa peste o bază reală existentă.
3. Creează administratorul local cu parola aleasă de proprietar și transferă doar hashul printr-o
   procedură privată. Nu include conturi implicite în pachet.
4. Urcă `vendor/` împreună cu sursele private și numai `public/` în webroot.
5. Pentru actualizări, aplică numai migrările noi într-un backup/restaurare de test înainte de live.

## Permisiuni și secrete

- Fișiere: numai citire pentru utilizatorul web unde este posibil; directoarele runtime primesc
  minimul necesar pentru scriere. Nu folosi `chmod 777`.
- Scriere necesară: `storage/cache`, `storage/logs`, `storage/private`, `public/media`.
- `.env` și cheia aplicației nu se trimit în Git și nu se pun în webroot.
- În producție: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`, HTTPS obligatoriu.
- Configurează `TRUSTED_HOSTS`; nu acorda automat încredere headerelor proxy.

## Cron, email și rezervări

Comenzi recomandate (căile absolute și lockul cron se adaptează hostingului):

```text
*/5 * * * * php /cale/bin/console.php outbox:process
* * * * * php /cale/bin/console.php push:process 100
*/10 * * * * php /cale/bin/console.php reservations:expire
```

Nu expune aceste comenzi ca URL public. Dacă hostingul nu oferă cron, outbox-ul, reîncercările
Web Push și expirarea rezervărilor sunt `BLOCKED_EXTERNAL_CONFIG`; comenzile trebuie rulate controlat.

În dezvoltare, outbox-ul scrie preview-uri în `storage/private/mail`. În producție, setează un
`MAILER_DSN` SMTP real și testează retry/backoff fără date reale de client.

Pe server, setează explicit `APP_URL=https://la-mariuca.ro` și `APP_BASE_PATH=` (gol pentru
instalarea la rădăcina domeniului). Toate butoanele magazinului, imaginile și linkurile din
emailuri se construiesc din această configurație, inclusiv linkurile private de urmărire
și CTA-urile interne către comenzi/solicitări. Nu se deduce domeniul din headerul cererii.
În producție, procesorul SMTP refuză o adresă locală, fără HTTPS sau invalidă înainte de a
prelua mesaje; corectarea configurației permite reluarea fără consumarea încercărilor.
După schimbarea domeniului, repornește workerul persistent. Păstrează `APP_KEY` și datele
comenzilor: linkurile private deja emise depind de acestea. Preview-urile locale folosesc
intenționat localhost; nu se publică și nu se transferă fișierele QA pe hosting.

Notificările push interne deschid comanda pe originea HTTPS a magazinului pe care a fost
înregistrat dispozitivul. După mutarea pe domeniul live, autentifică fiecare dispozitiv intern
în acel admin și activează notificările acolo; permisiunile și abonamentele browserului local
nu se transferă automat pe domeniul public. Păstrează cheile VAPID ale magazinului privat și
activează cronul `push:process` pentru reîncercări. Testează deschiderea unei comenzi din push
și din email într-o sesiune admin neautentificată, cu revenire după login/TOTP.

Pentru o listă explicită `MAIL_MESSAGE_TYPES`, notificările cererilor de anulare folosesc
`order_cancellation_received` (confirmarea clientului), `internal_order_cancellation`
(adresele interne salvate în Comenzi) și `order_cancellation_decision` (acceptare/respingere
către client). Păstrează și tipurile deja activate pentru comenzile noi. Modificarea listei
necesită repornirea procesorului persistent; nu reseta pragul `MAIL_SEND_AFTER_ID`.
Migrarea 040 trebuie aplicată înainte de utilizarea noului flux. Nu se retrimit automat
cererile istorice și o listă internă goală nu blochează confirmarea clientului.

Acceptarea cererii anulează comanda și eliberează rezervările; nu inițiază o rambursare Stripe
și nu emite documente fiscale. Plata rămâne în starea reală existentă până la un flux separat.

## Stripe

Stripe este dezactivat implicit în configurația distribuită. Configurația locală autorizată folosește exclusiv TEST, cu Checkout găzduit de Stripe și brandingul salvat în cont.

1. Aplică migrările 043–046 (legătura sesiunii de plată, coada catalogului, costurile operaționale și încercările de plată separate de comenzi).
2. Setează privat `STRIPE_ENABLED=true`, `STRIPE_MODE=test`, `STRIPE_SECRET_KEY` și `STRIPE_PUBLISHABLE_KEY` de test. Activează cardul din Metode de plată. Regulile categoriilor continuă să se aplice.
3. Local, instalează Stripe CLI din sursa oficială în `storage/private/stripe-cli/stripe.exe`, setează `STRIPE_WEBHOOK_SECRET_FILE=storage/private/stripe-webhook-secret` și pornește `powershell -File bin/start-stripe-listener.ps1`. Scriptul acceptă doar localhost și chei test, pornește ascuns și păstrează semnătura privată. Trebuie repornit după repornirea calculatorului. Nu publica acest fișier.
4. Pentru un mediu HTTPS autorizat, înregistrează `/stripe/webhook` și evenimentele `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.canceled`. Secretul endpointului Dashboard este diferit de secretul CLI; folosește `STRIPE_WEBHOOK_SECRET` privat.
5. Testează semnătura, duplicatele, suma, moneda, evenimentele tardive și reconcilierea. Datele cardului sunt introduse doar la Stripe. Emailul și adresele din comandă sunt trimise în Customer și shipping; Stripe poate solicita numele titularului, țara sau autentificarea băncii.
6. `automatic_tax` și codurile promoționale suplimentare Stripe sunt dezactivate. Sumele, reducerile și costurile operaționale sunt snapshoturi calculate pe server în bani. Totalul Stripe trebuie să fie identic cu cel al comenzii. Costurile se administrează separat pe metodă, dar trebuie să reprezinte servicii reale, comunicate înainte de comandă.

Catalog: `STRIPE_CATALOG_ENABLED=true`, apoi `php bin/stripe-catalog.php sync-all 200` pentru catalogul inițial. Rulează permanent `php bin/stripe-catalog.php work 20` sau prin cron `process 20`; pe Windows local există `bin/start-stripe-catalog.ps1`. Salvarea/ștergerea produselor pune modificările în coadă în tranzacție, iar workerul transmite separat, cu retry. Ștergerea arhivează produsele/prețurile Stripe pentru a păstra istoricul. Mappingurile sunt separate după cont și test/live. Checkout folosește snapshoturi ale produselor din comandă; când există cupon, produsele de marfă primesc ID-uri stabile după conținut, iar cuponul se aplică exclusiv acestora. Livrarea este trimisă la valoarea netă, cu economia descrisă; costurile operaționale nu sunt reduse prin repartizarea cuponului. Snapshoturile pot genera produse suplimentare în Stripe, fără a modifica produsele istorice.

Imaginile necesită URL-uri HTTPS accesibile public de Stripe. Localhost nu este accesibil din Stripe. Numai în TEST/dezvoltare se poate configura `STRIPE_PUBLIC_MEDIA_BASE_URL` către un host autorizat care servește aceleași căi publice. Pentru imaginile existente folosim temporar fișierele deja publice din repository; o imagine nouă locală trebuie să fie disponibilă și la acea sursă pentru a apărea în Stripe. În producție această suprascriere de test se ignoră și se folosește `APP_URL` + `APP_BASE_PATH`. Nu activa un tunel sau publicarea automată pentru a ocoli această limitare.

Trecerea LIVE necesită aprobare explicită, `APP_ENV=production`, `STRIPE_MODE=live`, cheile live, secretul endpointului HTTPS live și domeniul public corect. Nu copia `.env` local, baza QA ori listenerul CLI în producție. Reia sincronizarea catalogului pentru contul/modul live, configurează workerul, verifică brandingul live, emailurile și push-ul, apoi efectuează un checkout real autorizat. Credențialele de mod diferit sunt respinse. Nu considera migrarea MySQL, livrarea pe hosting ori Stripe live verificate prin testele SQLite/sandbox.

Redirectul browserului nu marchează plata ca achitată. Numai webhookul semnat actualizează plata.
Pentru checkouturile noi, înainte de plată există numai o încercare privată în `checkout_attempts`, cu snapshot și rezervări; nu există încă o comandă în `orders`. Rambursul creează o comandă `new/pending` la plasare. Webhookul Stripe valid creează o singură comandă `confirmed/paid` și pune notificările în coadă. O plată tardivă cu probleme de stoc sau un al doilea debit real pentru același coș intră plătită în `manual_review` pentru reconciliere, fără pierderea tranzacției.

Refuzul recuperabil rămâne în pagina Stripe pentru reîncercare. Revenirea prin `cancel_url` este doar citire; nu eliberează rezervări cât timp sesiunea poate fi plătită. „Reia plata” refolosește sesiunea deschisă. „Renunță la plată” confirmă întâi expirarea la Stripe, apoi eliberează rezervările și revine la checkout cu produsele și datele completate. La expirare/eșec nu se creează comenzi sau emailuri de confirmare; coșul rămâne activ. CSP permite redirecționarea formularelor doar către originile Checkout Stripe exacte, fără wildcard.

Configurează un cron cu lock, la fiecare 5 minute: `php /cale/bin/reconcile-stripe-attempts.php 20`. Acesta recuperează rezervările abandonate după expirare plus 5 minute, numai după verificarea procesatorului. La timeout fără ID de sesiune, caută sesiunea folosind metadata și amprenta configurației private; o schimbare de cheie, o scanare incompletă sau o eroare API nu eliberează stocul. Urmărește rezultatele `manual_review`, `failed` și `waiting_webhook`: ultima stare necesită recuperarea/livrarea webhookului semnat, nu confirmarea prin URL. Nu folosi `reservations:expire` pentru a forța eliberarea încercărilor Stripe. Cronul este pregătit, dar trebuie configurat și verificat pe hosting.

Rambursările se inițiază în dashboardul Stripe în această versiune; sincronizarea completă a
rambursărilor parțiale este încă de implementat.

### Checklist obligatoriu înainte de upload / activare Stripe live

- [ ] Acord explicit pentru publicare și activarea plăților live; testele sandbox nu înlocuiesc această aprobare.
- [ ] `APP_URL=https://la-mariuca.ro`, `APP_BASE_PATH` corect, `APP_ENV=production`, `SESSION_SECURE=true`; fără fișiere QA/private în webroot.
- [ ] Chei live și secret webhook live în configurația privată; niciun secret local/CLI copiat automat. Stripe Tax rămâne oprit, conform alegerii utilizatorului.
- [ ] Migrări MySQL aplicate și verificate; cron/worker catalog, email și push funcționale pe hosting, apoi resincronizarea inițială a catalogului LIVE.
- [ ] Imaginile produselor sunt accesibile anonim prin HTTPS pe domeniul public (răspuns 200, tip imagine), fără dependență de sursa temporară GitHub. Verificare la adăugare, înlocuire și eliminare de imagine, editare produs și arhivare/ștergere produs.
- [ ] O sesiune nouă Checkout arată numele, fotografiile, SKU/variantele și cantitățile corecte, pe telefon și desktop. Sesiunile vechi păstrează snapshotul comenzii.
- [ ] Emailul și adresele se preiau din comandă; Stripe nu cere din nou livrarea. Verificăm separat numele titularului și fluxul 3DS al băncii.
- [ ] Subtotalul, reducerile, livrarea gratuită/redusă și fiecare cost operațional corespund comenzii; totalul este egal la ban. Cuponul nu reduce accidental costurile operaționale.
- [ ] Regulile categoriilor și fallbackul card/ramburs global funcționează; costurile se recalculează pentru metoda efectiv aleasă.
- [ ] Checkout real autorizat: succes, anulare, eșec, 3DS, webhook semnat, retry/duplicate și plată întârziată. Redirectul singur nu confirmă plata.
- [ ] Încercările anulate/refuzate nu creează comenzi; coșul și datele rămân disponibile. Verifică „Reia plata”, cronul de reconciliere și notificarea unică după confirmare, inclusiv cu webhook întârziat.
- [ ] Clientul și destinatarii interni primesc notificările corecte o singură dată, cu CTA-uri/imagine pe domeniul public; dispozitivele push sunt înregistrate pe originea live.
- [ ] Reverificarea fiscală și a regulilor de rambursare pentru servicii/costuri este încheiată înaintea lansării. Nu declarăm verificarea live completă până la executarea efectivă a pașilor.

## Autentificare Google în producție

Checklistul operațional complet și persistent este în
[`docs/GOOGLE_OAUTH_CHECKLIST.md`](GOOGLE_OAUTH_CHECKLIST.md) și trebuie parcurs înainte de activare.

Același client Web Google deservește localhost și producția; callback-ul este construit exclusiv
din `APP_URL`, deci nu există URL de producție hardcodat în cod. Pentru hosting:

1. Urcă separat JSON-ul descărcat din Google Cloud în
   `<proiect>/storage/private/google-oauth-client.json`. Fișierul este ignorat de Git și trebuie să
   rămână în afara `public/`, `public_html` sau `httpdocs`.
2. Configurează în `.env` de pe server:

```dotenv
APP_ENV=production
APP_URL=https://la-mariuca.ro
APP_BASE_PATH=
GOOGLE_OAUTH_ENABLED=true
GOOGLE_CREDENTIALS_FILE=storage/private/google-oauth-client.json
```

3. Callback-ul rezultat va fi exact
   `https://la-mariuca.ro/cont/google/retur/`, deja înregistrat în clientul Google. Dacă se folosește
   un subdirector sau alt domeniu canonic, actualizează simultan `APP_URL`/`APP_BASE_PATH` și clientul
   Google; Google cere potrivire exactă.
4. JSON-ul poate fi omis numai dacă `GOOGLE_CLIENT_ID` și `GOOGLE_CLIENT_SECRET` sunt furnizate direct
   ca variabile private ale hostingului. Nu configura ambele metode cu valori diferite.

În `APP_ENV=production`, dacă `GOOGLE_CREDENTIALS_FILE` este omis dar fișierul privat standard există,
aplicația îl detectează automat. Activarea eșuează închis dacă lipsesc credențialele; secretul nu este
afișat în pagini, loguri sau redirecturi.

## Taxe, livrare și indexare

- Confirmă statutul fiscal, cota și afișarea prețurilor înainte de lansare; valorile demo nu sunt
  declarații fiscale.
- Confirmă tariful, pragul gratuit, zonele și contractul curierului. AWB-ul este manual.
- Păstrează `APP_INDEXING=false` pe local/staging. La lansare verifică manual paginile juridice,
  oferta utilă a categoriilor, canonical, sitemap și robots înainte de `true`.

## Backup și restaurare

1. Backup consistent DB (dump tranzacțional InnoDB) și copie separată pentru originalele private și
   derivatele media.
2. Criptează backupul, limitează accesul și stabilește retenția cu proprietarul.
3. Restaurează într-un mediu separat, cu domeniu neindexabil și email/Stripe dezactivate.
4. Rulează migrările lipsă, verifică totalurile/stocurile și deschide produse/media/comenzi.
5. Un backup este considerat valid numai după o restaurare testată.

## Checklist înainte de producție

### Recepții NIR și fișiere private

- Aplică migrarea `047_nir_receipts.sql` pe o copie MySQL înaintea bazei live; verifică tranzacțiile, blocarea concurentă, `row_version`, idempotency și reconcilierea FIFO.
- Verifică limitele PHP efective: `max_input_vars=60000`, `upload_max_filesize=0`, `post_max_size=0`, `max_file_uploads=30`. `public/.user.ini` se aplică PHP-FPM/CGI; pentru alte configurații folosește panoul hostingului/php.ini. Pentru atașamentele NIR nu mai există plafonul de 15 MB; valorile 0 elimină plafonul PHP pentru încărcările multipart. Aliniază și limita de request a proxyului/webserverului, timpul de încărcare și spațiul temporar disponibil. Testează efectiv un fișier mai mare de 32 MB, descărcarea lui cu același SHA-256 și mai multe fișiere. Protecțiile pentru extragerea automată XML/XLSX rămân separate de stocarea originalelor. Nu considera hostingul verificat numai pe baza testelor locale.
- Păstrează `storage/private/nir-documents/` și exporturile temporare în afara webrootului, cu permisiuni private și spațiu suficient. Include originalele în backup; nu publica `storage/qa/`. Accesul HTTP la `.user.ini` trebuie refuzat.
- Verifică extensiile necesare dependențelor PDF/XLSX/XML și rularea `composer check-platform-reqs`; testează PDF-ul multipagină și descărcarea ZIP-ului pe hosting.
- Verifică accesul HTTPS către arhiva BNR. Dacă rețeaua nu permite preluarea, cursul și data rămân introducibile manual.
- Verifică reamintirea zilnică din administrare/PWA pentru retururi fără NIR, amânarea pentru ziua curentă și dispariția după recepție. Acest modul nu autorizează expedierea de mesaje reale pentru test.
- Nu transforma soldurile istorice în loturi cu costuri presupuse. Introdu soldul inițial numai pe baza documentelor reale și verifică legătura viitorului modul de facturi de ieșire cu consumurile FIFO.

### Facturi de ieșire și automatizări

- Aplică migrațiile `051_invoice_automations`, `052_outgoing_invoices` și `053_invoice_workflows` pe copia MySQL de staging înaintea bazei live. Probează două emiteri simultane pentru aceeași comandă, alocarea numerelor din file diferite, retururile concurente și recuperarea după rollback. SQLite local nu confirmă concurența MySQL.
- Configurează cronul PHP `php /cale/proiect/bin/console.php invoices:process 20` și procesorul existent `outbox:process`; verifică reluarea intențiilor eșuate și erorile ajunse la limita de tentative. Activarea automatizării nu facturează retroactiv comenzile vechi.
- Dacă emailurile sunt filtrate prin `MAIL_MESSAGE_TYPES`, include `invoice`, `invoice_return_notice` și `order_confirmation`. Testează autorizat expedierea cu PDF, ordonarea confirmare/factură, retry fără duplicate, cazul unui document editat după programarea emailului și recepția pe telefon/desktop.
- Păstrează fișierele generate în `storage/private/`, în afara webrootului. Verifică drepturile, spațiul, extensiile PHP, PDF multipagină, imaginile, XLSX și ZIP pe hosting. Nu încărca baza/rutele/fișierele `storage/qa/`.
- Verifică URL-urile publice și linkul revocabil al facturii pe HTTPS, inclusiv revocarea și răspunsurile fără cache pentru documentele private. Datele istorice nu se recitesc automat din profilurile schimbate după emitere.
- Pentru retururi, verifică separat documentul valoric, înregistrarea restituirii efectuate și primirea fizică. Înregistrarea unei restituiri nu virează bani; integrarea restituirii Stripe este o etapă separată. Produsele nevandabile sunt evidențiate distinct, fără reintrare în stocul vandabil.
- Integrarea fiscală actuală este numai `FiscalGateway`, fără furnizor/autoritate sau credențiale configurate. Înaintea conectării reale implementează adaptorul, recuperarea joburilor întrerupte și testele semnării/transmiterii/răspunsurilor. Nu declara fiscalizare efectuată pe baza gateway-ului fals din teste.

### Verificări generale ale lansării

- PHP/extensii, Apache rewrite și webroot verificate pe hosting;
- instalare din DB goală și upgrade pe copie de DB reală;
- administrator unic, TOTP activ, coduri de recuperare păstrate sigur;
- HTTPS, cookies, CSP, trusted hosts/proxies, debug și permisiuni;
- email SMTP/outbox/cron și alerte la eșec;
- ramburs, Stripe test/live, webhook, plăți tardive și rambursări;
- concurență MySQL pentru ultima bucată și limite de cupoane;
- taxe, livrare, retur, pagini juridice și datele comerciantului aprobate;
- logo/fotografii/alt text/ghiduri de mărimi reale;
- `APP_INDEXING=true` numai după verificarea stagingului;
- backup + restaurare testată; demo, teste și secrete excluse din zona publică.
