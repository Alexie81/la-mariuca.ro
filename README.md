# La Măriuca Boutique

Magazin online modular, server-rendered, pentru copii 0–8 ani. Aplicația folosește PHP 8.4,
Slim 4, Twig 3 și PDO; ținta de producție este Apache cu MySQL/MariaDB pe găzduire standard.

> Stare: versiune locală funcțională și verificată pe SQLite. Nu este încă declarată gata de
> producție: Apache, MySQL/MariaDB, Stripe, SMTP și configurația hostingului nu au fost verificate.

## Ce funcționează

- storefront responsive în română: homepage, categorii/subcategorii, colecții, produse, căutare,
  favorite de vizitator, coș, checkout și confirmare;
- pagini HTML indexabile cu URL propriu, canonical, sitemap, robots, redirect 301 la schimbarea
  slugului, Product/Breadcrumb data și control `noindex`;
- catalog administrabil: categorii ierarhice fără cicluri, produse, variantă implicită, stoc și
  istoric de mișcări;
- checkout ramburs fără cont, cu prețuri exclusiv server-side, cheie idempotentă, rezervări de stoc,
  snapshoturi de comandă și outbox pentru email;
- tranziții controlate confirmare/pregătire/expediere/livrare/anulare, fără consum/eliberare dublă;
- cupoane fixe/procentuale cu prag, perioadă și limite totale/per email;
- conturi client, roluri administrator/operator și TOTP pentru admin (secret criptat, coduri de
  recuperare hash-uite, anti-replay);
- media JPG/PNG/WebP validată după conținut, limitată, decodată și re-encodată; original privat;
- CMS cu pagini HTML sanitizate și secțiuni homepage activabile/reordonabile;
- Stripe Checkout hosted și webhook semnat implementate, dar dezactivate până la configurare;
- outbox local privat, CSV export și import dry-run.

Interfața este intenționat neutră. Imaginile și logo-urile existente din `logo-uri/` nu au fost
modificate sau importate automat. Vezi [handoff-ul de design](docs/DESIGN_HANDOFF.md).

## Pornire rapidă pe acest calculator

Instalarea PHP din PATH nu are `php.ini`; wrapperul PowerShell activează extensiile DLL deja
instalate numai pentru comanda curentă.

```powershell
.\bin\php-local.ps1 composer.phar install
Copy-Item .env.example .env
.\bin\php-local.ps1 bin/generate-key.php
.\bin\php-local.ps1 bin/console.php migrate
.\bin\php-local.ps1 bin/console.php seed:demo
.\bin\php-local.ps1 -S 127.0.0.1:8080 -t public public/router.php
```

Deschide <http://127.0.0.1:8080/>. Baza demo este `storage/private/development.sqlite` și este
ignorată de Git. Seed-ul este explicit și idempotent; produsele sunt marcate în descrieri drept demo.

Pentru un PHP configurat normal pe Linux/macOS/hosting, înlocuiește
`.\bin\php-local.ps1` cu `php`.

## Administrator

Pe Windows, scriptul următor citește parola mascat și o păstrează doar în memoria procesului:

```powershell
.\bin\create-admin.ps1
```

Alternativ:

```powershell
.\bin\php-local.ps1 bin/console.php admin:create
```

Adminul este la <http://127.0.0.1:8080/admin/>. După prima autentificare, accesează
`/admin/securitate/totp/`, salvează codurile de recuperare și activează TOTP. Nu există cont sau
parolă implicită în repository.

## Verificări

```powershell
.\bin\php-local.ps1 composer.phar lint
.\bin\php-local.ps1 vendor/bin/phpstan analyse --memory-limit=512M
.\bin\php-local.ps1 vendor/bin/phpunit --testdox
.\bin\php-local.ps1 composer.phar audit
```

Pe acest calculator, `composer check` nu poate lansa PHPUnit cu extensiile wrapperului în procesul
copil; comenzile de mai sus sunt echivalentul verificat. Pe un PHP cu extensii configurate în
`php.ini`, `php composer.phar check` rulează agregat.

## Comenzi operaționale

```text
bin/console.php migrate
bin/console.php seed:demo
bin/console.php admin:create
bin/console.php outbox:process
bin/console.php push:process 100
bin/console.php reservations:expire
bin/console.php csv:export [fișier]
bin/console.php csv:import <fișier> --dry-run
```

Detalii despre Apache, shared hosting, cron, backup, restaurare și checklistul de lansare sunt în
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Arhitectura și starea exactă sunt în
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) și [docs/PROGRESS.md](docs/PROGRESS.md).
