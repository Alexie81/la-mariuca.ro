# Web Push administrativ — configurare Apache/PHP

Notificările administrative folosesc standardele browserului (Service Worker, Push API și VAPID) și biblioteca PHP `minishlink/web-push`. Nu necesită Node.js, Firebase, OneSignal, cont Apple Developer sau alt cont extern.

## Cerințe pe hosting

- domeniul trebuie să ruleze prin HTTPS valid; excepția HTTP este permisă de browsere numai pentru `localhost`;
- Apache trebuie să publice exclusiv directorul `public/`;
- PHP 8.4 cu extensiile `curl`, `openssl`, `mbstring` și `json`;
- ieșire HTTPS permisă din PHP către endpointurile push ale browserelor;
- cron PHP CLI este recomandat pentru reîncercări, dar trimiterea inițială se face imediat în cererea care a creat evenimentul.

## Configurare

1. Instalează dependențele cu `php composer.phar install --no-dev --optimize-autoloader`.
2. Generează o singură pereche VAPID:

   ```text
   php bin/generate-vapid-keys.php
   ```

   Scriptul scrie în `storage/private/web-push-vapid.json`, în afara webrootului și în afara Git. Nu regenera cheia după ce dispozitivele s-au abonat: rotația ei invalidează abonamentele existente.
3. Configurează `.env`:

   ```text
   WEB_PUSH_ENABLED=true
   WEB_PUSH_VAPID_FILE=storage/private/web-push-vapid.json
   WEB_PUSH_VAPID_SUBJECT=mailto:contact@la-mariuca.ro
   ```

4. Aplică migrările cu `php bin/console.php migrate`. Migrarea `017_admin_web_push.sql` există separat pentru SQLite și MySQL.
5. Adaugă reîncercarea în cron, de preferat la fiecare minut:

   ```text
   * * * * * php /cale/catre/proiect/bin/console.php push:process 100
   ```

   Comanda nu trebuie expusă ca URL public.

## Activarea pe dispozitive

- Administratorul intră în `/admin/notificari/` și apasă „Activează notificările” pe fiecare telefon/calculator.
- Permisiunea browserului este cerută numai după această acțiune explicită.
- Pe Android și desktop, notificările pot sosi în fundal conform setărilor browserului și sistemului de operare.
- Pe iPhone/iPad (iOS/iPadOS 16.4+), panoul trebuie adăugat întâi pe ecranul principal din Safari, apoi deschis din pictograma instalată și notificările activate acolo.
- „Ține-mă minte” păstrează sesiunea administrativă, iar abonamentul push rămâne separat pentru fiecare dispozitiv. Un dispozitiv poate fi eliminat din lista din centrul de notificări.

## Verificare înainte de lansare

- confirmă răspuns `200` pentru `/admin-manifest.webmanifest` și `/admin-service-worker.js`;
- activează un dispozitiv real și trimite notificarea „Testează” din centru;
- verifică o comandă nouă, o plată confirmată, o recenzie și un prag de stoc redus;
- verifică deschiderea directă a destinației la apăsarea notificării;
- verifică `php bin/console.php push:process 100` și coloanele `last_success_at` / `last_failure_at`;
- nu declara Web Push verificat în producție până când testul de mai sus nu a trecut pe domeniul HTTPS real.
