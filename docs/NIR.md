# NIR-uri — La Măriuca

Modulul se deschide din **Stoc și achiziții → NIR-uri**, la `/admin/nir-uri/`. Adaptarea pornește de la documentul furnizat de utilizator, „Specificație funcțională completă — modul NIR” (`Pasted text.txt`, atașamentul `a84eca9d-f2f0-439c-bd6f-3067e27d9807`). Folosește firma, gestiunile, furnizorii, produsele și variantele existente în La Măriuca. Nu depinde de marketplace-uri.

## Pentru operator

### Recepția unei facturi

1. Completează întâi **Detaliile firmei** și cel puțin o gestiune. În NIR alegi gestiunea și locația fizică de recepție; poți adăuga o locație din formular.
2. Apasă **Adaugă NIR**. Alege furnizorul activ sau creează-l în formular. Completează seria, numărul și data facturii, apoi verifică data/ora NIR-ului și a recepției fizice.
3. Verifică moneda. Pentru RON cursul este 1. Pentru valută, **Preia cursul BNR** caută ultimul curs disponibil la sau înaintea datei alese. Dacă preluarea nu reușește, introdu manual cursul și data documentată.
4. Atașează factura și dovezile recepției. Poți selecta fișiere, folosi drag-and-drop sau fotografia documentul pe telefon.
5. Pentru fiecare poziție, păstrează denumirea și codul furnizorului și asociază produsul/varianta din magazin. Verifică separat cantitățile facturate, primite fizic, acceptate și respinse, unitățile și factorul de conversie. Când există diferențe, alege motivul; pentru „Alt motiv”, scrie explicația.
6. Introdu valorile în forma disponibilă pe factură: preț unitar, valoare netă a liniei, net + TVA exact sau total cu TVA. Poți completa discountul, cota TVA și costurile suplimentare alocate în RON. Rezumatul final se recalculează pe server.
7. **Salvează draft** creează o ciornă, fără intrare în stoc. Redeschide documentul pentru completări sau apasă **Confirmă recepția**. Citește avertismentele și acceptă-le explicit numai după verificare.

La confirmare se atribuie numărul final și se înregistrează împreună documentul, cantitățile acceptate în stocul contabil, mișcările, loturile FIFO și auditul. Pozițiile excluse de la urmărirea contabilă rămân în document și în totalurile facturii, dar nu produc stoc ori loturi.

### Importul pozițiilor

- **XLSX:** se citește prima foaie, cu antete recunoscute pentru denumire, cod, cantitate, preț, TVA și câmpurile compatibile. Limita este de 1.000 de poziții. Formulele din fișierul importat sunt respinse; trimite un export cu valori.
- **XML:** sunt acceptate facturi UBL `Invoice-2`. Furnizorul poate fi preselectat când CUI-ul identifică exact un singur furnizor activ. Cantitățile, prețurile, valorile nete și TVA-ul disponibil devin propuneri în formular.
- Potrivirile exacte și neambigue pot asocia automat produsul. Potrivirile aproximative sunt sugestii care cer alegerea operatorului. O referință memorată păstrează și unitățile/factorul de conversie al furnizorului.
- Importul nu confirmă recepția. Verifică avertismentele: taxele sau reducerile UBL la nivelul întregului document necesită repartizare pe poziții, iar foile XLSX suplimentare nu sunt importate.
- PDF-urile și fotografiile sunt documente justificative; acest modul nu execută OCR asupra lor. XML-urile `CreditNote` se tratează prin fluxul de retur furnizor, nu ca recepții normale.

### Corectare, ștergere și documente suplimentare

Un draft poate fi editat sau șters oricând; drafturile rămase sunt renumerotate consecutiv, separat de documentele confirmate.

**Ultimul NIR confirmat, după numărul anual**, poate fi șters de un administrator cu drept de confirmare, inclusiv dacă marfa lui a fost vândută. Drafturile nu participă la această verificare. Modalul prezintă stocul contabil înainte și după ștergere; serverul verifică din nou ultimul număr și versiunea la salvare. Se inversează întreaga cantitate a documentului: de exemplu **−1 → NIR +2 → +1 → ștergere → −1**. La ștergerea unui retur furnizor, cantitățile și valorile revin în intrările efectiv folosite. Numărul șters poate fi atribuit următorului document confirmat.

Ștergerea nu modifică comenzile, vânzările, alte NIR-uri sau stocul online. Consumul alocat intrării șterse rămâne în așteptarea justificării printr-o intrare ulterioară; nu este mutat automat pe alt NIR în timpul ștergerii. Auditul intern și mișcarea de compensare rămân păstrate, iar reluarea unei cereri vechi nu poate recrea documentul șters.

**Corectarea controlată** este disponibilă numai pentru o recepție furnizor confirmată, fără storno și cu loturi complet neconsumate. Completează motivul și aplică modificările. Recepția rămâne confirmată până la salvarea corecției; dacă o verificare eșuează, tranzacția restaurează datele și stocul anterior.

Fișierele unui document confirmat se pot adăuga sau elimina separat. Aceste acțiuni se auditează și nu recalculează stocul. În cazul unui conflict de versiune, redeschide documentul înainte de a continua; sistemul nu suprascrie modificările făcute între timp de alt administrator.

### Retur către furnizor

Din recepția confirmată, deschide **Retur către furnizor**, selectează cantitățile, introdu referința și data facturii corective, motivul și cel puțin un fișier justificativ obligatoriu. Se creează un document negativ separat, legat de original. Registrul arată dacă recepția este stornată parțial sau integral.

În NIR-ul inițial, secțiunea **Retururi către furnizor** afișează toate documentele asociate, unul sub altul, cu număr, dată/oră, referința facturii corective, copiere și acces direct. Fiecare retur păstrează legătura inversă către NIR-ul original. Un document șters dispare din listă.

Pentru fiecare produs se afișează „Pe stoc din acest NIR”, cantitatea compatibilă din alte intrări și limita efectivă de retur. Se folosesc întâi bucățile originale; dacă nu ajung, operatorul poate accepta explicit folosirea altor intrări numai pentru același produs/variantă, furnizor, preț net de achiziție în RON și gestiune. Cantitatea cerută nu poate depăși stocul compatibil disponibil sau cantitatea documentară nereturnată. Prețul ori furnizorul diferit nu este compatibil. Alocarea internă scade disponibilul sursei efective, fără rescrierea celuilalt NIR, a exporturilor sale sau a vânzărilor anterioare. Nu apare în PDF/XLSX ca transfer între documente. Aceste verificări previn erorile contabile și returnarea aceleiași bucăți de două ori. Produsele întoarse ulterior de un client pot deveni eligibile prin trasabilitatea lor originală. Dacă au fost recepționate în altă gestiune, este necesar mai întâi un transfer documentat; modulul nu mută implicit stocul între gestiuni.

### Retur de la client

În **Evidența stocului** apar retururile care încă necesită recepție contabilă. **Ascunde pentru astăzi** amână afișarea pentru utilizatorul curent; avertizarea reapare la o vizită într-o zi ulterioară până la rezolvare. Acesta este un mesaj în administrare, nu o promisiune de email sau push zilnic.

Recepția cere gestiunea, locația, referința/datele facturii de retur și confirmarea că produsele au fost primite fizic. Reintrarea folosește costurile și consumurile FIFO originale. Schimbarea stării comerciale a comenzii nu creează singură un NIR. Când istoricul nu oferă costuri verificabile, recepția contabilă este blocată cu explicație; nu se inventează loturi cu cost zero.

### Evidența stocului și sold inițial

Pagina Evidența stocului prezintă loturile, consumurile, cantitățile rămase și reconcilierea: **stocul contabil al gestiunii = suma cantităților rămase în loturile sale**. Consumurile care nu au încă loturi suficiente rămân identificate separat și se reconciliază la recepțiile eligibile.

Administratorul poate înregistra un **Sold inițial** pentru un produs urmărit contabil, cu gestiune, dată, cantitate, cost real și explicație. Este o operațiune distinctă, auditată, destinată stocului documentat anterior primei recepții. Nu se folosește pentru a presupune costuri istorice necunoscute.

Referințele furnizorilor pot fi reasociate explicit din pagina Evidența stocului, cu motiv. O asociere existentă nu este înlocuită silențios cu alt produs.

### Exporturi

Din fișa NIR se descarcă PDF, Excel sau dosarul ZIP. Din registru se alege perioada, starea documentelor și formatul Excel/ZIP; perioadele folosesc zilele din Europe/Bucharest. Exportul este limitat la 5.000 de documente pentru o singură cerere.

- **PDF:** document cu sigla La Măriuca, datele firmei/furnizorului, recepția, produsele fotografiate, diferențe, totaluri, observații și zonă de semnătură/ștampilă.
- **Excel individual:** cinci foi — „Rezumat NIR”, „Poziții NIR”, „Diferențe”, „Documente & Audit”, „Dicționar câmpuri”. Foaia de poziții păstrează cele 141 de coloane din specificație.
- **Excel registru:** 38 de coloane, filtre, antet fix și totaluri pentru documentele selectate.
- **ZIP:** registrul, când este solicitat pentru o perioadă, plus PDF, Excel și documentele originale pentru fiecare NIR.

Coloanele opționale pe care La Măriuca nu le stochează — de exemplu, unele date despre lot, garanție, aviz sau formalități vamale — rămân goale și sunt marcate **Nestocat** în dicționar. Nu se completează cu valori presupuse. Fișierele exportate se generează temporar și nu devin documente publice permanente.

## Drepturi de acces

| Acțiune | Administrator | Operator |
| --- | --- | --- |
| Registru și fișă NIR | Da | Da |
| Costuri, prețuri și fișiere originale | Da | Nu |
| Creare, editare, confirmare, corectare și retur furnizor | Da | Nu |
| Exporturi, evidența stocului, referințe și furnizori inline | Da | Nu |
| Sold inițial | Da | Nu |

Acestea sunt rolurile administrative disponibile în autentificarea actuală. Serviciul de domeniu pregătește și permisiuni pentru un rol `manager`, însă acest rol nu este activat prin prezentul modul și nu primește acces nou la administrare.

Autorizarea se verifică pe server pentru fiecare rută. Câmpurile financiare sunt eliminate din răspunsurile destinate operatorului, inclusiv din audit. Fișierele originale necesită acces la costuri deoarece pot conține prețuri de achiziție.

## Arhitectură și reguli de integritate

- `NirAdminController` adaptează cererile HTTP și pregătește datele pentru Twig; `NirService` execută regulile; `NirRepository` concentrează SQL-ul. `NirDecimal` folosește `brick/math`: cantități cu 4 zecimale, prețuri/costuri unitare cu 6, curs cu 8 și totaluri în bani întregi. Calculul contabil nu folosește `float` binar.
- Migrarea `047_nir_receipts.sql` are variante SQLite și MySQL. `nir_stock_balances` este evidența contabilă nouă, pe gestiune și produs/variantă; `nir_fifo_layers`, consumurile și mișcările oferă trasabilitatea. Recepțiile nu modifică stocul comercial, rezervările sau cantitățile disponibile în checkout.
- Stocul contabil poate fi negativ: o factură emisă înainte de introducerea achiziției scade imediat soldul. NIR-ul introdus ulterior adaugă cantitatea și atribuie costurile consumurilor în așteptare fără o a doua scădere. Migrarea `049_nir_negative_accounting_stock.sql` reflectă în sold consumurile anterior păstrate numai în așteptare. Eventualul deficit din evidența veche este preluat o singură dată, înaintea primei intrări sau facturi din noua evidență.
- Disponibilitatea magazinului este controlată separat de setările de disponibilitate online și stoc online ale produselor. Un sold contabil zero/negativ nu dezactivează produsul și nu modifică acele setări.
- Firma, furnizorul, locația, gestiunea și identificarea produsului se păstrează în document ca instantanee. Editarea ulterioară a catalogului nu rescrie denumirile și valorile unei recepții confirmate.
- Scrierile critice sunt tranzacționale și serializate prin blocarea înregistrării `nir_write_lock`. `row_version` detectează formularele vechi. Cheile de idempotency împiedică dublarea confirmării, corecției, returului și soldului inițial; hash-ul cererii ignoră câmpurile de transport și numele aleatoriu al copiei încărcate, păstrând identitatea conținutului.
- Auditul păstrează autorul, momentul, acțiunea și modificările. Evenimentele domeniului sunt înregistrate în `nir_outbox`; acestea nu reprezintă, prin ele însele, emailuri sau notificări push trimise.
- Datele se persistă în UTC și se afișează în Europe/Bucharest. Rutele administrative cer autentificare și permisiuni; cererile de modificare cer CSRF. Formularele principale au pagini complete pentru utilizarea fără JavaScript; modalele, importul interactiv și preluarea BNR sunt îmbunătățiri ale interfeței.

### Fișiere și importuri sigure

`NirDocumentStorage` păstrează originalele în `storage/private/nir-documents/`, în afara webrootului. Sunt acceptate PDF, JPG/JPEG, PNG, WebP, XLSX și XML, fără plafon de bytes impus de aplicație, cu maximum 30 de fișiere într-o încărcare; limitele efective ale găzduirii rămân aplicabile. Extensia, conținutul MIME, dimensiunea și structura sunt verificate; numele stocat este aleatoriu, iar fiecare atașament are ID propriu și legătură la NIR. Nume identice nu sunt identități de fișier. Același conținut poate fi folosit în NIR-uri distincte, inclusiv confirmate; SHA-256 deduplică numai copia identică în același document. Frontendul păstrează fișiere distincte chiar dacă numele, dimensiunea și data sunt identice.

XML-ul nu permite DTD/entități externe. XLSX-ul are limite pentru arhiva dezarhivată și nu execută formule importate. Exporturile scriu textele ca texte, pentru a preveni interpretarea lor ca formule Excel. PDF-ul și miniaturile citesc numai imagini locale autorizate, fără descărcări arbitrare de URL-uri.

Descărcarea verifică apartenența fișierului la NIR, dreptul de acces și existența sa; răspunsul este privat, fără cache și cu `nosniff`. Copiile temporare rămase după erori sau reluarea unei cereri sunt curățate.

## Limite și integrarea următoare

Facturile de ieșire și emiterea fiscală a facturilor corective rămân module separate. Câmpurile facturii de retur de aici sunt referințe introduse de operator către documentul real; NIR-ul nu emite automat o factură fiscală și nu certifică validitatea ei fiscală.

`NirService::consume()` este punctul de integrare pentru factura de ieșire efectiv emisă. Checkout-ul, plata și simpla schimbare a statusului comenzii nu îl apelează. Legarea definitivă la entitatea viitoare de factură se face în etapa facturilor de ieșire. Stocurile și comenzile istorice nu sunt transformate automat în loturi FIFO cu costuri presupuse.

Schema MySQL este livrată, dar rularea migrării și concurența pe MySQL/InnoDB, Apache/shared hosting și accesul de rețea către BNR trebuie verificate în mediul țintă. Nu s-a făcut publicare în producție. Preluarea BNR a fost verificată separat în rețeaua locală, folosind arhiva oficială `https://curs.bnr.ro/files/xml/years/nbrfxratesAAAA.xml`; acest rezultat nu validează conectivitatea hostingului.

Limitele PHP pentru formularul de 1.000 de poziții și atașamente sunt incluse în `public/.user.ini` pentru PHP-FPM/CGI și în `bin/php-local.ps1` pentru serverul local: `max_input_vars=60000`, `upload_max_filesize=0`, `post_max_size=0`, `max_file_uploads=30`. Pe hosting se verifică valorile efectiv aplicate și limita proxy/webserverului, conform checklistului de deployment.

## Verificări efectuate

Auditul funcțional extins din 12.11.2026, matricea funcțiilor testate, defectele remediate și limitele integrării sunt în **[NIR_QA.md](NIR_QA.md)**. FIFO este exclusiv regula internă de consum; interfața și exporturile folosesc denumiri funcționale, fără numele algoritmului.

Testele locale rulează pe baze SQLite temporare. Suita dedicată documentelor și protecțiilor HTTP a trecut: 19 teste, 121 de aserțiuni. Acoperă formatele și securitatea importurilor/exporturilor, redacția costurilor, versiunile vechi, apartenența și accesul la atașamente, perioadele exportate la limita miezului nopții locale, avertismentele fără JavaScript și reluarea unui retur multipart fără duplicarea documentului sau fișierului.

PDF-ul a fost randat și inspectat vizual în variantă scurtă și cu mai multe pagini; antetul tabelului se repetă, iar numerotarea paginilor rămâne vizibilă. Verificările integrale ale aplicației și ale interfeței sunt consemnate în `docs/PROGRESS.md`. Această documentație nu bifează etapa din roadmap; finalizarea ei se confirmă separat de utilizator.

### Interacțiuni și transferuri (actualizare 12.11.2026)

Adăugarea, editarea și returul cer confirmare înainte de închiderea unui formular cu modificări nesalvate. „Continuă editarea” păstrează datele și fișierele selectate. Reîncărcarea sau navigarea folosește avertismentul nativ al browserului. Numai salvarea reușită resetează protecția.

Exporturile și descărcările afișează după 300 ms un dialog cu indicator circular și sigla LM. Uploadurile afișează progresul real al transferului, apoi așteptarea răspunsului serverului; 100% transferat nu este prezentat ca salvare finalizată. Erorile păstrează formularul. Generarea fișierului și preluarea acestuia de browser sunt pași separați.

Returul către furnizor are accente roz-roșu în fișă și registru, terminologie distinctă de recepție inclusiv în PDF/XLSX, și cardul NIR-ului original cu număr, dată/oră București, acces direct și copiere. Valorile documentului de retur rămân negative; identificatorul LM-NIR este păstrat.
