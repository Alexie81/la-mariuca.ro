# Instalare și deployment

## Stare de compatibilitate

Verificat local la 16 octombrie 2026:

- PHP CLI 8.4.25;
- extensii folosite: ctype, curl, DOM/XML, fileinfo, GD, JSON, mbstring, OpenSSL, PDO,
  pdo_sqlite, pdo_mysql, session, sodium și zip (zip este necesar numai la instalarea Composer);
- Composer 2 local (`composer.phar`) și lockfile instalat fără ignorarea cerințelor;
- SQLite pentru dezvoltare/teste; serverul PHP integrat pentru smoke test HTTP.

Neverificat extern: PHP-ul Apache, `mod_rewrite`, `AllowOverride`, MySQL/MariaDB, structura reală
`public_html`/`httpdocs`, permisiunile, cron, SMTP, Stripe test/live și backupul hostingului.
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
*/10 * * * * php /cale/bin/console.php reservations:expire
```

Nu expune aceste comenzi ca URL public. Dacă hostingul nu oferă cron, outbox-ul și expirarea
rezervărilor sunt `BLOCKED_EXTERNAL_CONFIG`; comenzile trebuie rulate controlat din panou.

În dezvoltare, outbox-ul scrie preview-uri în `storage/private/mail`. În producție, setează un
`MAILER_DSN` SMTP real și testează retry/backoff fără date reale de client.

## Stripe

Stripe este dezactivat implicit. Pentru test:

1. setează `STRIPE_ENABLED=true`, cheia secretă test și secretul webhook test;
2. expune endpointul HTTPS `/stripe/webhook` numai în mediul de test autorizat;
3. configurează evenimentul `checkout.session.completed`;
4. testează semnătura, duplicatele, suma, moneda, evenimentele tardive și reconcilierea;
5. activează live numai după separarea completă test/live și un checkout real autorizat.

Redirectul browserului nu marchează plata ca achitată. Numai webhookul semnat actualizează plata.
Rambursările se inițiază în dashboardul Stripe în această versiune; sincronizarea completă a
rambursărilor parțiale este încă de implementat.

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
