# Audit funcțional NIR — 12 noiembrie 2026

Verificare solicitată pentru întregul flux NIR existent, cu regresii automate, cereri HTTP și utilizare în browser. Nu reprezintă aprobarea fiscală a modulului și nu bifează etapa din roadmap.

## Mediu și rezultate

- PHP 8.4.25, baze SQLite temporare, date demonstrative separate de baza principală.
- Suita integrală: **651 teste, 6.403 aserțiuni**, fără eșecuri; 100 MB memorie maximă raportată.
- După ultima corecție a numelor din evidența stocului și testul suplimentar: suita NIR **148 teste, 871 aserțiuni**, fără eșecuri. Aceasta include regresia numelui istoric al furnizorului după redenumire/dezactivare.
- JavaScript: **21 teste Node**, inclusiv 11 exemple de calcul comparate cu rezultatele PHP.
- PHPStan fără erori, lint PHP, sintaxă JS și **113 template-uri Twig** compilate.
- Browser intern: desktop, tabletă 768 px și telefon 390 px; server QA separat pe 8081, fără SMTP, Stripe sau push. Serverul principal rămâne pe 8080.
- BNR: cerere reală către arhiva oficială `https://curs.bnr.ro/files/xml/years/nbrfxrates2026.xml`. Pentru EUR/12.11.2026, la verificarea de dimineață, serviciul a returnat **5,34600000**, curs publicat la **11.11.2026**. Căutarea folosește ultima cotație disponibilă, nu inventează cursul zilei.

## Acoperire funcțională

| Funcționalitate | Verificare efectuată |
| --- | --- |
| Registru | Căutare, filtre de stare, paginare, perioade valide/invalide și limitele zilei în Europe/Bucharest. |
| Rute și modale | Registru, fișă, adăugare, editare, confirmare, corecție, retur, sold inițial, export și evidența stocului; HTML complet și răspunsuri pentru modale. |
| Draft | Creare, salvare incompletă, redeschidere, editare și ștergere; fără modificarea stocului. |
| Date obligatorii | Firma, gestiunea, locația activă, furnizorul, factura și produsele sunt reverificate la confirmare. Erorile păstrează formularul. |
| Furnizor și locație inline | Adăugare și validare cu/fără JavaScript; datele NIR nesalvat se păstrează; răspunsurile întârziate nu schimbă gestiunea curentă. |
| Asocierea produsului | Nume/SKU exact, cod furnizor, EAN, priorități, rezultate ambigue și referințe memorate. Importul nu creează produse sau stoc. |
| Referințe și preț anterior | Reasociere cu motiv și audit; istoricul produsului vechi nu devine preț anterior pentru produsul nou. Documentele istorice rămân neschimbate. |
| Cantități și UM | Facturat/primit/acceptat/respins, motive pentru diferențe, conversie și respingerea cantităților nereprezentabile la patru zecimale. |
| Calcul financiar | Preț unitar, net impus, TVA exact, total brut, discount inclusiv 100%, curs, TVA în cost, costuri suplimentare și limite numerice. Serverul ignoră totalurile trimise de browser. |
| Curs BNR | RON, valută, dată invalidă/viitoare, multiplicator, cache, ultima zi publicată, trecerea dintre ani și indisponibilitatea rețelei. Introducerea manuală rămâne posibilă. |
| Confirmare | Număr anual după anul local, avertismente confirmate explicit, duplicate de factură, tranzacție atomică și instantanee istorice. |
| Cereri repetate și concurente | Chei de idempotență, reluare multipart, payload diferit pe aceeași cheie și formulare cu versiuni vechi. Aceste teste nu simulează concurența reală MySQL. |
| Corecție controlată | Motiv obligatoriu, blocare după consum/storno, restaurare integrală a documentului și stocului dacă noua confirmare eșuează. |
| Retur furnizor | Parțial/integral, document negativ separat, maximum disponibil, repetare fără dublare, ultima fracție păstrează exact valoarea în bani. |
| Sold inițial | Cost real și explicație, produs urmărit, gestiune/locație eligibile, cantități zecimale, reluare sigură și formular păstrat la eroare. |
| Consumul intern | First in, first out după data recepției și ID-ul lotului, costuri din mai multe loturi, izolare pe gestiune, consum în așteptare și reconciliere la intrare. |
| Retur client | Recepție fizică explicită, referință factură, costurile originale, retururi parțiale și gestiuni diferite; blocare dacă nu există cost justificat. |
| Avertizare retur | Amânare pe zi/utilizator și dispariție după recepție. Nu s-au expediat notificări externe. |
| Atașamente | PDF/JPG/PNG/WebP/XLSX/XML, MIME real, limite, nume lungi, deduplicare, selecții succesive și păstrarea originalului importat. |
| Viața fișierelor | Adăugare/eliminare/descărcare, apartenența la document, curățare după eroare/retry și protecția fișierelor din afara directorului privat. |
| Import XLSX | Prima foaie, rânduri goale, coduri cu zerouri, TVA numeric/text/procent, UM implicită, limita de 1.000 de poziții și erori cu numărul rândului. |
| Import UBL | Net redus și TVA exact, CUI unic, avertismente pentru ajustări la nivel de document, respingerea documentelor incomplete/negative neeligibile. |
| Securitate import | Formule, DTD/entități externe, macrocomenzi, traversare de căi și arhive cu volum dezarhivat excesiv. |
| Export individual | PDF, Excel cu cinci foi/141 coloane și ZIP cu originale; valori, formule interne de raportare, diferențe, audit, tipuri și protecția textelor introduse de utilizator. |
| Export registru | 38 coloane, filtre/perioade, XLSX/ZIP; descărcare reală Excel din browser. |
| PDF | Randare și inspecție vizuală pe documente scurte și pe patru pagini, antet repetat, numerotare și curățarea fișierelor temporare. |
| Drepturi | Autentificare admin, CSRF pentru fiecare mutație, acces interzis operatorului la acțiuni/costuri/fișiere/exporturi/referințe și eliminarea costurilor din audit. |
| Interfață | Antet/acțiuni fixe, scroll numai în conținut, mesaje de validare și avertismente simultane, blocare în timpul importului, fără overflow orizontal la 390/768 px. |

## Flux parcurs manual în browser

1. Adăugare draft cu un produs și TVA exact: **10,01 + 2,13 = 12,14 RON**, trei bucăți; PDF și PNG selectate succesiv și păstrate ambele.
2. Confirmare după acceptarea avertismentelor. Lot nou cu trei bucăți și valoarea corectă.
3. Retur de o bucată: **−3,34 − 0,71 = −4,05 RON**; original marcat parțial stornat, două bucăți rămase. Evidența totală a produsului a rămas în echilibru cu loturile.
4. Import Excel cu două poziții, asociere automată sigură și TVA text `21%`: **169,98 + 35,70 = 205,68 RON**. Salvare fără număr de factură permisă pentru draft; confirmarea blochează factura incompletă și afișează și avertismentul de preț.
5. Verificarea modalelor pe telefon/tabletă, a loturilor, referințelor, soldului inițial și descărcarea registrului Excel.

Datele și capturile acestei verificări sunt private, în `storage/qa/nir-audit-20261112/`; PDF-urile inspectate sunt în `storage/qa/nir-document-review/`. Nu sunt livrate în webroot sau Git.

## Corecții rezultate din audit

- Preview-ul financiar folosește aritmetică zecimală exactă, cu aceeași ordine de rotunjire ca serverul.
- Cererile asincrone vechi nu suprascriu produsul, moneda, data/cursul sau locația schimbate între timp.
- Importul blochează salvarea concurentă și respectă limitele de poziții/atașamente înainte de modificarea formularului.
- Erorile de câmp nu mai dispar când răspunsul conține și avertismente; ciornele incomplete pot fi salvate.
- Motivul corecției este obligatoriu pe server; precizia invalidă la retur produce validare, nu eroare internă.
- Numerotarea de Anul Nou folosește anul din România. Retururile clientului reconciliază și consumurile în așteptare.
- Locațiile inactive sunt respinse. Reasocierea referinței nu transferă istoricul de preț al altui produs.
- Procentele Excel, valorile UBL incomplete, numele de fișiere lungi și căile nesigure sunt tratate explicit.
- Feed-ul BNR folosește gazda oficială actuală. Exporturile nu inventează seria separată ori prețuri inițiale necunoscute.
- Numele produselor/furnizorilor și etichetele istoricului sunt prezentate corect; numerele de stoc sunt formatate lizibil.
- **FIFO rămâne exclusiv regula internă**. Interfața afișează „Evidența stocului”, „Loturi” și „Sold inițial”; exporturile nu expun denumirea algoritmului. URL-ul vechi redirecționează către `/admin/nir-uri/stoc/`.

## Limite explicite

- `AccountingStockService::recordInvoice()` este puntea către `NirService::consume()`. Emiterea facturilor de ieșire nu este încă legată de ea: checkout-ul, plata și schimbarea statusului nu consumă singure aceste loturi contabile. La etapa facturării se stabilește și gestiunea facturii.
- Returul contabil al clientului cere consumurile și costurile originale. Testele construiesc aceste date; comenzile istorice fără cost justificat nu primesc costuri inventate.
- NIR-ul nu emite facturi fiscale/corective, nu execută OCR și nu sincronizează implicit stocul comercial cu stocul contabil.
- Unele coloane opționale din specificație rămân explicit „Nestocat” în dicționarul Excel.
- MySQL/InnoDB, concurența reală, Apache/shared hosting, limitele serverului și accesul BNR din hosting se verifică înainte de publicare. Faptul că SQLite și rețeaua locală au trecut testele nu validează mediul de producție.
- Nu s-au trimis emailuri/push, nu s-au configurat servicii live, nu s-a făcut deploy și nu s-au introdus NIR-uri de test în baza principală.

## Comenzi reproductibile

```powershell
.\bin\php-local.ps1 vendor/phpunit/phpunit/phpunit
.\bin\php-local.ps1 vendor/phpunit/phpunit/phpunit --filter Nir
node --test tests/Frontend/nir-calculations.test.cjs tests/Frontend/nir-admin.test.cjs
.\bin\php-local.ps1 vendor/phpstan/phpstan/phpstan analyse --no-progress
.\bin\php-local.ps1 bin/lint.php
.\bin\php-local.ps1 bin/compile-templates.php
git diff --check
```

## Actualizare 13.11.2026 — retururi, stoc negativ și ștergerea ultimului NIR

- Suită integrală: **689 teste / 6.933 aserțiuni**. După ultima ajustare a facturii fără stoc/deficitului vechi: **189 teste NIR și AccountingStock / 1.397 aserțiuni**, plus **55 teste JavaScript**. PHPStan, lint PHP și compilarea a 120 template-uri Twig au trecut.
- Verificate: stoc contabil −1 înaintea NIR-ului, intrare ulterioară, ștergere după vânzare și revenire la −1; idempotency, lipsa realocării la ștergere, restaurarea exactă a retururilor furnizor/client, numerotare numerică 9/10, drafturi independente/compactate și blocarea documentelor confirmate mai vechi. Inventarul online rămâne identic în testele de modificare contabilă.
- Verificate: minimum un fișier real la retur, cantitate activată prin switch, prag disponibil pe furnizor/preț/gestiune, confirmarea folosirii intrărilor compatibile, nume/conținut de fișier acceptate în NIR-uri diferite și guard pentru modificările nesalvate.
- Legături în ambele sensuri: test HTTP cu două retururi în tranșe, numere/date/copiere/URL distinct pentru fiecare, fără limită de afișare adăugată, legătura eliminată după ștergerea returului. Testele nu emit facturi fiscale.
- Browser intern Paula/8080: 320/390/768 px și desktop; verificări vizuale pentru returul roz-roșu, informații, renunțare, loader LM, legătura originalului și lista retururilor. Copierea a returnat efectiv numărul documentului; navigarea a deschis documentul corect. Confirmarea ștergerii a fost doar previzualizată, fără executare în baza principală.
- Fișiere XLSX/ZIP descărcate efectiv sunt valide; captura „Stopped” nu s-a reprodus. Proba privată de 33 MB a separat stocarea de 68–78 ms de primirea prin serverul PHP local de 6,8–9,2 secunde. Aceste rezultate nu validează performanța hostingului.
- Migrarea SQLite 049 aplicată după backup consistent: integritate OK, zero încălcări FK și comparație identică pentru documentele, comenzile, produsele și inventarul online existente. MySQL/concurența reală și Apache nu au fost rulate.

Această actualizare înlocuiește regula inițială care interzicea orice ștergere de NIR confirmat. Nu autorizează bifarea roadmapului, publicarea sau conectarea automată a viitoarelor facturi fiscale. Capturi și probe: `storage/qa/nir-return-ui/`, `storage/qa/nir-upload-performance/`, excluse din Git.