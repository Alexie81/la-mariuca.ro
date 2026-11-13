# Specificație funcțională completă — facturare de ieșire

## 0. Scopul documentului

Acest document descrie integral și în ordine logică un modul generic de facturare de ieșire, astfel încât funcționalitatea să poată fi reimplementată într-o altă aplicație fără dependențe de numele, furnizorii, marketplace-urile sau particularitățile comerciale ale proiectului din care a fost inspirată.

Specificația acoperă:

1. emiterea facturilor de vânzare;
2. facturile de retur integral și parțial;
3. facturile de corecție și documentele pozitive de înlocuire;
4. scăderea stocului și înregistrarea ieșirii;
5. anularea comenzilor și efectul asupra documentelor fiscale;
6. actualizarea controlată a unei facturi încă modificabile;
7. ștergerea strict controlată a ultimului document eligibil;
8. încasarea, starea plății și rambursarea;
9. automatizările de emitere și trimitere;
10. registrul, căutarea, filtrarea și paginarea;
11. fișa completă a facturii;
12. PDF-ul, Excelul, linkul public și exporturile centralizate;
13. notificările, istoricul și auditul;
14. punctul de extensie pentru o integrare fiscală electronică viitoare;
15. regulile de securitate, concurență, idempotentă și testare.

Backendul și baza de date reprezintă unica sursă de adevăr. Aplicațiile desktop, mobile și web/PWA trebuie să consume aceleași reguli și aceleași endpointuri; nicio regulă fiscală sau de stoc nu trebuie duplicată diferit în clienți.

---

## 1. Delimitări obligatorii pentru noua aplicație

### 1.1. Fără legături comerciale interne specifice

Specificația nu include:

- nume de furnizori;
- coduri sau convenții proprii unui anumit furnizor;
- marketplace-uri concrete;
- sincronizări de preț sau catalog cu o platformă anume;
- reguli care funcționează numai pentru un anumit partener.

Produsele, comenzile, clienții și documentele sunt tratate generic.

### 1.2. Fără implementarea detaliată a traseului loturilor

Modulul trebuie să ofere un punct de integrare pentru asocierea ieșirii cu loturile de intrare și cu costul contabil al mărfii, însă algoritmul complet de alegere a lotului nu face parte din această specificație.

La emiterea unei facturi trebuie apelat un serviciu generic de alocare a ieșirii. Serviciul poate întoarce:

- alocare completă;
- alocare parțială;
- alocare în așteptare;
- indisponibil temporar.

Lipsa unei alocări complete nu trebuie să dubleze factura și nu trebuie să piardă mișcarea de stoc. Ieșirea rămâne înregistrată, iar legătura cu loturile poate fi completată ulterior.

### 1.3. O singură temă fixă

Noua aplicație va avea o singură identitate vizuală pentru facturi. Nu se implementează:

- selector de temă;
- teme diferite pe utilizator, comandă sau tip de factură;
- atribuire automată de teme;
- configurator de culori pentru facturi;
- alegerea unei teme în momentul emiterii.

PDF-ul și Excelul folosesc aceeași prezentare fixă, stabilită la nivelul aplicației.

### 1.4. Fără alegerea următorului număr

În configurator nu trebuie să existe:

- „următorul număr”;
- „setează numărul de pornire” după ce seria este folosită;
- editarea manuală a contorului;
- alegerea numărului la emitere;
- regenerarea unui număr din interfață.

Numărul se alocă exclusiv de backend, atomic și automat. Operatorul vede numărul numai după emitere.

Poate exista configurarea seriei active înainte de folosire. După ce o serie a emis documente, modificarea ei se face numai prin activarea unei serii noi, nu prin rescrierea istoricului.

### 1.5. Fără reguli concrete pentru SPV sau altă autoritate

Nu se implementează în această etapă:

- autentificare la o autoritate fiscală;
- reguli concrete de termen;
- formate oficiale impuse de o autoritate;
- endpointuri oficiale;
- tokenuri, certificate sau secrete;
- reguli de validare specifice unei platforme;
- programarea transmiterii;
- interpretarea răspunsurilor unei autorități.

Se păstrează numai un contract generic pentru o integrare fiscală electronică viitoare. Integrarea se va adăuga separat, fără modificarea regulilor de emitere, stoc, retur și audit descrise aici.

---

## 2. Principii funcționale fundamentale

1. O factură este un instantaneu fiscal al comenzii la momentul emiterii.
2. Istoricul unei facturi nu se modifică retroactiv atunci când se redenumește un produs sau se schimbă datele din catalog.
3. Orice emitere este idempotentă: aceeași comandă nu poate produce accidental două facturi pozitive.
4. Numărul documentului se alocă în aceeași tranzacție în care se salvează factura.
5. Factura pozitivă produce ieșirea din stoc pentru pozițiile urmărite.
6. Ieșirea din stoc și factura trebuie să fie confirmate sau anulate împreună.
7. Factura de retur este un document valoric negativ; ea nu reprezintă singură dovada recepției fizice a bunurilor.
8. Reintrarea în stoc a unui retur se face numai printr-o acțiune distinctă de recepție retur.
9. O operațiune de stoc trebuie să aibă document sursă, operator, moment și cantitate înainte/după.
10. Facturile deja blocate de o integrare fiscală externă nu se editează și nu se șterg.
11. Eșecul trimiterii prin e-mail nu anulează factura.
12. Eșecul generării unei copii PDF după commit nu anulează documentul fiscal; copia se poate regenera din instantaneu.
13. Sumele se calculează cu tipuri zecimale exacte, niciodată cu numere binare de tip float.
14. Valorile afișate și exportate se rotunjesc după reguli deterministe.
15. Interfața nu poate ocoli permisiunile și validările backendului.
16. Acțiunile critice sunt protejate împotriva dublului click, cererilor repetate și execuției concurente.
17. Toate aplicațiile client primesc același rezultat pentru aceeași factură.

---

## 3. Terminologie

### 3.1. Factură pozitivă

Document de vânzare cu total pozitiv, emis pentru bunurile și serviciile livrate clientului.

### 3.2. Factură de retur

Document cu valori negative, emis integral sau parțial pentru bunuri returnate ori pentru anularea valorică a unei facturi pozitive.

### 3.3. Factură de corecție negativă

Document negativ care neutralizează anumite poziții dintr-o factură existentă pentru ca acestea să poată fi înlocuite sau corectate.

### 3.4. Factură corectată pozitivă

Document pozitiv nou, emis cu pozițiile corecte sau înlocuitoare după documentul negativ de corecție.

### 3.5. Instantaneu fiscal

Copie imuabilă a tuturor datelor folosite la emitere: vânzător, client, produse, cantități, prețuri, reduceri, TVA, plată, referințe și totaluri.

### 3.6. Stoc comercial

Cantitatea folosită de magazin pentru disponibilitatea la vânzare.

### 3.7. Stoc contabil

Cantitatea justificată de documente de intrare și ieșire.

### 3.8. Mișcare de ieșire

Înregistrarea auditabilă prin care cantitatea aferentă unei linii facturate este scăzută din stoc.

### 3.9. Recepție retur

Operațiunea distinctă care confirmă că bunurile returnate au fost recepționate fizic și pot reintra, integral sau parțial, în stoc.

### 3.10. Document modificabil

Factură care nu a fost transmisă, prelucrată sau blocată de integrarea fiscală externă și pentru care politica aplicației permite încă refacerea controlată a instantaneului.

---

## 4. Roluri și permisiuni

Permisiunile se verifică obligatoriu în backend.

1. **INVOICE_VIEW** — registru și fișă.
2. **INVOICE_ISSUE** — emitere factură pozitivă.
3. **INVOICE_RETURN** — emitere retur integral sau parțial.
4. **INVOICE_CORRECT** — emitere pereche de corecție și înlocuire.
5. **INVOICE_EDIT_UNLOCKED** — actualizare controlată a unei facturi modificabile.
6. **INVOICE_DELETE_LAST** — ștergerea ultimului document eligibil.
7. **INVOICE_EMAIL** — trimitere și retrimitere prin e-mail.
8. **INVOICE_EXPORT** — PDF, Excel și export registru.
9. **INVOICE_PUBLIC_LINK** — generare și copiere link public.
10. **RETURN_RECEIPT_CREATE** — confirmarea recepției fizice a returului.
11. **STOCK_VIEW_MOVEMENTS** — vizualizarea mișcărilor legate de factură.
12. **INVOICE_SETTINGS** — configurarea seriei active, scadenței, notelor și automatizărilor.
13. **FISCAL_INTEGRATION_VIEW** — vizualizarea stării viitoarei integrări externe.
14. **FISCAL_INTEGRATION_ACTION** — acțiune manuală viitoare de transmitere/reîncercare.

Rol recomandat:

- administrator: toate permisiunile;
- manager financiar: vizualizare, emitere, retur, corecție, e-mail, export;
- operator vânzări: vizualizare și emitere în limitele stabilite;
- operator depozit: vizualizare poziții și recepție retur;
- utilizator numai-citire: registru, fișă și export, dacă este autorizat.

Datele sensibile nu se ascund doar vizual. Backendul trebuie să elimine din răspuns câmpurile la care rolul nu are acces.

---

## 5. Modelul de date

### 5.1. Entitatea factură

Factura păstrează cel puțin:

1. identificator intern unic;
2. tipul documentului;
3. seria;
4. numărul;
5. data și ora emiterii;
6. data scadenței;
7. data livrării, dacă este relevantă;
8. moneda;
9. starea valorică;
10. starea plății la momentul emiterii;
11. comanda sursă;
12. factura referită, pentru retur sau corecție;
13. motivul operațiunii;
14. totalul fără TVA;
15. totalul TVA;
16. totalul cu TVA;
17. totalul reducerilor;
18. costul transportului;
19. costul de retur reținut, dacă există;
20. instantaneul fiscal complet;
21. emitentul/operatorul;
22. momentele de creare și actualizare;
23. starea e-mailului;
24. starea generică a integrării fiscale externe;
25. marcaje de blocare și versiune;
26. metadate de audit.

Cheia logică serie + număr este unică.

### 5.2. Poziția facturii

Fiecare poziție păstrează:

- identificatorul liniei;
- identificatorul poziției din comandă;
- identificatorul produsului;
- denumirea în momentul emiterii;
- SKU/codul intern în momentul emiterii;
- imaginea în momentul emiterii sau referința ei;
- unitatea de măsură;
- cantitatea;
- prețul unitar fără TVA;
- prețul unitar cu TVA, dacă este păstrat;
- cota TVA;
- procentul reducerii;
- reducerea valorică;
- baza fără TVA;
- valoarea TVA;
- totalul cu TVA;
- tipul poziției: produs, transport, taxă permisă sau ajustare explicită;
- indicatorul dacă produce mișcare de stoc.

O redenumire ulterioară a produsului nu schimbă poziția facturii.

### 5.3. Referința dintre facturi

Un document negativ trebuie să indice factura pozitivă pe care o corectează. O factură pozitivă de înlocuire trebuie să indice documentul negativ și factura inițială.

Relațiile trebuie să permită afișarea unui lanț:

1. factură inițială;
2. retur sau corecție negativă;
3. factură pozitivă corectată;
4. eventuală recepție fizică a returului.

### 5.4. Mișcarea de stoc

Pentru fiecare linie facturată și urmărită se păstrează:

- produsul;
- comanda;
- factura;
- linia facturii;
- gestiunea;
- tipul mișcării;
- cantitatea cu semn;
- stocul comercial înainte și după;
- stocul contabil înainte și după;
- prețul de vânzare unitar și total;
- operatorul;
- data și ora;
- explicația;
- starea legăturii viitoare cu lotul/costul.

### 5.5. Fișierul generat

PDF-ul, Excelul și viitorul fișier fiscal electronic pot fi:

- generate la cerere din instantaneu;
- stocate imuabil;
- identificate prin tip, dimensiune, hash, versiune și moment.

Fișierul nu este sursa de adevăr; instantaneul din baza de date este sursa de adevăr.

### 5.6. Evenimentul de audit

Auditul păstrează:

- tipul acțiunii;
- documentul și comanda;
- utilizatorul;
- data și ora;
- valorile relevante înainte și după;
- motivul;
- rezultatul;
- identificatorul cererii/idempotency key;
- adresa și agentul clientului, unde politica de securitate permite.

---

## 6. Tipurile de documente

### 6.1. Factură de vânzare

Caracteristici:

- total pozitiv;
- o singură factură pozitivă principală per comandă;
- poate conține produse și transport;
- scade stocul;
- poate fi achitată sau neachitată;
- devine sursă pentru retur ori corecție.

### 6.2. Factură de retur integral

Caracteristici:

- referă factura pozitivă;
- include toate cantitățile eligibile rămase pentru retur;
- valorile produselor sunt negative;
- poate restitui și transportul inițial, conform politicii comerciale;
- poate include separat un cost de retur reținut permis;
- nu adaugă automat produsele în stoc;
- permite ulterior generarea recepției retur.

### 6.3. Factură de retur parțial

Caracteristici:

- referă factura pozitivă;
- include numai produsele și cantitățile acceptate;
- nu poate depăși cantitatea facturată minus cantitățile deja returnate;
- valorile se calculează din instantaneul facturii inițiale;
- nu recitește prețul actual din catalog;
- nu adaugă automat produsele în stoc.

### 6.4. Factură de corecție negativă

Caracteristici:

- neutralizează pozițiile greșite sau înlocuite;
- valorile sunt negative;
- referă factura inițială;
- păstrează exact baza, TVA-ul, reducerile și cantitățile care se corectează;
- nu reprezintă singură recepția fizică.

### 6.5. Factură pozitivă corectată

Caracteristici:

- conține pozițiile corecte ori produsele de înlocuire;
- are număr propriu;
- referă factura inițială și documentul negativ;
- produce ieșirea din stoc pentru noile produse;
- poate conține transportul de înlocuire, dacă politica operațiunii îl cere.

### 6.6. Tipuri care nu fac parte din modul

Nu sunt incluse implicit:

- proforme;
- avize;
- chitanțe;
- bonuri fiscale;
- facturi de achiziție;
- NIR-uri de furnizor;
- documente interne fără efect fiscal.

Acestea pot fi module separate.

---

## 7. Stările documentului și ale plății

### 7.1. Starea documentului

Stări recomandate:

1. **issued** — emis;
2. **return** — retur;
3. **correction_negative** — corecție negativă;
4. **corrected_positive** — document pozitiv corectat;
5. **deleted** — numai în audit, pentru documentul eligibil șters controlat.

Nu se folosește „draft” pentru o factură care a primit deja serie și număr. Formularul dinaintea emiterii este o simulare a comenzii, nu o factură fiscală.

### 7.2. Starea plății comenzii

Stări minime:

- în așteptare;
- plătită;
- eșuată;
- rambursată.

### 7.3. Starea valorică afișată pe factură

- factură pozitivă plătită: **Achitată**;
- factură pozitivă neplătită: **Neachitată**;
- document negativ: **Retur/Corecție**;
- starea rambursării se afișează separat de existența facturii de retur.

O comandă plătită cu cardul este afișată ca achitată numai după confirmarea reală a plății. Simpla alegere a metodei „card” nu înseamnă plată.

### 7.4. Starea generică a integrării fiscale viitoare

Se rezervă stările:

- neconfigurată;
- netransmisă;
- în coadă;
- în procesare;
- acceptată;
- respinsă;
- eroare temporară;
- eroare definitivă.

Aceste stări nu conțin reguli concrete de SPV.

---

## 8. Configurarea modulului

Configuratorul poate conține:

1. seria activă;
2. numărul de zile până la scadență pentru facturile neachitate;
3. observația implicită;
4. datele emitentului;
5. contul bancar și banca;
6. indicatorul de plătitor/neplătitor de TVA;
7. cota implicită permisă;
8. activarea emiterii automate pentru card;
9. activarea e-mailului automat pentru card;
10. activarea emiterii automate pentru ramburs;
11. activarea e-mailului automat pentru ramburs;
12. păstrarea copiilor imuabile;
13. activarea linkurilor publice;
14. politica de retur și transport;
15. destinatarii notificărilor administrative.

Nu conține următorul număr. Nu conține selector de temă.

O opțiune dependentă trebuie validată. De exemplu, e-mailul automat nu poate fi activ dacă emiterea automată corespunzătoare este oprită.

---

## 9. Numerotarea automată

### 9.1. Regula de bază

Toate documentele fiscale emise în seria activă primesc numere consecutive, indiferent dacă sunt:

- facturi pozitive;
- retururi;
- corecții negative;
- documente pozitive corectate.

### 9.2. Alocarea

Backendul:

1. începe tranzacția;
2. blochează contorul seriei;
3. citește cel mai mare număr existent;
4. determină următorul număr sigur;
5. îl rezervă;
6. inserează documentul;
7. finalizează tranzacția.

Un index unic serie + număr reprezintă ultima barieră împotriva duplicării.

### 9.3. Concurența

Două emiteri simultane trebuie să primească numere diferite. Niciun client nu calculează numărul.

### 9.4. Ștergerea ultimului document eligibil

Dacă politica permite ștergerea strictă a ultimului document, contorul se poate reașeza numai după ce:

- s-a verificat că documentul este ultimul global eligibil;
- s-a verificat că este ultimul din serie;
- documentul nu a intrat în nicio transmitere externă;
- toate efectele lui au fost anulate;
- ștergerea a fost confirmată în tranzacție.

Nu se creează goluri artificiale și nu se rescriu numerele documentelor rămase.

---

## 10. Sursa facturii

Factura pozitivă se emite dintr-o comandă existentă. Comanda este sursa operațională, iar factura devine sursa istorică.

Comanda furnizează:

- identitatea clientului;
- adresele;
- datele de contact;
- produsele;
- cantitățile;
- prețurile finale agreate;
- reducerile;
- transportul;
- moneda;
- metoda și starea plății;
- observațiile;
- referința comenzii.

Nu se recomandă emiterea liberă dintr-un formular fără comandă, deoarece s-ar pierde legătura dintre vânzare, stoc, client, plată și audit. Dacă noua aplicație are nevoie ulterior de facturi manuale, ele trebuie proiectate ca un flux separat.

---

## 11. Condițiile pentru emiterea unei facturi pozitive

Înainte de emitere, backendul verifică în această ordine:

1. identificatorul comenzii este valid;
2. comanda există;
3. utilizatorul are permisiune;
4. comanda nu este anulată;
5. comanda nu este rambursată integral;
6. nu există deja o factură pozitivă principală;
7. dacă există deja, operația întoarce factura existentă, nu emite alta;
8. pentru card, plata este confirmată ca plătită;
9. pentru ramburs, emiterea este permisă chiar dacă plata este în așteptare;
10. datele juridice obligatorii ale emitentului sunt complete;
11. clientul are datele obligatorii pentru tipul său;
12. există cel puțin o poziție de produs sau un serviciu de transport permis;
13. cantitățile sunt strict pozitive;
14. prețurile sunt valide;
15. moneda este validă;
16. cotele TVA sunt permise;
17. totalurile recalculate corespund comenzii în toleranța de rotunjire;
18. seria activă este validă.

Comanda se blochează pe durata emiterii pentru a preveni modificarea simultană.

---

## 12. Identitatea clientului

### 12.1. Persoană fizică

Factura păstrează:

- nume complet;
- adresă;
- localitate;
- județ/sector;
- cod poștal;
- țară;
- e-mail;
- telefon.

Câmpurile de companie rămân necompletate.

### 12.2. Persoană juridică

Factura păstrează:

- denumire juridică;
- cod fiscal;
- număr de înregistrare, dacă este folosit;
- sediu social;
- localitate;
- județ/sector;
- cod poștal;
- țară;
- persoană de contact;
- e-mail;
- telefon.

### 12.3. Regula selecției

Tipul clientului fiscal se determină din alegerea făcută la checkout/comandă, nu din tipul contului utilizatorului. Datele de facturare ale companiei au prioritate numai dacă a fost aleasă facturarea pe persoană juridică.

---

## 13. Datele emitentului

Instantaneul emitentului include:

- denumire juridică;
- denumire comercială, dacă se afișează;
- cod fiscal;
- număr de înregistrare;
- adresă;
- localitate;
- județ/sector;
- cod poștal;
- țară;
- e-mail;
- telefon;
- website;
- bancă;
- IBAN;
- capital social, dacă se afișează;
- statut TVA.

Schimbarea ulterioară a datelor firmei nu modifică facturile istorice.

---

## 14. Construirea pozițiilor

### 14.1. Produse

Pentru fiecare poziție din comandă se copiază:

- produsul;
- SKU-ul;
- denumirea;
- imaginea;
- unitatea de măsură;
- cantitatea;
- prețul final;
- reducerea;
- cota TVA;
- identificatorii necesari stocului.

### 14.2. Transport

Transportul apare ca poziție distinctă:

- denumire clară;
- cod generic de serviciu;
- unitate de măsură „serv.”;
- cantitate 1;
- valoare conform comenzii;
- cota TVA aplicabilă.

Transportul nu produce mișcare de stoc.

### 14.3. Reduceri

Reducerea trebuie să rămână inclusă în totalul agreat cu clientul.

Sunt permise:

- reducere pe linie;
- reducere globală distribuită proporțional;
- cod promoțional;
- reducere fixă sau procentuală.

Dacă o reducere globală trebuie distribuită, ultima linie eligibilă absoarbe diferența de rotunjire.

### 14.4. Poziții fără produs intern

O poziție poate fi facturată fără mișcare de stoc numai dacă este definită explicit ca serviciu ori taxă permisă. Nu se transformă accidental un produs în serviciu doar pentru a evita stocul.

---

## 15. Calcule financiare

### 15.1. Precizia

- cantitățile folosesc precizia unității de măsură;
- prețurile unitare interne pot păstra 6–8 zecimale;
- valorile monetare afișate se rotunjesc la 2 zecimale;
- totalurile se recalculează din linii;
- ultima linie eligibilă poate absorbi o diferență de un ban rezultată exclusiv din rotunjire.

### 15.2. Prețuri cu TVA inclus

Dacă prețul public include TVA:

1. baza unitară = preț brut / (1 + cotă TVA);
2. baza liniei = baza unitară × cantitate, după reducere;
3. TVA linie = total brut linie − baza liniei;
4. total brut linie = baza + TVA.

TVA-ul se extrage din preț; nu se adaugă a doua oară.

### 15.3. Emitent neplătitor de TVA

Dacă emitentul nu este plătitor:

- cota efectivă este 0;
- TVA-ul este 0;
- baza este egală cu totalul;
- documentul afișează nota fiscală configurată.

### 15.4. Totalurile

Factura păstrează:

- subtotal produse fără TVA;
- subtotal transport fără TVA;
- reducere totală;
- bază totală;
- TVA total;
- total brut;
- sumă achitată;
- sumă rămasă;
- valoare de rambursat, pentru retur.

### 15.5. Reconcilierea cu comanda

Totalul produselor trebuie să fie egal cu totalul comenzii minus transport, în toleranța de rotunjire. Dacă nu este, emiterea se oprește cu o eroare explicită; nu se emite o factură cu total neexplicat.

---

## 16. Tranzacția de emitere

Emiterea unei facturi pozitive urmează exact această ordine:

1. autentificare și autorizare;
2. validarea cererii;
3. deschiderea tranzacției;
4. blocarea comenzii;
5. verificarea idempotentă a facturii existente;
6. verificarea eligibilității;
7. blocarea contorului seriei;
8. alocarea numărului;
9. construirea instantaneului;
10. recalcularea totalurilor;
11. inserarea facturii;
12. înregistrarea ieșirilor din stoc;
13. apelarea punctului generic de asociere cu loturile/costurile;
14. scrierea istoricului;
15. pregătirea eventualei intrări în coada integrării externe, fără reguli concrete în această etapă;
16. commit;
17. generarea sau încălzirea copiilor PDF/Excel;
18. trimiterea e-mailului, dacă este cerută.

Orice eroare până la commit anulează atât factura, cât și toate mișcările de stoc.

E-mailul și pre-generarea fișierelor rulează după commit. Eșecul lor se raportează separat.

---

## 17. Scăderea din stoc și înregistrarea ieșirii

### 17.1. Regula generală

Pentru fiecare poziție cu produs intern și cantitate pozitivă:

1. produsul este blocat;
2. se determină dacă urmărește stoc comercial;
3. se determină dacă urmărește stoc contabil;
4. se citește cantitatea înainte;
5. se scade cantitatea facturată;
6. se salvează cantitatea după;
7. se creează mișcarea de tip vânzare/ieșire;
8. mișcarea este legată de factură și linia facturii.

### 17.2. Stoc comercial și contabil

- produs urmărit comercial și contabil: se actualizează ambele;
- produs urmărit numai comercial: se actualizează numai disponibilitatea comercială;
- produs urmărit numai contabil: se actualizează numai evidența contabilă;
- produs neurmărit: se păstrează linia fiscală, fără mișcare.

### 17.3. Stoc negativ

Emiterea fiscală nu trebuie să dispară doar pentru că, la acel moment, stocul înregistrat este insuficient. Dacă politica aplicației permite finalizarea unei vânzări deja efectuate, ieșirea poate conduce la stoc negativ, iar deficitul trebuie semnalat clar.

La adăugarea sau înlocuirea voluntară a unui produs înainte de emitere se verifică însă stocul disponibil, pentru a preveni crearea unei noi promisiuni imposibile.

### 17.4. Prevenirea dublei scăderi

Dacă un flux vechi a creat deja o mișcare de vânzare pentru aceeași comandă și linie:

- mișcarea se leagă de factura nouă;
- stocul comercial nu se scade a doua oară;
- efectul contabil lipsă poate fi completat controlat;
- operația este înregistrată în audit.

### 17.5. Câmpurile mișcării

Mișcarea conține:

- tip „sale/outbound”;
- produs;
- cantitate negativă;
- stoc înainte/după;
- stoc contabil înainte/după;
- comandă;
- factură;
- linie;
- gestiune;
- preț de vânzare;
- operator;
- moment;
- notă;
- stare alocare lot/cost.

### 17.6. Punctul de integrare cu loturile

După mișcare se apelează o interfață generică:

- alocă ieșirea pe loturi;
- calculează sau atașează costul;
- întoarce starea;
- permite reconciliere ulterioară.

Detaliile algoritmului nu sunt incluse aici.

---

## 18. Registrul facturilor

### 18.1. Informații în listă

Fiecare rând/card afișează:

- seria și numărul;
- tipul documentului;
- data emiterii;
- comanda;
- clientul;
- tipul clientului;
- totalul;
- moneda;
- metoda de plată;
- starea reală a plății;
- starea e-mailului;
- starea generică a integrării fiscale;
- indicatorul de retur/corecție;
- acțiunea de deschidere.

### 18.2. Sumar

Registrul poate afișa:

- total facturi;
- valoarea facturilor achitate;
- valoarea facturilor neachitate;
- număr și valoare retururi;
- număr documente cu erori operaționale;
- număr recepții retur încă neefectuate.

### 18.3. Paginare

Paginarea este server-side și apare:

- deasupra tabelului;
- sub tabel;
- pe desktop;
- pe telefon/PWA.

Include pagina curentă, totalul paginilor, totalul documentelor și opțional mărimea paginii.

---

## 19. Căutarea și filtrele

### 19.1. Căutarea

Căutarea trebuie să găsească după:

- serie;
- număr;
- seria și numărul scrise împreună;
- număr comandă;
- nume client;
- denumire companie;
- cod fiscal;
- e-mail;
- telefon;
- SKU;
- denumire produs;
- total;
- monedă;
- referința facturii inițiale;
- referința externă viitoare, când integrarea există.

Normalizarea permite căutare fără diferențe de majuscule, diacritice, spații sau separatori uzuali, dar ordinea rezultatelor trebuie să rămână relevantă.

### 19.2. Filtre

Filtre minime:

- toate;
- facturi pozitive;
- retururi;
- corecții;
- achitate;
- neachitate;
- perioadă;
- persoană fizică/juridică;
- metodă de plată;
- stare e-mail;
- stare integrare externă;
- recepție retur necesară/efectuată.

Filtrele și căutarea se combină.

---

## 20. Fișa completă a facturii

Fișa include:

1. antetul documentului;
2. tipul;
3. seria și numărul;
4. starea plății;
5. data emiterii;
6. scadența;
7. referința comenzii;
8. factura referită;
9. datele emitentului;
10. datele clientului;
11. pozițiile;
12. imaginile și SKU-urile;
13. cantitățile și unitățile;
14. prețurile;
15. reducerile;
16. cotele și valorile TVA;
17. subtotalurile;
18. transportul;
19. costul de retur reținut;
20. totalul;
21. metoda și detaliile plății;
22. observațiile;
23. istoricul operațiunilor;
24. starea e-mailului;
25. starea integrării externe;
26. legăturile către documentele asociate;
27. mișcările de stoc;
28. starea recepției retur, când este cazul.

Acțiuni posibile, în funcție de rol și eligibilitate:

- descarcă PDF;
- descarcă Excel;
- obține link public;
- trimite/retrimite e-mail;
- inițiază retur;
- inițiază corecție;
- generează recepție retur;
- vizualizează documentele asociate;
- actualizează factura modificabilă;
- șterge ultimul document eligibil;
- viitoare acțiune de transmitere fiscală.

---

## 21. Plata

### 21.1. Card

Factura pozitivă se emite automat sau manual numai dacă plata este confirmată ca plătită.

Interfața nu afișează „Achitată” doar pentru că metoda este card. Starea plății are prioritate.

### 21.2. Ramburs

Factura poate fi emisă neachitată. Plata rămâne în așteptare până la confirmarea încasării.

### 21.3. Actualizarea stării

Când plata se schimbă:

- factura pozitivă trece între achitată/neachitată, dacă este permis;
- suma achitată și data plății se actualizează;
- copiile stocate se regenerează;
- schimbarea se auditează;
- returul rămâne document de retur, nu devine „achitat”.

### 21.4. Rambursarea

Rambursarea banilor este distinctă de:

- emiterea facturii de retur;
- recepția fizică a produselor;
- reintrarea în stoc.

Cele trei stări trebuie urmărite separat.

---

## 22. Automatizarea emiterii

### 22.1. Flux card

Condiții minime:

- plata este confirmată;
- comanda are starea operațională permisă;
- emiterea automată este activată.

### 22.2. Flux ramburs

Condiții minime:

- comanda este nouă/confirmabilă;
- metoda este ramburs;
- emiterea automată este activată.

### 22.3. Idempotenta automatizării

Pentru fiecare comandă și flux se creează o rulare unică. Ea păstrează:

- starea;
- numărul încercărilor;
- factura;
- dacă e-mailul a fost trimis;
- ultima eroare;
- începutul și finalul.

O a doua cerere concurentă:

- nu emite altă factură;
- nu trimite e-mailul automat a doua oară;
- întoarce rezultatul existent.

O rulare eșuată poate fi reluată controlat.

### 22.4. Dependențe

E-mailul automat se execută numai după emiterea reușită. Dezactivarea emiterii dezactivează logic și e-mailul aferent.

---

## 23. Trimiterea prin e-mail

### 23.1. Conținut

Mesajul conține:

- tipul documentului;
- seria și numărul;
- clientul;
- totalul și moneda;
- data emiterii;
- comanda;
- starea plății sau a returului;
- instrucțiuni utile;
- PDF-ul atașat.

### 23.2. Atașamente

În mod implicit se atașează PDF-ul. Excelul și viitorul XML fiscal se descarcă separat, dacă politica aplicației nu cere altfel.

### 23.3. Trimitere unică automată

E-mailul automat:

- folosește o blocare per factură;
- verifică dacă a fost deja trimis;
- salvează data trimiterii;
- nu dublează mesajul la retry.

### 23.4. Retrimitere manuală

Un utilizator autorizat poate retrimite. Retrimiterea se marchează distinct în audit.

### 23.5. Eșecul e-mailului

Dacă adresa este invalidă ori serverul refuză:

- factura rămâne emisă;
- eroarea se salvează;
- utilizatorul vede motivul;
- operația poate fi reluată.

---

## 24. Linkul public

Dacă funcția este activă:

- linkul conține un token aleator, netransparent;
- tokenul nu expune ID-ul secvențial;
- accesul este numai la documentul permis;
- linkul poate furniza PDF și, opțional, Excel;
- tokenul poate fi revocat;
- accesările se pot audita;
- datele sensibile inutile nu se expun.

Generarea linkului nu modifică factura și nu produce o nouă emitere.

---

## 25. PDF-ul individual

### 25.1. Format general

- A4 portret;
- o singură temă fixă;
- font Unicode;
- logo și identitate vizuală unică;
- numerotare de pagini;
- antet repetabil unde este nevoie;
- subsol cu datele emitentului și starea documentului.

### 25.2. Antet

Antetul afișează:

- emitent;
- denumirea documentului;
- seria și numărul;
- tipul: factură, retur sau corecție;
- data emiterii;
- scadența sau data plății;
- starea vizuală;
- referința comenzii;
- factura inițială, când există.

### 25.3. Blocurile părților

Emitentul și clientul sunt prezentate în două blocuri clare, cu toate datele relevante.

### 25.4. Tabelul pozițiilor

Coloane recomandate:

1. Nr.;
2. Foto;
3. Articol;
4. UM;
5. Cantitate;
6. Preț unitar fără TVA;
7. TVA;
8. Valoare fără TVA;
9. Total.

Sub denumire pot apărea SKU-ul și reducerea.

### 25.5. Totaluri și plată

Zona finală include:

- subtotal;
- reduceri;
- transport;
- cost de retur reținut;
- baza TVA pe cote;
- TVA;
- total;
- achitat;
- de plată/de rambursat;
- metoda plății;
- IBAN/bancă;
- referința tranzacției, dacă există;
- observații.

### 25.6. Retur și corecție

PDF-ul indică vizibil:

- faptul că este document negativ;
- factura referită;
- motivul;
- cantitățile negative;
- totalul negativ;
- suma de rambursat;
- costul de retur reținut, dacă există.

### 25.7. Numele fișierului

Exemple generice:

- Factura-SERIE-NUMAR.pdf;
- Factura-retur-SERIE-NUMAR.pdf;
- Factura-corectie-SERIE-NUMAR.pdf.

---

## 26. Excelul individual

### 26.1. Structură

Un registru denumit „Factură”, în format landscape, cu:

- logo;
- tip și stare;
- emitent;
- client;
- serie/număr;
- date;
- referințe;
- poziții;
- totaluri;
- plată;
- observații.

### 26.2. Coloanele pozițiilor

1. Nr.;
2. Cod/SKU;
3. Produs/serviciu;
4. UM;
5. Cantitate;
6. Preț unitar fără TVA;
7. Reducere %;
8. TVA;
9. Valoare fără TVA;
10. Total.

### 26.3. Reguli tehnice

- sumele sunt celule numerice;
- procentele sunt procente reale;
- formulele pot fi folosite în copie, dar valorile fiscale provin din instantaneu;
- capul de tabel are autofilter;
- rândurile de antet se repetă la tipărire;
- foaia are freeze panes;
- lățimile sunt adaptate;
- subtotalurile și totalurile sunt evidențiate;
- retururile au cantități și valori negative;
- prețul unitar poate rămâne pozitiv pentru lizibilitate.

---

## 27. Exportul registrului

### 27.1. Principiu

Exportul citește direct toate documentele eligibile din baza de date și nu este limitat la pagina afișată ori la primele 1.000 de rezultate.

Poate primi o perioadă, dacă interfața proiectului dorește acest filtru. Exportul fără perioadă include întregul registru autorizat.

### 27.2. Centralizatorul Excel

Coloane recomandate:

1. Factură;
2. Stare integrare fiscală;
3. Tip document;
4. Data emiterii;
5. Ora emiterii;
6. Scadență;
7. Serie;
8. Număr;
9. Comandă;
10. Factură referință;
11. Client;
12. Tip client;
13. Cod fiscal client;
14. Număr înregistrare;
15. Adresă client;
16. Localitate;
17. Județ/sector;
18. Cod poștal;
19. Țară;
20. E-mail;
21. Telefon;
22. Monedă;
23. Bază fără TVA;
24. TVA;
25. Total factură;
26. Starea plății;
27. Metoda plății;
28. Data plății;
29. Data viitoarei transmiteri fiscale;
30. ID extern viitor;
31. Detalii stare externă;
32. E-mail factură trimis la;
33. Emitent;
34. Cod fiscal emitent;
35. IBAN emitent;
36. Bancă emitent;
37. Observații;
38. Poziții factură.

Pozițiile pot fi serializate lizibil, câte una pe linie, cu SKU, denumire, cantitate, UM, preț, TVA și reducere.

### 27.3. Pachetul complet ZIP

Pachetul poate conține:

- centralizatorul Excel la rădăcină;
- câte un folder sigur pentru fiecare factură;
- PDF-ul facturii;
- Excelul facturii;
- un loc rezervat pentru viitorul fișier fiscal electronic, dacă integrarea este activată.

Numele folderului este derivat sigur din serie și număr. Caracterele invalide sunt eliminate, iar coliziunile sunt detectate.

### 27.4. Exportul este read-only

Exportul:

- nu emite facturi;
- nu schimbă stocul;
- nu trimite e-mail;
- nu transmite fiscal;
- nu modifică stări;
- nu regenerează numere.

Fișierele temporare se șterg după livrare.

---

## 28. Modificarea unei comenzi fără factură

Înainte de facturare, un operator autorizat poate:

- adăuga produs;
- elimina produs;
- înlocui produs;
- schimba cantitatea;
- corecta datele clientului;
- corecta transportul;
- corecta metoda de plată, în limitele fluxului;
- recalcula totalurile.

Validări:

- produs activ;
- preț pozitiv;
- cantitate în limita configurată;
- un produs nu este duplicat accidental;
- rămâne cel puțin o poziție facturabilă;
- stocul este verificat pentru noile promisiuni;
- reducerile și totalul se recalculează determinist.

---

## 29. Actualizarea unei facturi modificabile

### 29.1. Când este permisă

Numai dacă:

- documentul nu a intrat în nicio transmitere fiscală externă;
- nu are identificator extern;
- nu este blocat;
- nu are retur/corecție dependentă incompatibilă;
- utilizatorul are permisiune.

### 29.2. Actualizarea datelor nefinanciare

Pot fi refăcute controlat:

- date client;
- adresă;
- e-mail;
- telefon;
- observații;
- stare plată;
- referințe de plată.

### 29.3. Actualizarea pozițiilor

Dacă se modifică produsele unei facturi încă modificabile:

1. se blochează factura și comanda;
2. se inversează mișcările de ieșire existente;
3. se actualizează comanda;
4. se recalculează totalurile;
5. se reconstruiește instantaneul;
6. se înregistrează noile ieșiri;
7. se reapelază integrarea generică lot/cost;
8. factura își păstrează seria și numărul;
9. copiile stocate se regenerează;
10. e-mailul trimis anterior se marchează pentru retrimitere controlată;
11. starea integrării fiscale viitoare rămâne netransmisă.

Totul se execută atomic.

### 29.4. Înghețarea prețului agreat

Modificarea catalogului nu trebuie să schimbe retroactiv prețul comenzii. La eliminarea unei promoții sau schimbarea structurii, prețurile finale deja acceptate se conservă explicit în pozițiile rămase.

---

## 30. Corecția unei facturi blocate

Dacă factura nu mai este modificabilă, nu se rescrie.

Fluxul este:

1. alegerea pozițiilor greșite;
2. alegerea cantităților;
3. introducerea motivului;
4. emiterea facturii de corecție negative;
5. dacă există retur fizic, recepția se confirmă separat;
6. actualizarea comenzii cu produsele corecte;
7. emiterea facturii pozitive corectate;
8. scăderea noilor produse din stoc;
9. legarea tuturor documentelor;
10. trimiterea documentelor și actualizarea istoricului.

Nu se permite un lanț nelimitat de corecții automate greu de urmărit. După o corecție complexă, o nouă modificare poate necesita revizuire manuală.

---

## 31. Inițierea returului

Cererea de retur păstrează:

- comanda;
- factura pozitivă;
- clientul;
- data cererii;
- motivul general;
- produse și cantități solicitate;
- prețurile istorice;
- metoda de restituire;
- IBAN și titular, dacă este necesar;
- cine suportă transportul de retur;
- costul transportului de retur;
- documente sau imagini atașate, dacă politica le permite;
- istoricul deciziilor.

Eligibilitatea returului se stabilește printr-o politică configurabilă, în funcție de tipul clientului, data livrării, produs și regulile comerciale aplicabile.

---

## 32. Analiza returului

Operatorul decide pentru fiecare linie:

- acceptat integral;
- acceptat parțial;
- refuzat;
- cantitate acceptată;
- motiv.

Reguli:

1. fiecare linie trebuie soluționată;
2. cantitatea acceptată nu poate depăși cantitatea solicitată;
3. totalul returnat cumulat nu poate depăși cantitatea facturată;
4. pentru emiterea unei facturi de retur trebuie să existe cel puțin o cantitate acceptată;
5. refuzul integral nu emite document fiscal negativ;
6. decizia se auditează.

---

## 33. Emiterea facturii de retur

### 33.1. Sursa valorilor

Valorile provin din factura inițială:

- preț;
- reducere;
- TVA;
- monedă;
- cantitate eligibilă.

Nu se folosesc prețurile actuale din catalog.

### 33.2. Semnul valorilor

- cantitatea documentară este negativă;
- baza este negativă;
- TVA-ul este negativ;
- totalul este negativ;
- prețul unitar poate fi afișat pozitiv.

### 33.3. Retur parțial

Documentul conține numai cantitățile acceptate.

### 33.4. Transportul inițial

Transportul inițial poate fi restituit numai conform politicii comerciale configurate, de regulă la returul integral eligibil.

### 33.5. Costul returului

Dacă un cost de curier retur este suportat de client:

- apare separat;
- este o valoare pozitivă reținută;
- reduce suma netă de rambursat;
- nu poate depăși nejustificat valoarea restituibilă;
- nu maschează valoarea produselor returnate.

### 33.6. Idempotenta

Aceeași confirmare de retur nu poate emite de două ori aceeași factură. Dacă documentul există, se întoarce cel existent.

### 33.7. Efect asupra stocului

Emiterea facturii de retur nu mărește stocul.

---

## 34. Recepția fizică a returului

### 34.1. Acțiune explicită

Pe fiecare factură de retur eligibilă există acțiunea:

**Generează/confirmă recepția returului**.

Acțiunea apare numai dacă nu există deja o recepție pentru acel document.

### 34.2. De ce nu este automată

Factura negativă confirmă corecția valorică. Ea nu demonstrează că produsele:

- au ajuns fizic;
- au fost verificate;
- sunt în stare vandabilă;
- trebuie introduse integral în stoc.

De aceea recepția este separată.

### 34.3. Efect

La confirmare:

- se creează documentul de recepție retur;
- produsele urmărite contabil reintră în stocul contabil;
- produsele urmărite comercial reintră în stocul comercial, dacă sunt acceptate ca vandabile;
- se creează mișcări de intrare;
- se apelează punctul generic de reversare/reconciliere lot-cost;
- operația devine idempotentă;
- factura de retur este marcată cu recepție efectuată.

### 34.4. Produse nevandabile

Pentru produse defecte, deteriorate sau incomplete, recepția poate:

- intra într-o locație de carantină;
- evita stocul comercial;
- păstra stocul contabil separat;
- genera ulterior casare, service sau alt flux.

### 34.5. Notificarea recurentă

Dacă există facturi de retur fără recepție:

- aplicația notifică utilizatorii responsabili la intrare;
- notificarea poate fi închisă pentru ziua curentă;
- reapare în ziua următoare;
- dispare definitiv numai după recepție sau soluționare explicită.

Notificarea funcționează identic pe desktop, mobil și PWA.

---

## 35. Anularea unei comenzi

### 35.1. Fără factură

Dacă nu există factură:

- comanda se anulează;
- nu se emite retur;
- rezervările și efectele operaționale se eliberează;
- istoricul păstrează motivul;
- plata se tratează separat.

### 35.2. Cu factură pozitivă încă eligibilă pentru ștergere

Dacă factura este strict ultimul document eligibil și nu a intrat în integrarea externă:

- se poate șterge controlat;
- se inversează ieșirile din stoc;
- se șterg mișcările aferente;
- contorul se reașează sigur;
- comanda se anulează.

### 35.3. Cu factură care nu poate fi ștearsă

Se emite retur integral care neutralizează factura.

### 35.4. Plata la anulare

Aplicația păstrează starea anterioară a plății.

Exemple:

- card plătit: plata rămâne plătită până la rambursarea efectivă; se marchează rambursare necesară;
- ramburs neîncasat: poate trece în eșuată/anulată operațional;
- deja rambursată: rămâne rambursată.

### 35.5. Idempotenta

Repetarea anulării nu dublează returul, e-mailul sau mișcările.

---

## 36. Ștergerea controlată a unei facturi pozitive

Ștergerea este o excepție, nu o metodă de corectare uzuală.

Condiții cumulative:

1. utilizator autorizat;
2. document pozitiv normal;
3. ultimul document fiscal eligibil;
4. ultimul număr din serie;
5. fără retur sau corecție dependentă incompatibilă;
6. fără transmitere externă începută;
7. fără identificator extern;
8. fără rambursare finalizată incompatibilă;
9. efectele de stoc pot fi inversate;
10. alocările viitoare lot/cost pot fi reversate;
11. confirmare explicită cu explicație.

Flux:

1. blocare factură, comandă, produse și contor;
2. reverificarea tuturor condițiilor;
3. inversarea mișcărilor;
4. actualizarea stocului;
5. apelarea hookului de reversare lot/cost;
6. ștergerea documentului activ;
7. păstrarea unei urme de audit;
8. recalcularea contorului la maximul rămas;
9. commit;
10. ștergerea copiilor stocate după commit.

Documentele rămase nu se renumerotează.

---

## 37. Ștergerea controlată a unei facturi de retur

Condiții cumulative:

1. este document de retur;
2. este ultimul document eligibil și ultimul număr;
3. nu a fost transmis extern;
4. rambursarea nu a fost finalizată;
5. este returul activ al comenzii;
6. stocul returnat nu a fost consumat ulterior;
7. recepția retur, dacă există, poate fi inversată;
8. documentul de recepție nu este folosit într-un alt flux;
9. utilizator autorizat.

Efecte:

- se inversează recepția retur și intrările aferente, dacă există;
- se elimină documentele dependente permise;
- comanda revine la starea livrată/finalizată;
- starea plății revine exact la starea salvată anterior;
- dacă înainte era în așteptare, revine în așteptare;
- dacă înainte era plătită, revine plătită;
- dacă înainte era eșuată, revine eșuată;
- se trimite notificarea/e-mailul de revenire la livrat, după commit;
- istoricul explică întreaga revenire.

---

## 38. Inversarea stocului la ștergere sau refacere

Pentru fiecare mișcare de ieșire:

1. se blochează produsul;
2. se verifică dacă mișcarea nu a fost deja inversată;
3. se adaugă înapoi exact cantitatea scăzută;
4. se actualizează stocul comercial și/sau contabil potrivit mișcării originale;
5. se marchează sau se elimină mișcarea conform modelului de audit;
6. se apelează reversarea integrării lot/cost.

Pentru o recepție retur:

1. se verifică disponibilitatea cantității reintrate;
2. dacă a fost deja vândută, consumată, casată sau transferată, ștergerea este blocată;
3. altfel cantitatea este scoasă din nou;
4. mișcările de intrare sunt inversate.

Nu se ajustează stocul „la aproximare”; se inversează exact efectele documentului.

---

## 39. Propagarea modificărilor de catalog

### 39.1. Fără factură

O redenumire de produs poate actualiza pozițiile comenzilor încă nefacturate, conform politicii aplicației.

### 39.2. Factură modificabilă

Actualizarea se poate face numai prin refacerea controlată a instantaneului și regenerarea fișierelor.

### 39.3. Factură blocată

Nu se modifică. Denumirea, SKU-ul, imaginea și prețul istoric rămân cele de la emitere.

---

## 40. Integrarea fiscală electronică viitoare

### 40.1. Contract generic

Modulul rezervă o interfață cu operații precum:

- construiește documentul electronic din instantaneu;
- validează local;
- pune în coadă;
- transmite;
- verifică starea;
- salvează referința externă;
- descarcă răspunsul;
- reîncearcă erorile temporare.

### 40.2. Separarea responsabilităților

Serviciul fiscal viitor:

- nu recalculează factura;
- nu schimbă stocul;
- nu alocă numărul;
- nu modifică clientul;
- nu emite un al doilea document;
- serializează și transmite instantaneul deja emis.

### 40.3. Blocarea

Din momentul primei încercări externe, factura devine protejată împotriva editării și ștergerii până când regulile viitoarei integrări stabilesc alt flux.

### 40.4. Securitate

Secretele, certificatele și tokenurile:

- rămân exclusiv pe server;
- nu apar în răspunsurile API;
- nu sunt incluse în loguri;
- sunt rotite separat.

### 40.5. Ce rămâne intenționat nespecificat

- schema oficială;
- autoritatea;
- URL-urile;
- autentificarea;
- termenele;
- programarea;
- validatoarele;
- codurile de eroare;
- procedura juridică.

---

## 41. Notificări

Notificări recomandate:

- factură emisă;
- e-mail trimis/eșuat;
- retur solicitat;
- retur confirmat/refuzat;
- recepție retur necesară;
- recepție retur efectuată;
- stoc negativ rezultat;
- alocare lot/cost incompletă;
- rambursare necesară;
- integrare fiscală cu eroare, după implementarea ei;
- automatizare eșuată.

Notificările administrative indică documentul, comanda, clientul, acțiunea necesară și un link direct.

---

## 42. Istoric și audit

Evenimente obligatorii:

1. emitere;
2. alocare număr;
3. creare mișcări de stoc;
4. actualizare plată;
5. trimitere/retrimitere e-mail;
6. generare link public;
7. export;
8. inițiere retur;
9. decizie retur pe linie;
10. emitere retur;
11. recepție retur;
12. corecție;
13. actualizare factură modificabilă;
14. anulare comandă;
15. ștergere document;
16. inversare stoc;
17. acțiune a integrării fiscale viitoare.

Auditul este append-only. Nu se permite ștergerea urmei doar pentru că documentul activ a fost șters.

---

## 43. Contracte API recomandate

Endpointurile pot fi denumite diferit, dar responsabilitățile trebuie separate:

1. listare facturi cu paginare, căutare și filtre;
2. fișă factură;
3. emitere factură din comandă;
4. actualizare factură modificabilă;
5. trimitere e-mail;
6. generare link public;
7. descărcare PDF;
8. descărcare Excel;
9. export registru;
10. creare cerere retur;
11. soluționare retur;
12. emitere factură retur;
13. confirmare recepție retur;
14. emitere corecție și document pozitiv corectat;
15. ștergere ultim document eligibil;
16. vizualizare mișcări de stoc;
17. vizualizare istoric;
18. citire/salvare configurare;
19. endpoint rezervat pentru integrarea fiscală viitoare.

Fiecare operație mutabilă primește:

- autentificare;
- permisiune;
- token CSRF unde este cazul;
- idempotency key;
- versiunea entității, pentru control optimist;
- motiv, când acțiunea este sensibilă.

---

## 44. Erori funcționale

Erorile trebuie să fie explicite și acționabile:

- comanda nu există;
- factura există deja;
- plata cu cardul nu este confirmată;
- comanda este anulată;
- date client incomplete;
- date emitent incomplete;
- serie invalidă;
- totaluri inconsistente;
- produs inactiv;
- cantitate invalidă;
- retur peste cantitatea eligibilă;
- factură blocată;
- documentul nu este ultimul;
- există o transmitere externă;
- stocul returnat a fost deja consumat;
- recepția retur există deja;
- e-mail invalid;
- generare fișier eșuată;
- conflict concurent;
- permisiune insuficientă.

Mesajul pentru utilizator este clar; detaliul tehnic rămâne în logul serverului.

---

## 45. Securitate

1. Toate mutațiile sunt autentificate.
2. Permisiunile sunt verificate în backend.
3. Interogările sunt parametrizate.
4. Datele de ieșire sunt escapate.
5. Linkurile publice folosesc tokenuri puternice.
6. Fișierele generate nu permit traversarea directoarelor.
7. Numele arhivelor și folderelor sunt sanitizate.
8. PDF-ul și Excelul nu execută conținut introdus de client.
9. Celulele Excel care încep cu caractere de formulă sunt neutralizate când provin din text liber.
10. Logurile nu conțin tokenuri, date complete de card sau secrete.
11. Exporturile respectă permisiunile.
12. Rate limiting-ul protejează emiterea, e-mailul și linkurile publice.
13. Acțiunile distructive cer reconfirmare.

---

## 46. Concurență și idempotentă

Scenarii obligatoriu protejate:

- două clickuri pe „Emite”;
- două automatizări simultane;
- emitere manuală în timp ce rulează automatizarea;
- două retururi pentru aceeași cantitate;
- două recepții pentru același retur;
- ștergere simultană cu emiterea următorului număr;
- actualizarea plății în timpul emiterii;
- modificarea comenzii în timpul facturării;
- două e-mailuri automate;
- două exporturi mari.

Instrumente:

- tranzacții;
- row locking;
- chei unice;
- idempotency keys;
- optimistic locking/version;
- stări de procesare;
- retry numai pentru operații sigure.

---

## 47. Performanță

1. Registrul folosește paginare server-side.
2. Căutarea folosește indici pentru serie, număr, comandă, client, e-mail și cod fiscal.
3. Fișa facturii citește instantaneul fără recalculări costisitoare.
4. PDF-ul/Excelul stocat se reutilizează dacă versiunea nu s-a schimbat.
5. Exporturile mari rulează controlat și folosesc streaming sau fișier temporar.
6. Imaginile sunt redimensionate pentru documente.
7. Trimiterea e-mailului și integrarea fiscală viitoare nu țin blocată interfața.
8. Mișcările de stoc au indici pe produs, factură, comandă și dată.

---

## 48. Tranzacții și limite de commit

În aceeași tranzacție:

- numărul;
- factura;
- pozițiile/instantaneul;
- mișcările de stoc;
- actualizarea comenzii;
- auditul critic;
- marcajele de automatizare.

După commit:

- PDF/Excel cache;
- e-mail;
- notificări externe;
- viitoare transmitere fiscală;
- curățarea fișierelor vechi.

La ștergere, fișierele se elimină numai după commit. Astfel, un rollback al bazei nu lasă factura fără copii.

---

## 49. Matrice minimă de testare

### 49.1. Emitere

- factură ramburs neachitată;
- factură card plătită;
- blocare card neplătit;
- persoană fizică;
- persoană juridică;
- cu și fără TVA;
- cu transport;
- fără transport;
- cu reducere pe linie;
- cu reducere globală;
- două emiteri simultane;
- retry după timeout;
- produs neurmărit în stoc;
- produs cu stoc negativ rezultat.

### 49.2. Stoc

- scădere comercială și contabilă;
- numai comercială;
- numai contabilă;
- fără urmărire;
- prevenirea dublei scăderi;
- rollback integral la eroare;
- inversare la ștergere;
- refacere la actualizarea facturii;
- hook lot/cost indisponibil.

### 49.3. Retur

- retur integral;
- retur parțial;
- două produse, unul refuzat;
- cantitate acceptată parțial;
- tentativă peste cantitatea eligibilă;
- transport inițial restituit;
- cost de retur reținut;
- retry fără document duplicat;
- factură retur fără reintrare automată;
- recepție explicită;
- notificare recurentă;
- produs nevandabil/carantină.

### 49.4. Corecție

- corecție negativă;
- factură pozitivă de înlocuire;
- scăderea produsului nou;
- legături între documente;
- blocarea modificării directe.

### 49.5. Ștergere

- ștergere ultimă factură pozitivă eligibilă;
- blocare dacă nu este ultima;
- blocare dacă există stare externă;
- ștergere retur eligibil;
- restaurarea exactă a stării plății;
- inversarea recepției retur;
- blocare dacă stocul reintrat a fost consumat;
- refacerea contorului fără renumerotare.

### 49.6. Export

- PDF o pagină;
- PDF multipagină;
- diacritice;
- imagini lipsă;
- Excel cu formule și valori numerice;
- retur cu valori negative;
- centralizator complet;
- ZIP cu multe facturi;
- nume de fișiere cu caractere speciale;
- export fără efecte secundare.

### 49.7. E-mail

- trimitere automată unică;
- retrimitere manuală;
- adresă invalidă;
- server indisponibil;
- atașament mare;
- factură rămasă validă după eșec.

---

## 50. Ordinea recomandată de implementare

1. schema și migrațiile;
2. configurarea fără următor număr și fără teme;
3. serviciul de numerotare atomică;
4. calculatorul financiar;
5. instantaneul fiscal;
6. emiterea pozitivă idempotentă;
7. mișcările de ieșire și stoc;
8. hookul generic lot/cost;
9. registrul, căutarea și paginarea;
10. fișa facturii;
11. PDF și Excel;
12. e-mail și link public;
13. automatizările;
14. returul integral și parțial;
15. recepția retur explicită și notificările;
16. corecțiile și înlocuirile;
17. actualizarea facturii modificabile;
18. anularea și ștergerea controlată;
19. exportul centralizat și ZIP;
20. auditul complet;
21. interfața goală pentru integrarea fiscală viitoare;
22. testele de concurență, regresie și acceptanță.

---

## 51. Criterii finale de acceptanță

Modulul este considerat complet numai dacă:

1. emite o singură factură pentru o comandă, inclusiv sub concurență;
2. numerotează automat, fără câmp configurabil pentru următorul număr;
3. nu oferă selector de temă;
4. păstrează instantaneul istoric;
5. calculează corect reducerile, TVA-ul, transportul și rotunjirile;
6. scade stocul în aceeași tranzacție cu factura;
7. înregistrează fiecare ieșire cu trasabilitate;
8. oferă numai un punct generic pentru lot/cost;
9. afișează cardul ca plătit numai după confirmarea plății;
10. emite retur integral și parțial din valorile istorice;
11. nu adaugă automat returul în stoc;
12. permite recepția explicită a fiecărei facturi de retur;
13. notifică zilnic recepțiile retur lipsă;
14. emite corecții fără rescrierea documentelor blocate;
15. inversează exact stocul la o ștergere permisă;
16. restaurează exact starea anterioară a plății;
17. blochează ștergerea dacă există consumuri sau dependențe;
18. generează PDF și Excel complete și lizibile;
19. exportă registrul fără limita paginii;
20. trimite e-mail idempotent și păstrează erorile;
21. oferă aceleași reguli pe desktop, mobil și PWA;
22. păstrează auditul tuturor acțiunilor;
23. conține un punct de extensie fiscală electronică fără reguli SPV concrete;
24. nu include dependențe de furnizori sau marketplace-uri;
25. trece matricea de teste funcționale, de stoc și concurență.

---

## 52. Rezumat operațional

Fluxul normal este:

**Comandă validă → verificare plată și date → număr automat → instantaneu fiscal → factură → ieșire din stoc → mișcare auditabilă → copie PDF/Excel → e-mail.**

Fluxul de retur este:

**Cerere retur → decizie pe fiecare poziție → factură negativă → rambursare separată → recepție fizică explicită → reintrare controlată în stoc.**

Fluxul de corecție pentru un document blocat este:

**Document negativ de corecție → recepție fizică separată dacă există bunuri → document pozitiv corectat → ieșirea noilor produse.**

Fluxul de anulare este:

**Fără factură: anulare simplă. Cu ultima factură eligibilă: ștergere controlată și inversare de stoc. Cu factură neeligibilă pentru ștergere: retur integral.**

Astfel, noua aplicație obține aceeași logică funcțională esențială — facturare, plată, retur, corecție, stoc, export și audit — fără teme multiple, fără alegerea manuală a numerelor, fără dependențe comerciale interne și fără implementarea prematură a regulilor SPV.
