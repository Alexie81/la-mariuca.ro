# Checklist persistent — Google OAuth

Acest document trebuie citit și urmat la fiecare instalare, mutare de hosting, schimbare de domeniu
sau rotație a credențialelor Google. Nu conține și nu trebuie să conțină niciodată secrete.

## 1. Configurația care trebuie păstrată în Google Cloud

- Tip client: **Web application**.
- Application home page: `https://la-mariuca.ro/`.
- Privacy policy: `https://la-mariuca.ro/pagina/confidentialitate/`.
- Terms of service: `https://la-mariuca.ro/pagina/termeni-si-conditii/`.
- Authorized domain: `la-mariuca.ro`.
- Authorized JavaScript origin producție: `https://la-mariuca.ro`.
- Authorized redirect URI producție: `https://la-mariuca.ro/cont/google/retur/`.
- Origin local: `http://127.0.0.1:8080`.
- Redirect local: `http://127.0.0.1:8080/cont/google/retur/`.
- Cât timp aplicația este în `Testing`, conturile folosite la verificare trebuie adăugate în
  **Audience → Test users**.

Google cere potrivire exactă pentru redirect URI, inclusiv protocol, host, cale și slashul final.
Dacă domeniul canonic devine `www.la-mariuca.ro` sau aplicația este mutată într-un subdirector,
actualizează mai întâi configurația Google și apoi `APP_URL`/`APP_BASE_PATH`.

Local, proiectul se pornește prin `./bin/php-local.ps1 -S 127.0.0.1:8080 -t public public/router.php`.
Scriptul încarcă extensiile disponibile, inclusiv `curl`; fără `curl`, schimbul securizat al codului
OAuth cu Google nu poate fi finalizat.

## 2. Fișiere private care se transferă separat

Fișierul JSON descărcat din Google Cloud se transferă separat la:

```text
<proiect>/storage/private/google-oauth-client.json
```

Reguli obligatorii:

- nu se adaugă în Git;
- nu se pune în `public/`, `public_html` sau `httpdocs`;
- nu se copiază în capturi, loguri, tichete sau documentație;
- permisiunile trebuie să permită citirea numai utilizatorului aplicației, pe cât permite hostingul;
- la rotație, vechiul secret se revocă în Google Cloud după confirmarea noului flux.

Fișierul este acoperit de `.gitignore`. Dacă deploymentul se face prin `git pull`, acesta nu ajunge
automat pe server și trebuie încărcat separat prin canalul privat al hostingului.

## 3. Variabile `.env` de producție

Verifică explicit următoarele valori pe server:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://la-mariuca.ro
APP_BASE_PATH=
SESSION_SECURE=true
GOOGLE_OAUTH_ENABLED=true
GOOGLE_CREDENTIALS_FILE=storage/private/google-oauth-client.json
```

Alternativ, hostingul poate furniza `GOOGLE_CLIENT_ID` și `GOOGLE_CLIENT_SECRET` ca variabile private.
Nu configura simultan JSON-ul și variabilele cu credențiale diferite. `.env` rămâne în afara
webrootului și nu se trimite în Git.

## 4. Pregătirea aplicației și bazei de date

Înainte de primul test Google pe server:

1. Confirmă PHP cu extensiile `curl`, `openssl`, `json`, `mbstring`, `pdo` și driverul bazei. Verifică și că `curl.cainfo`/`openssl.cafile` indică un bundle CA valid atunci când hostingul nu folosește automat certificatele sistemului.
2. Rulează migrările, inclusiv:
   - `013_google_oauth_identities.sql`;
   - `014_user_phone.sql`.
3. Confirmă că DocumentRoot indică exclusiv către `public/`.
4. Confirmă HTTPS valid și cookie de sesiune `Secure`.
5. Confirmă că `APP_URL` generează exact callback-ul de producție, nu localhost.
6. Finalizează și aprobă juridic paginile de confidențialitate și termeni; textele demo/draft nu sunt
   suficiente pentru publicare sau verificare Google.

## 5. Smoke test obligatoriu pe domeniul live

Execută în ordine, cu un cont de test autorizat:

1. Deschide `https://la-mariuca.ro/cont/autentificare/` într-o sesiune nouă.
2. Apasă „Continuă cu Google” și confirmă că domeniul de redirect este Google, prin HTTPS.
3. Selectează contul de test și verifică ecranul de consimțământ: nume, domeniu, logo și linkuri
   juridice corecte.
4. După callback, confirmă redirecționarea la `/cont/` și existența sesiunii autentificate.
5. Confirmă în baza de date asocierea `oauth_identities` fără a afișa tokenuri sau secretul.
6. Deconectează-te și autentifică-te din nou cu același cont; nu trebuie creat un utilizator duplicat.
7. Verifică separat un cont nou și un cont client existent cu același email verificat.
8. Confirmă că un cont `administrator` sau `operator` nu este asociat automat prin email.
9. Verifică mesajul sigur de eroare când Google anulează sau refuză autentificarea.
10. Verifică logurile pentru erori, dar nu salva codul OAuth, `id_token`, access tokenul sau secretul.

## 6. Criteriul de finalizare

Google OAuth poate fi declarat „verificat în producție” numai după ce:

- checklistul live a trecut integral;
- callback-ul real HTTPS a fost observat;
- login/logout/login a funcționat fără duplicate;
- fișierele private nu sunt accesibile prin web;
- paginile juridice sunt finale;
- starea `Testing`/`Production` și lista de test users sunt intenționate și documentate.

Până atunci, starea corectă este `BLOCKED_EXTERNAL_CONFIG` sau „pregătit, neverificat pe hosting”.
