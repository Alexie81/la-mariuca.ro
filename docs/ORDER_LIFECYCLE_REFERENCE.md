# Specificație funcțională completă — produse, comenzi, facturi, retururi și stoc

## 0. Scopul documentului

Acest document descrie, cap-coadă și din perspectiva unei aplicații desktop de administrare, toate funcționalitățile și regulile care leagă:

- catalogul de produse;
- pagina publică a produsului;
- coșul și finalizarea comenzii;
- registrul și fișa comenzii;
- plata;
- factura de vânzare;
- avizul de însoțire;
- integrarea fiscală electronică/SPV;
- anularea comenzii;
- solicitarea și procesarea returului integral sau parțial;
- factura de retur;
- recepția fizică a produselor returnate;
- stocul online, stocul contabil și alocarea pe loturi;
- notificările, istoricul, exporturile și auditul.

Documentul este intenționat generic. El poate fi folosit ca specificație pentru implementarea acelorași capabilități într-un website, fără să depindă de numele magazinului, de un furnizor concret, de o temă vizuală anume sau de un conector comercial specific.

Regulile trebuie implementate în backend. Aplicația desktop și viitorul website sunt două interfețe ale aceluiași sistem, nu două implementări independente ale logicii fiscale sau de stoc.

---

## 1. Principii obligatorii

### 1.1. Backendul este sursa unică de adevăr

Interfața poate ascunde sau dezactiva acțiuni nepermise, dar backendul trebuie să verifice din nou fiecare condiție. Nu este suficient ca un buton să nu fie afișat.

### 1.2. Documentele confirmate sunt instantanee istorice

O factură, un aviz, un NIR sau o solicitare de retur păstrează valorile existente la momentul operațiunii:

- denumirea produsului;
- SKU-ul;
- unitatea de măsură;
- cantitatea;
- prețul;
- reducerea;
- TVA-ul;
- datele clientului;
- adresa;
- metoda și costul transportului;
- datele companiei emitente.

Redenumirea ulterioară a produsului sau schimbarea prețului din catalog nu modifică retroactiv documentele deja blocate.

### 1.3. Operațiunile critice sunt atomice

Emiterea unei facturi, alocarea numărului, scăderea stocului și rezervarea loturilor trebuie să reușească împreună sau să fie anulate împreună.

### 1.4. Operațiunile sunt idempotente

Un dublu click, o reîncercare de rețea sau două cereri concurente nu trebuie să producă:

- două comenzi identice;
- două facturi pozitive pentru aceeași comandă;
- două facturi de retur pentru aceeași confirmare;
- două scăderi de stoc;
- două NIR-uri de recepție retur;
- două încărcări ale aceluiași document în SPV;
- două e-mailuri automate marcate ca fiind prima trimitere.

### 1.5. Factura de retur și recepția fizică sunt operațiuni distincte

Factura de retur corectează valoarea fiscală. Produsul reintră în stoc numai după generarea explicită a recepției retur. Schimbarea statusului în „Rambursată” nu modifică stocul.

### 1.6. Stocul contabil este documentar

Stocul contabil se modifică numai pe baza documentelor confirmate:

- NIR de intrare de la furnizor: intrare;
- factură pozitivă emisă: ieșire;
- NIR de recepție retur client: reintrare;
- document de retur către furnizor, dacă modulul există: ieșire.

Ajustările manuale, sincronizările de marketplace sau modificările operaționale ale stocului online nu apar ca documente contabile dacă nu au document justificativ.

### 1.7. Sumele se calculează exact

Pentru bani și TVA se folosesc tipuri zecimale, reguli deterministe de rotunjire și aceeași funcție de calcul în checkout, comandă, factură și export.

---

## 2. Actori și permisiuni

### 2.1. Client public

Poate:

- consulta produsele;
- adăuga produse în coș;
- plasa o comandă;
- plăti cu metoda disponibilă;
- urmări comanda printr-un link securizat;
- solicita anularea înainte de expediere, în stările eligibile;
- solicita returul după livrare, în termenul aplicabil;
- descărca documentele puse la dispoziție;
- trimite recenzie verificată pentru un produs comandat și eligibil.

### 2.2. Operator comercial

Poate, în limitele drepturilor acordate:

- administra produsele;
- vedea și edita o comandă modificabilă;
- adăuga, elimina sau înlocui produse;
- actualiza statusul comenzii și al plății;
- emite documente;
- comunica cu clientul;
- analiza anulări și retururi;
- genera recepția retur;
- exporta date.

### 2.3. Contabil/administrator fiscal

Poate:

- emite și consulta facturile;
- administra integrarea fiscală;
- transmite/retransmite documente eligibile;
- consulta erori și referințe ANAF;
- efectua ștergerile strict controlate permise;
- consulta registrul și exporturile contabile.

### 2.4. Administrator tehnic

Configurează:

- identitatea companiei;
- seriile active;
- TVA-ul și moneda;
- metodele de plată și livrare;
- integrarea cu procesatorul de plată;
- integrarea SPV;
- e-mailurile;
- permisiunile;
- politicile comerciale și versiunile termenilor.

Acțiunile sensibile trebuie autorizate pe rol, nu doar ascunse în interfață.

---

## 3. Entitățile principale și legăturile dintre ele

### 3.1. Produs

Conține identitatea comercială, conținutul public, prețurile, TVA-ul, stocurile, clasificarea, datele de conformitate, imaginile, specificațiile și starea activă.

### 3.2. Comandă

Conține clientul, adresa, produsele comandate, calculele comerciale, livrarea, plata, statusul, istoricul și legăturile către documente.

### 3.3. Poziție de comandă

Este un instantaneu al produsului în momentul comenzii. Nu trebuie reconstruită ulterior numai din catalog.

### 3.4. Factură pozitivă

Documentul fiscal de ieșire care fixează valoarea vânzării și produce ieșirea contabilă din stoc pentru produsele urmărite.

### 3.5. Aviz

Document logistic asociat comenzii. Nu înlocuiește factura și nu produce a doua mișcare de stoc dacă factura a produs deja ieșirea.

### 3.6. Solicitare de anulare

Cererea clientului sau decizia operatorului de a opri comanda. Efectul fiscal depinde de existența și starea facturii.

### 3.7. Solicitare de retur

Conține produsele, cantitățile, motivul, datele bancare când sunt necesare, costurile estimate și deciziile operatorului pe fiecare poziție.

### 3.8. Factură de retur

Document fiscal cu valori negative care face referire la factura pozitivă și la comandă.

### 3.9. NIR de recepție retur

Document distinct, generat explicit după acceptarea returului, care confirmă reintrarea fizică în stoc a cantităților acceptate.

### 3.10. Mișcare de stoc și alocare FIFO

Mișcarea arată intrarea/ieșirea, documentul sursă, cantitatea și soldul. Alocarea FIFO leagă ieșirea de loturile de intrare și de costurile lor.

### 3.11. Trimitere SPV

Înregistrare separată a încercărilor, mediului, statusului, referinței externe, erorilor și momentelor de transmitere.

---

## 4. Dicționarul stărilor

### 4.1. Statusul comenzii

| Cod intern generic | Etichetă în interfață | Semnificație |
|---|---|---|
| `new` | Nouă | Comanda a fost înregistrată și așteaptă verificarea. |
| `confirmed` | Confirmată | Comanda a fost confirmată comercial. |
| `processing` | În pregătire | Produsele sunt pregătite pentru expediere. |
| `shipped` | Predată curierului | Coletul a părăsit gestiunea. |
| `completed` | Livrată | Livrarea a fost finalizată. |
| `return_requested` | Retur solicitat | Există o solicitare de retur activă. |
| `return_refused` | Retur refuzat | Cererea a fost respinsă integral sau este în starea intermediară de refuz. |
| `return_confirmed` | Retur confirmat | Returul și cantitățile acceptate au fost confirmate. |
| `refunded` | Rambursată | Restituirea financiară a fost finalizată. |
| `cancelled` | Anulată | Comanda a fost anulată. |

Fluxul normal este înainte. Întoarcerea este permisă numai între „Retur solicitat” și „Retur refuzat”, înainte de confirmarea returului. După „Retur confirmat”, pasul operațional următor este „Rambursată”. `cancelled` și `refunded` sunt terminale.

### 4.2. Statusul plății

| Status | Sens |
|---|---|
| În așteptare | Plata nu a fost încă finalizată. |
| Plătită | Încasarea este confirmată. |
| Eșuată | Procesatorul sau operatorul a marcat plata nereușită. |
| De rambursat | Există obligație de restituire. |
| Rambursată | Restituirea a fost confirmată. |

Starea plății nu se deduce exclusiv din statusul comenzii. La revenirea controlată dintr-o anulare/retur, sistemul trebuie să restaureze exact starea anterioară a plății, nu o valoare presupusă.

### 4.3. Statusul facturii

- emisă și modificabilă;
- emisă și blocată;
- achitată/neachitată;
- factură pozitivă;
- factură de retur;
- factură de corecție negativă;
- factură pozitivă corectată.

### 4.4. Statusul SPV

- neconfigurat;
- în așteptarea configurării;
- programat;
- în procesare;
- încărcat/în verificare;
- acceptat/transmis;
- respins;
- eroare temporară;
- eroare definitivă.

---

## 5. Harta generală a fluxului

1. Administratorul creează și publică produsul.
2. Clientul vede produsul, îl adaugă în coș și plasează comanda.
3. Backendul recalculează toate valorile și creează comanda cu instantanee de produs și client.
4. Plata este înregistrată ca ramburs sau este confirmată de procesatorul de card.
5. În registrul de comenzi apare acțiunea potrivită: „Emite”, „Așteaptă plata” sau numărul facturii existente.
6. Operatorul confirmă, pregătește și expediază comanda.
7. Factura fixează datele fiscale și produce ieșirea din stoc/FIFO.
8. Documentul eligibil este transmis în SPV manual sau automat.
9. Clientul urmărește comanda prin link securizat.
10. Înainte de expediere poate solicita anularea.
11. După livrare și în termen poate solicita retur integral sau parțial.
12. Operatorul decide fiecare poziție și cantitate.
13. Confirmarea returului creează, dacă există factura pozitivă, factura de retur.
14. Reintrarea fizică în stoc se face numai prin NIR-ul de recepție retur generat explicit.
15. Rambursarea financiară închide fluxul, dar nu produce singură mișcare de stoc.

---

## 6. Registrul produselor în aplicația desktop

### 6.1. Informații afișate pe fiecare rând

Registrul trebuie să afișeze cel puțin:

- imaginea principală;
- denumirea produsului;
- slug-ul sau identificatorul paginii publice;
- SKU-ul intern;
- codul furnizorului/sursei, dacă există;
- sursa principală;
- prețul public curent;
- prețul vechi și/sau promoțional;
- stocul online;
- stocul fizic/contabil;
- stocul extern, dacă există un conector;
- starea activ/inactiv;
- acțiuni de deschidere, editare și ștergere.

### 6.2. Căutare semantică și relevantă

Căutarea trebuie să normalizeze literele mari/mici, diacriticele și spațiile și să caute după:

- denumire;
- fragmente de denumire;
- SKU;
- cod furnizor;
- EAN;
- model;
- producător;
- categorie;
- compatibilitate;
- termeni din descriere și specificații.

Relevanța trebuie ordonată astfel: potrivire exactă de cod, început de cod, denumire exactă, început de denumire, potrivire în denumire, apoi potriviri semantice/tolerante la greșeli. Nu se returnează rezultate slab legate doar pentru că au un cuvânt generic comun.

### 6.3. Filtre și paginare

Sunt utile filtre pentru:

- activ/inactiv;
- categorie;
- producător;
- cu/fără stoc;
- stoc scăzut;
- urmărit contabil/neurmărit;
- sursă.

Paginarea trebuie să existe sus și jos, să păstreze căutarea și filtrele și să afișeze numărul total de rezultate.

---

## 7. Adăugarea și editarea produsului

### 7.1. Identitate

Formularul include:

- sursa produsului;
- SKU intern, unic, opțional generat automat;
- cod furnizor;
- EAN/GTIN;
- denumire unică obligatorie;
- slug unic obligatoriu, generat și editabil controlat;
- producător;
- model;
- unitate de măsură.

### 7.2. Galerie

- maximum configurabil de imagini, de exemplu 12;
- formate JPEG, PNG și WebP;
- reordonare prin drag-and-drop;
- prima imagine este imaginea principală;
- ștergere individuală;
- text alternativ pentru accesibilitate și SEO;
- validare de dimensiune și tip pe server;
- numele fișierului clientului nu se folosește ca path direct.

### 7.3. Conținut comercial

- descriere scurtă;
- titlu extins;
- descriere lungă în editor bogat;
- formatare cu bold, italic, subliniere și liste;
- imagini în conținut;
- reordonare și redimensionare controlată;
- sanitizare HTML în backend pentru eliminarea scripturilor și atributelor periculoase.

### 7.4. Specificații

Specificațiile sunt introduse repetabil ca:

- grupă;
- denumire;
- valoare;
- ordine.

Interfața publică le grupează vizual, iar exporturile păstrează ordinea stabilită. Rândurile goale nu se salvează.

### 7.5. Întrebări și răspunsuri

Operatorul poate adăuga perechi întrebare–răspuns, reordona și șterge. Conținutul este afișat în zona FAQ și poate alimenta date structurate numai dacă este vizibil utilizatorului.

### 7.6. Preț, reducere și TVA

Formularul include:

- preț public obligatoriu și mai mare ca zero;
- cota TVA aplicabilă;
- reducere procentuală sau fixă;
- preț anterior, dacă este justificat;
- moneda;
- preț de referință extern, dacă există conector;
- marjă configurabilă, dacă politica magazinului o folosește.

Validări:

- procentul este între 0 și sub 100%;
- reducerea fixă este mai mică decât prețul;
- prețul final nu poate fi negativ sau zero;
- costul de achiziție contabil nu se introduce arbitrar în catalog, ci provine din documentele de intrare și loturi.

### 7.7. Clasificare și compatibilitate

- categorie și subcategorie;
- brand/producător;
- branduri și modele compatibile;
- etichete;
- produs recomandat/featured.

### 7.8. Garanție și conformitate

Se pot salva:

- garanție legală și comercială;
- adresă și e-mail producător;
- persoana responsabilă în UE;
- marcaj CE;
- identificator de model;
- avertismente de siguranță;
- documente de conformitate;
- manuale;
- informații despre actualizări software;
- reparabilitate și disponibilitatea pieselor.

### 7.9. Stoc

Produsul poate fi:

- cu stoc urmărit;
- cu stoc nelimitat/neurmărit;
- urmărit contabil;
- neurmărit contabil.

Se configurează:

- cantitatea online;
- pragul de stoc scăzut;
- disponibilitatea publică;
- comportamentul la lipsa stocului.

Dezactivarea urmăririi contabile este permisă numai dacă stocul contabil este zero, nu există loturi deschise și nu există alocări de cost în așteptare.

### 7.10. SEO

- meta titlu;
- meta descriere;
- previzualizare Google;
- canonical;
- date structurate Product/Offer;
- disponibilitate, preț, monedă, retur și livrare;
- sitemap și indexabilitate.

### 7.11. Salvarea produsului

Backendul verifică unicitatea numelui, SKU-ului, EAN-ului și slug-ului, validitatea prețurilor și consistența imaginilor. Salvarea actualizează tranzacțional produsul și relațiile lui.

După commit se pot executa sincronizări externe, regenerarea paginii SEO și notificarea abonaților. Eșecul unei sincronizări externe produce avertisment și reîncercare, dar nu anulează produsul salvat cu succes.

---

## 8. Fișa produsului în aplicația desktop

Fișa completă trebuie să includă:

### 8.1. Identificare

- denumire;
- SKU;
- EAN;
- cod furnizor;
- producător și model;
- categorii;
- stare activ/inactiv.

### 8.2. Galerie și conținut

- imagine principală și galerie;
- descrierile;
- specificațiile;
- întrebările și răspunsurile;
- documentele de conformitate.

### 8.3. Stoc și indicatori

- stoc online;
- stoc contabil;
- stoc extern;
- prag minim;
- intrări totale;
- ieșiri totale;
- sold;
- eventuale costuri FIFO în așteptare.

### 8.4. Indicatori comerciali

- venit generat;
- cantitate vândută;
- preț mediu de vânzare;
- vizualizări publice;
- număr și medie recenzii.

### 8.5. Istoric de vânzări

Pentru fiecare comandă relevantă:

- numărul comenzii;
- data;
- clientul conform permisiunilor;
- cantitatea;
- prețul instantaneu;
- statusul;
- factura asociată;
- buton de deschidere a comenzii.

### 8.6. Istoric de aprovizionare

- document de intrare;
- furnizor generic;
- dată;
- cantitate;
- cost unitar;
- lot;
- sold rămas;
- legătură către NIR.

### 8.7. Recenzii

Operatorul poate vedea recenziile, statutul verificat, comanda care dovedește achiziția, poate răspunde sau șterge în limitele politicii de moderare. Istoricul moderării se auditează.

---

## 9. Ștergerea produsului

Ștergerea permanentă cere confirmare explicită și permisiune ridicată. În mod normal se recomandă dezactivarea, pentru a păstra istoricul.

La ștergere permanentă:

- produsul dispare din catalog și canalele externe;
- imaginile și fișierele proprii se elimină controlat;
- relațiile cu categorii, branduri și compatibilități se elimină;
- recenziile se tratează conform politicii de retenție;
- `product_id` din pozițiile istorice poate deveni nul, dar instantaneele nume/SKU/preț rămân;
- paginii publice i se generează răspuns 410 sau o redirecționare justificată;
- sitemap-ul se regenerează;
- mișcările și documentele fiscale istorice nu se șterg.

Un produs cu stoc contabil nenul sau cu operațiuni deschise nu se șterge până la regularizare.

---

## 10. Pagina publică a produsului

Pagina publică afișează:

- galerie cu miniaturi și navigare;
- denumire și identificatori utili;
- preț curent și, când este legal justificat, preț anterior;
- disponibilitate;
- selector de cantitate;
- buton „Adaugă în coș”;
- garanție, producător și brand;
- descriere scurtă și lungă;
- specificații grupate;
- recenzii;
- întrebări și răspunsuri;
- date de siguranță și conformitate;
- informații despre livrare și retur.

Cantitatea cerută nu poate depăși stocul disponibil pentru un produs urmărit. Backendul revalidează stocul la checkout.

Recenzia verificată necesită:

- număr de comandă;
- e-mailul comenzii;
- produs prezent în comandă;
- comandă într-o stare finală eligibilă;
- cel mult o recenzie pentru combinația comandă–produs.

---

## 11. Exportul catalogului de produse

Exportul Excel trebuie să exporte toate rezultatele filtrate sau toate produsele selectate, nu numai pagina curentă.

Coloane recomandate:

1. imagine;
2. nume produs;
3. preț sursă/furnizor;
4. preț public;
5. marjă;
6. SKU;
7. cod sursă;
8. sursă;
9. stoc online;
10. stoc contabil;
11. stoc extern;
12. cantitate vândută;
13. vizualizări;
14. cod furnizor;
15. nume furnizor;
16. rating mediu;
17. număr recenzii.

Fișierul include logo, data generării, antet înghețat, filtre, celule numerice reale și imaginile produselor. Dacă un produs are mai multe referințe de furnizor, poate produce mai multe rânduri, cu celulele de produs îmbinate logic. Dacă o imagine obligatorie nu poate fi încărcată, exportul raportează eroarea; nu livrează în tăcere un fișier incomplet.

---

## 12. Coșul de cumpărături

### 12.1. Reguli de cantitate

- minimum 1;
- maximum configurabil, de exemplu 99 pe poziție;
- maximum configurabil de produse distincte, de exemplu 50;
- produsele inactive sau sursele inactive nu pot fi comandate;
- stocul se verifică din nou la fiecare recalculare și la plasarea comenzii.

### 12.2. Calcul

Browserul trimite intenția clientului, nu adevărul financiar. Backendul recalculează:

- prețurile curente;
- promoțiile;
- reducerile pe linie și globale;
- TVA-ul;
- transportul;
- pragul de transport gratuit;
- totalul final.

Un preț trimis de browser nu este acceptat ca preț final fără verificare.

### 12.3. Modificări între coș și checkout

Dacă prețul, stocul sau disponibilitatea s-au schimbat, clientul primește un mesaj explicit, iar comanda nu se plasează până la confirmarea valorilor actualizate.

---

## 13. Checkout și crearea comenzii

### 13.1. Client persoană fizică

Câmpuri obligatorii:

- nume complet;
- telefon;
- e-mail valid;
- adresă;
- localitate;
- județ, acolo unde este cerut operațional;
- metodă de livrare;
- metodă de plată;
- confirmarea corectitudinii datelor;
- acceptarea separată a termenilor și politicii de retur.

Codul poștal poate fi opțional dacă metoda de livrare nu îl cere.

### 13.2. Client persoană juridică

Suplimentar:

- denumire companie;
- CUI/CIF;
- număr de înregistrare;
- sediu social/adresă de facturare;
- persoană de contact.

Tipul cumpărătorului se stabilește din alegerea din checkout, nu din tipul contului utilizatorului.

### 13.3. Consimțăminte

Se salvează:

- versiunea termenilor;
- versiunea politicii de retur;
- momentele acceptării;
- IP și/sau metadatele necesare conform politicii de confidențialitate;
- opțiunea de newsletter separat și neobligatoriu.

### 13.4. Validarea vizuală

La eșec:

- toate câmpurile invalide au contur și mesaj roșu;
- sumarul indică ce lipsește;
- pe mobil și desktop se face scroll lin la primul câmp invalid;
- câmpul primește focus;
- datele deja introduse nu se pierd.

### 13.5. Crearea în backend

Backendul:

1. validează clientul;
2. validează coșul;
3. recalculează valorile;
4. verifică produsele active și stocul;
5. rezervă promoția în mod atomic;
6. creează numărul unic al comenzii;
7. generează tokenul securizat de urmărire;
8. salvează instantaneele produselor și clientului;
9. setează statusul `new` și plata `pending`;
10. inițiază plata sau confirmă fluxul ramburs;
11. trimite notificările numai după commit.

Numărul poate avea formatul `PREFIX-AAAALLZZ-SUFIX_ALEATOR`, dar trebuie să fie unic și imposibil de ghicit secvențial cu ușurință.

### 13.6. Plata cu cardul

- este disponibilă numai dacă procesatorul este configurat;
- checkoutul redirecționează numai către domeniul valid al procesatorului;
- factura nu poate fi emisă cât timp plata nu este confirmată;
- confirmarea se face prin webhook verificat, nu doar prin revenirea browserului;
- evenimentele repetate ale procesatorului sunt idempotente.

### 13.7. Plata ramburs

Comanda poate continua și factura poate fi emisă conform regulilor comerciale, fără confirmarea prealabilă a unei încasări electronice.

---

## 14. Instantaneul poziției de comandă

Fiecare poziție salvează cel puțin:

- ID-ul produsului la momentul comenzii;
- numele;
- SKU-ul;
- unitatea de măsură;
- imaginea principală sau referința ei;
- cantitatea;
- prețul brut unitar acceptat;
- reducerea unitară și totală;
- valoarea netă;
- cota și valoarea TVA;
- totalul liniei;
- costul de achiziție cunoscut, dacă politica îl snapshot-uiește;
- promoția aplicată;
- datele de conformitate esențiale, dacă trebuie păstrate.

Acest instantaneu permite afișarea corectă chiar dacă produsul este ulterior redenumit, dezactivat sau șters.

---

## 15. Registrul comenzilor în aplicația desktop

### 15.1. Coloane pe fiecare rând

1. numărul comenzii și data/ora;
2. clientul și indicator PF/PJ;
3. telefonul și/sau e-mailul;
4. statusul comenzii;
5. metoda de plată;
6. statusul plății;
7. totalul;
8. acțiunea pentru factură;
9. butonul de deschidere a fișei.

Eticheta „Plătită” se afișează numai când există confirmarea reală a încasării. Metoda „Card online” nu este echivalentă cu plata efectuată; o comandă cu card și status „În așteptare” rămâne neplătită.

### 15.2. Regula exactă a butonului de factură

| Situație | Text/acțiune pe rând |
|---|---|
| Există factura pozitivă | Se afișează numărul facturii; clickul deschide fișa facturii. |
| Nu există factură, plata ramburs este eligibilă | Se afișează „Emite”. |
| Nu există factură, cardul este confirmat plătit | Se afișează „Emite”. |
| Nu există factură, cardul nu este plătit | Se afișează „Așteaptă plata”; emiterea este blocată. |
| Comanda este într-o stare terminală fără document eligibil | Butonul de emitere este ascuns/dezactivat, cu explicație. |

Interfața nu trebuie să arate `Emite` pentru o comandă cu card neplătit doar pentru că operatorul are drepturi administrative.

### 15.3. Căutare

Căutarea normalizează diacriticele și caută după:

- număr comandă;
- nume client/contact;
- companie;
- telefon;
- e-mail;
- CUI;
- adresă/localitate;
- dată și oră;
- status;
- metodă și status plată;
- numele produselor;
- SKU-urile produselor.

### 15.4. Filtre

- status comandă;
- retururi;
- metodă de plată;
- status plată;
- interval de date;
- cu/fără factură;
- cu probleme SPV, dacă este util.

### 15.5. Paginare

Paginarea apare atât sus, cât și jos, păstrează filtrele și arată pagina curentă, totalul paginilor și numărul rezultatelor.

### 15.6. Indicatori de sinteză

Pot fi afișate:

- comenzi noi;
- comenzi în pregătire;
- comenzi expediate;
- retururi;
- facturi de retur și valoarea lor;
- total vânzări;
- total încasat;
- sume în așteptare;
- rambursuri de încasat.

---

## 16. Fișa completă a comenzii

### 16.1. Antet

- număr comandă;
- dată și oră;
- status;
- status plată;
- total;
- sursa comenzii;
- operator responsabil;
- acțiuni rapide.

### 16.2. Client

- tip PF/PJ;
- nume sau companie;
- date fiscale;
- persoană de contact;
- telefon;
- e-mail;
- buton apel;
- buton WhatsApp;
- buton pentru mesajul WhatsApp al statusului curent.

Datele pot fi editate numai cât timp factura nu este blocată de SPV sau de un lanț de corecție. O factură pozitivă încă netransmisă și modificabilă se regenerează controlat din aceleași instantanee actualizate.

### 16.3. Livrare

- adresă;
- localitate;
- județ;
- cod poștal;
- metodă de livrare;
- cost transport;
- AWB/referință curier, dacă există;
- metoda de plată.

Adresa, localitatea și județul trebuie validate. Modificarea transportului recalculează totalul numai în condițiile permise de starea fiscală.

### 16.4. Produse

Pentru fiecare poziție:

- imagine;
- nume snapshot;
- SKU snapshot și funcție de copiere;
- cantitate;
- unitate de măsură;
- preț unitar;
- reducere;
- TVA;
- total linie;
- buton de deschidere a fișei produsului, dacă produsul încă există;
- acțiuni de schimbare sau ștergere când sunt permise.

### 16.5. Totaluri

- subtotal produse;
- reduceri;
- transport;
- TVA pe cote;
- total general;
- suma încasată;
- suma de rambursat.

### 16.6. Documente

Blocuri separate pentru:

- factura pozitivă;
- aviz;
- factura de retur/corecție;
- recepția retur;
- documentele SPV.

### 16.7. Status și comunicare

- selector status;
- trimitere opțională a e-mailului de status;
- buton separat „Trimite statusul curent”;
- motiv anulare/retur;
- status plată;
- note interne;
- note client;
- cronologie completă.

Salvarea aceluiași status nu retrimite automat e-mailul. Retrimiterea se face explicit.

### 16.8. WhatsApp

Butonul de mesaj precompletat este activ pentru stările comerciale relevante: Nouă, Confirmată, În pregătire, Predată curierului și Livrată. Necesită telefon valid.

Mesajul poate include:

- formulă de adresare;
- nume client;
- produse;
- transport;
- total;
- metodă de plată;
- status;
- linkul securizat de urmărire;
- la livrare, linkuri directe de recenzie pentru produsele eligibile.

Pentru celelalte statusuri butonul este dezactivat cu explicație.

---

## 17. Tranzițiile statusului comenzii

### 17.1. Flux normal

`Nouă → Confirmată → În pregătire → Predată curierului → Livrată`

Operatorul poate sări înainte dacă are datele necesare. Backendul trebuie să înregistreze coerent tranziția și, când este necesar, pașii intermediari tehnici fără efecte duplicate.

### 17.2. Flux de retur

`Livrată → Retur solicitat → Retur confirmat → Rambursată`

Opțional:

`Retur solicitat → Retur refuzat → Retur solicitat`

după reanalizare, dar numai înainte de confirmare.

### 17.3. Anulare

`Anulată` se aplică prin serviciul dedicat de anulare, nu prin simpla modificare a unui câmp. Serviciul decide tratamentul facturii, stocului și plății.

### 17.4. Efecte după commit

După o tranziție reușită se pot trimite:

- e-mail client;
- notificare internă;
- mesaj WhatsApp inițiat de operator;
- actualizare în pagina publică de urmărire.

Un eșec de e-mail nu anulează tranziția. Se păstrează eroarea și se oferă reîncercare.

### 17.5. Matricea efectelor de status

| Status nou | E-mail implicit | Factură | Stoc | Acțiune publică |
|---|---|---|---|---|
| Nouă | confirmarea înregistrării | nu se emite doar din schimbarea statusului | fără mișcare | anulare eligibilă |
| Confirmată | confirmare comercială | poate deveni eligibilă conform plății/politicii | numai prin factură | anulare eligibilă |
| În pregătire | informare pregătire | nicio duplicare | numai prin document | anulare eligibilă |
| Predată curierului | informare expediere | documentul existent rămâne | fără mișcare nouă dacă ieșirea există | anularea publică dispare |
| Livrată | confirmare livrare și recenzii | neschimbată | neschimbat | retur eligibil în termen |
| Retur solicitat | confirmare cerere | fără retur fiscal încă | fără reintrare | afișează starea curentă |
| Retur refuzat | motivul deciziei | fără retur fiscal la refuz integral | fără reintrare | arată refuzul |
| Retur confirmat | confirmare | retur fiscal idempotent dacă există original | încă fără reintrare | arată confirmarea |
| Rambursată | confirmare rambursare | neschimbată | fără efect | flux închis |
| Anulată | confirmare anulare | ștergere sigură sau retur, după caz | inversare numai documentară | flux închis |

---

## 18. Modificarea produselor din comandă — reguli comune

Adăugarea, ștergerea și înlocuirea sunt blocate în stările:

- retur solicitat;
- retur refuzat;
- retur confirmat;
- rambursată;
- anulată.

Sunt de asemenea blocate dacă:

- factura este în proces SPV;
- factura a fost transmisă în SPV, cu excepția fluxului formal de corecție;
- există deja un lanț de corecție complex incompatibil;
- produsul țintă este inactiv;
- stocul este insuficient;
- cererea ar produce o comandă fără produse.

Toate modificările recalculează totalurile în backend și actualizează avizul modificabil. Numele, prețurile și reducerile istorice rămân snapshot-uri.

---

## 19. Adăugarea unui produs în comandă

### 19.1. Interfață

Operatorul apasă „Adaugă produs”, caută semantic produsul, alege cantitatea și vede:

- disponibilitatea;
- prețul public curent;
- prețul propus pentru comandă;
- TVA-ul;
- totalul poziției.

### 19.2. Validări

- cantitatea este între 1 și limita configurată;
- prețul unitar este pozitiv;
- produsul este activ;
- sursa produsului este activă;
- produsul nu există deja în comandă; dacă există, se modifică cantitatea prin fluxul dedicat;
- stocul urmărit este suficient;
- factura, dacă există, este încă modificabilă și netransmisă;
- nu există factură corectată care blochează editarea simplă.

### 19.3. Calcul și documente

- valorile deja acceptate pentru produsele existente se păstrează exact;
- produsul nou intră la prețul explicit acceptat de operator;
- promoțiile vechi nu se reaplică automat dacă ar rescrie retroactiv comanda;
- totalurile și TVA-ul se recalculează;
- factura netransmisă se revizuiește păstrând același număr;
- avizul netransmis se revizuiește;
- mișcarea de stoc/FIFO se reface atomic dacă factura produsese deja ieșirea.

---

## 20. Ștergerea unui produs din comandă

### 20.1. Confirmare

Modalul arată produsul, cantitatea și impactul financiar. Ștergerea este blocată dacă ar rămâne zero poziții.

### 20.2. Reguli

- produsul trebuie să existe în comandă;
- se aplică aceleași restricții SPV și de status;
- valorile finale ale produselor rămase se păstrează;
- promoția sau rezervarea asociată poziției eliminate se eliberează controlat;
- factura netransmisă și avizul se regenerează cu același număr/referință;
- stocul și alocările poziției eliminate se inversează exact, nu printr-o ajustare generică.

---

## 21. Înlocuirea unui produs din comandă

### 21.1. Condiții generale

- produsul nou trebuie să fie diferit;
- nu poate dubla o poziție existentă;
- trebuie să fie activ și disponibil;
- prețul poate fi schimbat numai ca parte explicită a înlocuirii;
- se confirmă diferența de preț, cantitatea și transportul;
- o a doua corecție complexă poate fi blocată pentru analiză manuală.

### 21.2. Factură netransmisă și comandă nelivrată

Factura existentă poate fi revizuită păstrând numărul. Backendul:

1. inversează ieșirea produsului vechi;
2. reface alocarea pentru produsul nou;
3. actualizează comanda;
4. regenerează factura și avizul;
5. salvează auditul.

### 21.3. Factură transmisă sau comandă deja livrată

Factura istorică nu se rescrie. Sistemul creează:

1. document negativ de corecție/retur pentru produsul vechi;
2. poziția nouă în comandă;
3. document pozitiv corectat pentru produsul înlocuitor;
4. fluxurile de stoc corespunzătoare.

Pentru o înlocuire după livrare sunt obligatorii:

- factura inițială;
- costul transportului de înlocuire;
- cine suportă transportul de retur;
- valoarea transportului suportat de client, dacă este cazul.

---

## 22. Editarea clientului și livrării

### 22.1. Când este permisă

Este permisă înainte ca factura să fie blocată sau transmisă în SPV. Dacă există o factură încă modificabilă, aceasta și avizul se regenerează controlat.

În comportamentul de referință trebuie diferențiate trei categorii de date:

1. **date de contact editabile**: numele persoanei/persoanei de contact, telefonul și e-mailul;
2. **date de livrare editabile**: adresa, localitatea, județul și codul poștal;
3. **date fiscale PJ afișate istoric**: denumirea firmei, CUI/CIF, numărul Registrului Comerțului și sediul social.

În interfața desktop de referință, datele fiscale PJ sunt afișate în fișa comenzii, dar nu sunt editate de același formular rapid care modifică numele/contactul, telefonul și e-mailul. O aplicație nouă care dorește editarea lor trebuie să aplice aceleași blocări fiscale ca pentru identitatea cumpărătorului și să regenereze numai factura încă netransmisă.

### 22.2. Când este blocată

Este blocată când:

- factura este în procesare SPV;
- factura este acceptată/transmisă;
- există corecții care cer păstrarea identității istorice;
- comanda este într-o stare terminală incompatibilă.

Adresa de livrare trebuie tratată separat de adresa fiscală după blocarea facturii: o corecție pur logistică poate actualiza livrarea și avizul, dar nu rescrie cumpărătorul sau adresa din factura deja intrată în SPV. Dacă modelul de date folosește același câmp pentru ambele, editarea trebuie oprită și datele separate înainte de implementarea website-ului.

### 22.3. Reguli de validare

- numele/compania nu pot fi goale;
- e-mailul trebuie să fie valid;
- telefonul trebuie să conțină un număr minim de cifre;
- adresa, localitatea și județul sunt obligatorii conform metodei de livrare;
- datele fiscale ale PJ sunt validate separat.

---

## 23. Propagarea schimbărilor din catalog

### 23.1. Denumire schimbată

Denumirea nouă se poate propaga numai în:

- comenzi încă editabile;
- facturi netransmise și modificabile;
- avize modificabile.

Nu se propagă în:

- facturi transmise sau în procesare SPV;
- facturi de retur istorice;
- lanțuri de corecție;
- comenzi anulate/rambursate;
- documente exportate și arhivate.

### 23.2. Preț și TVA schimbate

Nu modifică retroactiv comenzile existente. O schimbare de preț în comandă este o acțiune comercială explicită și auditată.

### 23.3. Produs dezactivat

Rămâne vizibil în documentele istorice prin snapshot, dar nu mai poate fi adăugat într-o comandă nouă.

---

## 24. Avizul de însoțire

### 24.1. Generare

În fișa comenzii, dacă nu există aviz, apare „Generează avizul”. Datele se preiau din companie, client, adresă și pozițiile comenzii.

### 24.2. Conținut

- emitent;
- destinatar;
- număr și dată;
- comandă sursă;
- adresă de livrare;
- produse, SKU, UM și cantități;
- transportator/referință, dacă există;
- note și semnături, dacă sunt necesare.

### 24.3. Acțiuni

- vizualizare;
- descărcare PDF;
- trimitere e-mail;
- partajare;
- ștergere numai cât timp documentul este eligibil și nu este referit de un flux blocat.

Avizul nu scade încă o dată stocul dacă factura a produs ieșirea.

---

## 25. Emiterea facturii pozitive din comandă

### 25.1. Eligibilitate

Factura poate fi emisă dacă:

- comanda există și are cel puțin o poziție;
- nu există deja o factură pozitivă activă;
- cumpărătorul și emitentul au datele obligatorii;
- toate calculele sunt valide;
- seria activă este configurată;
- produsele urmărite au stoc/alocare disponibilă;
- plata cu cardul este confirmată;
- pentru ramburs, fluxul comercial permite emiterea;
- comanda nu este într-o stare incompatibilă.

### 25.2. Acțiunea din interfață

Click pe „Emite” deschide un rezumat, nu permite introducerea arbitrară a următorului număr. Operatorul confirmă emiterea și eventual opțiunile permise de e-mail/SPV.

### 25.3. Tranzacția de emitere

Într-o singură tranzacție:

1. se blochează comanda;
2. se verifică din nou eligibilitatea;
3. se rezervă atomic următorul număr;
4. se creează antetul facturii;
5. se salvează pozițiile snapshot;
6. se calculează netul, TVA-ul și totalul;
7. se înregistrează ieșirile de stoc;
8. se alocă FIFO;
9. se leagă factura de comandă;
10. se scrie auditul;
11. se face commit.

După commit:

- se generează PDF/XLSX/XML;
- se trimite e-mailul, dacă este cerut;
- se programează SPV, dacă politica o cere;
- se trimit notificările.

Eșecul PDF-ului sau al e-mailului după commit nu șterge factura; documentele se pot regenera din snapshot.

### 25.4. Număr și serie

- numărul este alocat exclusiv de backend;
- contorul este blocat concurent;
- operatorul nu alege numărul;
- factura de retur folosește seria normală activă și următorul număr din aceeași succesiune;
- un număr se reutilizează numai în excepția controlată a ultimului document netransmis și șters integral.

---

## 26. Conținutul facturii pozitive

### 26.1. Antet

- serie și număr;
- data și ora emiterii;
- scadență;
- moneda;
- numărul comenzii;
- emitentul;
- cumpărătorul PF/PJ;
- adresele;
- identificatorii fiscali;
- metoda de plată;
- cont bancar, dacă este cazul.

### 26.2. Poziții

- număr curent;
- SKU;
- denumire snapshot;
- unitate de măsură;
- cantitate;
- preț unitar net;
- reducere;
- cotă TVA;
- valoare netă;
- valoare TVA;
- total.

Transportul apare ca poziție/serviciu distinct când politica contabilă o cere.

### 26.3. Totaluri și note

- subtotal fără TVA;
- reduceri;
- TVA pe cote;
- total cu TVA;
- sumă achitată/restantă;
- note;
- operator emitent.

---

## 27. Ieșirea din stoc și FIFO la facturare

### 27.1. Produse urmărite contabil

Factura pozitivă creează mișcare de ieșire. Alocarea FIFO consumă cele mai vechi loturi eligibile și salvează:

- lotul/NIR-ul sursă;
- cantitatea consumată;
- costul unitar;
- valoarea de achiziție;
- soldul lotului după alocare.

### 27.2. Produse neurmărite contabil

Nu produc mișcare contabilă. Pot produce numai actualizarea operațională a stocului online, conform tipului produsului.

### 27.3. Lipsă stoc

Nu se permite stoc negativ. Emiterea eșuează fără număr consumat și fără factură parțială.

### 27.4. Inversare

Orice inversare folosește exact mișcările și loturile originale. Nu se adaugă o ajustare generică egală numeric, deoarece aceasta ar pierde proveniența.

---

## 28. Automatizarea facturii

Politica poate avea opțiuni separate pentru:

- emitere automată;
- trimitere e-mail automată;
- programare SPV automată.

Reguli tipice:

- card: eligibil numai după plată confirmată și, dacă politica o cere, comandă confirmată;
- ramburs: eligibil din starea comercială configurată;
- jobul revendică o singură dată comanda;
- erorile temporare se reîncearcă;
- erorile de date necesită intervenție;
- reîncercarea nu creează o a doua factură.

---

## 29. Registrul „Facturi emise”

### 29.1. Ordine și acces

În aplicația desktop poate fi primul modul din zona de vânzări, înaintea comenzilor.

### 29.2. Rezumat

- număr facturi;
- total achitat;
- total neachitat;
- total retururi;
- documente cu erori SPV;
- documente programate.

### 29.3. Căutare semantică

Caută după:

- serie/număr;
- număr comandă;
- client;
- CUI;
- e-mail;
- total;
- monedă;
- referință ANAF;
- index de încărcare/descărcare;
- SKU/nume produs, dacă indexul permite.

### 29.4. Filtre și paginare

- toate/achitate/neachitate;
- pozitive/retur/corecție;
- status SPV;
- interval;
- client;
- paginare sus și jos.

### 29.5. Card/rând factură

Afișează:

- numărul;
- tipul;
- data;
- comanda;
- clientul;
- totalul;
- plata;
- starea SPV și referința;
- programarea;
- e-mail trimis/netrimis;
- avertisment „NIR retur lipsă”, când este cazul;
- buton de deschidere.

---

## 30. Fișa completă a facturii

### 30.1. Informații

- tip și număr;
- emitent;
- client;
- comandă;
- factură de referință, pentru retur/corecție;
- poziții cu imagini;
- totaluri;
- plată și scadență;
- note și emitent uman;
- status SPV, mediu, încercări, erori și referințe.

### 30.2. Acțiuni

- obține/copiere link public securizat;
- trimite e-mail;
- descarcă PDF;
- descarcă XLSX;
- descarcă XML e-Factura;
- transmite manual în SPV;
- generează NIR de recepție retur, pentru retur eligibil;
- șterge numai dacă regulile stricte permit.

### 30.3. Starea acțiunii SPV

- neconfigurat: apare doar acțiunea de configurare;
- programat: se arată data;
- procesare: buton dezactivat și stare vizibilă;
- transmis: buton verde/dezactivat, cu referința;
- respins/eroare: eroarea reală și acțiunea eligibilă de reîncercare.

---

## 31. PDF-ul facturii

PDF-ul A4 include:

- logo și identitate emitent;
- titlu și serie/număr;
- date emitent/cumpărător;
- comandă și referințe;
- tabel cu `Nr.`, fotografie opțională, produs, UM, cantitate, preț net, reducere, TVA, valoare netă și total;
- totaluri;
- plată, scadență și note;
- referința la factura inițială pentru retur;
- valori negative clare pentru factura de retur.

Layoutul trebuie să fie stabil pe mai multe pagini, să repete antetul tabelului și să nu taie rândurile importante.

---

## 32. Excelul facturii

Fișierul conține o foaie structurată cu:

- logo;
- emitent și cumpărător;
- serie/număr, dată, comandă și referințe;
- coloane `Nr.`, `SKU`, `Produs`, `UM`, `Cantitate`, `Preț unitar net`, `Reducere %`, `TVA`, `Valoare netă`, `Total`;
- totaluri și plată;
- valori numerice reale, nu texte;
- antet înghețat;
- filtre;
- formate monetare;
- valori negative la retur.

---

## 33. XML-ul fiscal și linkul public

### 33.1. XML

Se generează din snapshot-ul fiscal, se validează înainte de trimitere și se păstrează versiunea exactă transmisă.

### 33.2. Link public

- folosește token puternic, nu ID secvențial;
- poate expira sau fi revocat;
- afișează numai factura vizată;
- nu dezvăluie documentele altui client;
- permite descărcarea documentelor autorizate;
- accesul se poate audita.

### 33.3. E-mail

Conține sumar, număr factură, comandă, total, link și/sau atașament. Trimiterea se marchează numai după răspunsul acceptat al serviciului de e-mail. Reîncercările nu schimbă factura.

---

## 34. Exportul registrului de facturi

Coloane recomandate:

1. factură;
2. stare SPV;
3. tip document;
4. data și ora;
5. scadență;
6. serie;
7. număr;
8. comandă;
9. factură de referință;
10. tip client;
11. nume/companie;
12. identificator fiscal;
13. adresă;
14. date contact;
15. monedă;
16. net;
17. TVA;
18. total;
19. status plată;
20. metodă plată;
21. data plății;
22. programare SPV;
23. referință SPV;
24. detalii eroare;
25. e-mail;
26. emitent;
27. IBAN/bancă;
28. note;
29. poziții serializate sau foaie separată.

Exportul complet ZIP poate conține:

- centralizatorul Excel;
- câte un folder pentru fiecare factură;
- PDF;
- XLSX;
- XML;
- alte dovezi permise.

Exportul este read-only și nu modifică statusuri.

---

## 35. Integrarea SPV/ANAF

### 35.1. Configurare

- mediu implicit de test;
- producția se activează explicit;
- OAuth2/certificat conform integrării;
- tokenuri criptate;
- accesul la secrete limitat;
- endpointurile mediului nu se amestecă.

În implementarea curentă, familia de endpointuri este de forma `https://api.anaf.ro/{test|prod}/FCTEL/rest`, dar noul proiect trebuie să izoleze acest detaliu într-un adaptor.

### 35.2. Tokenuri

- refresh automat înainte de expirare;
- alertă la expirare/reautorizare;
- niciun token în loguri sau în browser;
- autorizarea producției este explicită.

În configurația actuală de referință, tokenul de acces poate avea valabilitate de până la 90 de zile, iar tokenul de refresh până la 365 de zile. Implementarea trebuie însă să citească expirarea reală din răspunsul furnizorului și să nu presupună permanent aceste durate.

### 35.3. Fără conexiune

Dacă integrarea nu este configurată:

- nu se pretinde că documentul a fost trimis;
- automatizările sunt ascunse/dezactivate;
- documentele pot intra în `awaiting_configuration`;
- există acțiune clară de conectare.

### 35.4. Transmitere

1. se selectează XML-ul validat;
2. se revendică idempotent operațiunea;
3. se încarcă o singură dată;
4. se salvează indexul/referința;
5. se interoghează starea mesajului;
6. se salvează acceptarea sau erorile;
7. se reîncearcă numai erorile temporare.

Acțiunea manuală rămâne disponibilă pentru un document eligibil, indiferent de automatizare.

### 35.5. Automatizare

Politicile pentru facturile pozitive și cele de retur sunt separate:

- manual;
- imediat după emitere;
- programat după 1–5 zile lucrătoare.

Ordinea se bazează pe data și ora reală a emiterii. Calculul zilelor lucrătoare exclude weekendurile și sărbătorile legale configurate.

Sistemul generează avertisment înainte de limita legală configurată, în implementarea de referință cu cinci zile lucrătoare. Acțiunea manuală „Trimite în SPV” rămâne disponibilă pentru orice document eligibil.

### 35.6. Regula de blocare

Din momentul în care factura a intrat în procesul SPV, nu se editează și nu se șterge. Corecțiile se fac prin documente noi referitoare la original.

### 35.7. Afișarea erorilor

Interfața arată mesajul real, mediul, numărul încercărilor, momentul ultimei încercări, referința de upload și recomandarea de remediere. Nu reduce totul la „A apărut o eroare”.

---

## 36. Urmărirea publică a comenzii

### 36.1. Acces

Clientul poate accesa:

- linkul cu token primit prin e-mail/WhatsApp;
- formularul cu număr comandă și e-mailul comenzii.

Nu se cere numele clientului sau numărul facturii. Tokenul permite acces direct fără reintroducerea datelor.

### 36.2. Informații afișate

- număr comandă;
- statusul curent;
- cronologie publică fără note interne;
- produse și cantități;
- total și plată;
- livrare/AWB, dacă este permis;
- documente publice;
- acțiunile de anulare sau retur numai când sunt eligibile.

Pentru retur se afișează numai starea curentă, nu pașii viitori ca și cum ar fi garantați.

### 36.3. Securitate

- rate limiting;
- răspuns generic la autentificare eșuată;
- token hash-uit sau protejat;
- fără enumerarea comenzilor;
- datele sensibile mascate;
- audit minimal al accesului.

---

## 37. Solicitarea publică de anulare

### 37.1. Eligibilitate exactă

Clientul poate solicita anularea numai când comanda este:

- Nouă;
- Confirmată;
- În pregătire.

Butonul este ascuns după predarea curierului. API-ul respinge cererea chiar dacă este apelat direct.

### 37.2. Formular

- motivul anulării;
- confirmare explicită;
- informare despre plată/rambursare;
- datele de identificare deja preluate din comanda autentificată.

### 37.3. Efect

Cererea trece prin serviciul unic de anulare. Nu se schimbă doar statusul. Serviciul verifică factura, SPV, stocul și plata.

### 37.4. Răspuns public

Clientul primește confirmare clară a înregistrării și e-mail durabil. Interfața nu promite rambursare instantanee dacă aceasta necesită procesare.

---

## 38. Anularea manuală de către operator

Operatorul poate iniția anularea inclusiv după expediere/livrare, dacă are drepturi, dar efectul fiscal va fi un retur, nu ștergerea nejustificată a istoricului.

Reguli:

- motiv obligatoriu;
- comenzile deja anulate/rambursate nu se procesează din nou;
- aceeași operațiune folosește serviciul central de anulare;
- se păstrează autorul, data, motivul și starea anterioară;
- notificarea către client este automată după commit.

---

## 39. Arborele fiscal al anulării

### 39.1. Nu există factură

- comanda devine Anulată;
- nu se creează factură;
- rezervările comerciale se eliberează;
- dacă nu a existat ieșire contabilă, nu se inventează o mișcare de stoc;
- plata cu cardul confirmată intră în fluxul de rambursare.

### 39.2. Factura este ultima din sistem și nu a intrat niciodată în SPV

Factura poate fi ștearsă în siguranță, tranzacțional:

- se inversează exact ieșirea și FIFO;
- se șterg artefactele generate;
- se eliberează numărul pentru reutilizare numai dacă politica seriei permite;
- se anulează comanda;
- se auditează excepția.

### 39.3. Factura a intrat în SPV sau nu este ultima

Factura pozitivă rămâne istorică. Sistemul creează factura de retur integrală, cu referință la original și comandă. Stocul nu reintră doar pentru că s-a emis factura negativă.

### 39.4. Plata

- card: se marchează obligația de rambursare, de regulă în maximum 15 zile calendaristice și prin aceeași metodă;
- ramburs: nu se creează automat obligație de restituire dacă nu a existat încasare;
- starea plății este independent auditată.

---

## 40. Eligibilitatea returului public

Returul poate fi solicitat public numai pentru o comandă cu status exact „Livrată”. API-ul respinge:

- comenzi nelivrate;
- anulate;
- rambursate;
- comenzi deja într-un flux de retur;
- cereri în afara termenului.

Politică generică:

- consumator/B2C: 14 zile legale plus, dacă magazinul oferă, extensie comercială până la 30 de zile totale;
- B2B: termen voluntar configurat, de exemplu 14 zile;
- garanția și neconformitatea reprezintă fluxuri distincte.

Pentru retragerea B2C integrală în primele 14 zile se poate include costul livrării standard inițiale. Extensia comercială și B2B nu includ automat acest cost.

---

## 41. Solicitarea returului de către client

### 41.1. Puncte de intrare

- buton din pagina securizată de urmărire;
- link din e-mailul de livrare;
- formular public separat autentificat cu număr comandă și e-mail.

Textul juridic trebuie să fie explicit, de exemplu „Retrageți-vă din contract aici”, iar confirmarea „Confirmați retragerea”. Se păstrează declarația și timestampul.

### 41.2. Selectarea produselor

Clientul poate selecta:

- unul sau mai multe produse;
- toate produsele;
- cantitatea dorită, maximum cantitatea comandată și încă nereturnată.

Se salvează snapshot:

- nume;
- SKU;
- cantitate solicitată;
- valoare unitară rambursabilă;
- total estimat.

Din e-mailul de livrare poate exista un link direct către formularul de recenzie al fiecărui produs. Linkul precompletează în siguranță numărul comenzii, numele și e-mailul, iar clientul completează numai ratingul și textul; backendul verifică din nou dreptul de recenzie.

### 41.3. Motivul

- pentru PF/B2C motivul poate fi opțional și se poate salva „Retragere fără motiv declarat”;
- pentru PJ/B2B motivul este obligatoriu;
- fluxurile de defect/neconformitate pot cere poze și detalii separate.

### 41.4. Date bancare

Când rambursarea nu se poate face automat pe metoda inițială, sunt obligatorii:

- IBAN;
- titular cont.

Datele bancare sunt criptate/restricționate și nu apar în loguri sau notificări generale.

### 41.5. Estimarea rambursării

Formula generală:

`valoarea brută a produselor acceptabile + transportul inițial eligibil - costul returului suportat de client`

Costurile și politica metodei de transport se snapshot-uiesc la cerere, ca să nu se schimbe retroactiv.

### 41.6. Confirmare

Solicitarea publică trimite automat confirmarea prin e-mail. În această etapă:

- nu se emite factură de retur;
- nu se modifică stocul;
- nu se marchează plata rambursată;
- comanda devine „Retur solicitat”.

---

## 42. Crearea manuală a solicitării de retur

Operatorul poate iniția același flux din fișa comenzii. Selectează produsele/cantitățile, motivul, costurile și datele necesare.

Diferență de comunicare:

- cererea publică trimite confirmarea automat;
- la cererea creată de operator există o opțiune explicită de trimitere a e-mailului.

Backendul folosește aceeași validare și aceeași entitate; nu există două logici incompatibile.

---

## 43. Analiza returului de către operator

### 43.1. Decizie pe poziție

Pentru fiecare produs:

- cantitate solicitată;
- cantitate acceptată;
- cantitate refuzată;
- motivul refuzului;
- observații interne;
- eventuale dovezi.

### 43.2. Validări

- cantitatea acceptată este între 0 și cantitatea solicitată;
- toate pozițiile trebuie decise;
- pentru confirmare există cel puțin o unitate acceptată;
- cantitatea totală returnată pentru o poziție nu depășește cantitatea facturată;
- refuzul unei cantități cere motiv.

### 43.3. Rezultat

- toate refuzate: `Retur refuzat`, fără factură și fără stoc;
- unele acceptate: retur parțial;
- toate acceptate: retur integral;
- confirmarea trece în `Retur confirmat`.

Operatorul poate sări înainte, iar backendul înregistrează idempotent pașii intermediari necesari dacă toate datele există.

---

## 44. Factura de retur

### 44.1. Momentul emiterii

La `Retur confirmat`, dacă există factura pozitivă, factura de retur se creează automat și idempotent. Dacă nu există factură pozitivă, nu se inventează un document fiscal.

Dacă factura pozitivă este emisă manual după ce returul fusese deja confirmat, sistemul poate crea perechea pozitivă + retur, cu opțiuni separate de e-mail.

### 44.2. Numerotare

- folosește seria normală activă;
- primește următorul număr din aceeași succesiune;
- nu folosește o serie artificială separată doar pentru retururi.

### 44.3. Conținut

- referință la factura pozitivă;
- referință la comandă;
- numai produsele și cantitățile acceptate;
- valori și cantități negative;
- preț unitar pozitiv;
- TVA corectat proporțional;
- transport inițial eligibil;
- costul de retur reținut, ca ajustare pozitivă care reduce suma de rambursat;
- motiv și note.

În XML, costul reținut poate fi reprezentat prin structura fiscală de tip `AllowanceCharge` configurată ca taxă/charge, astfel încât să reducă rambursarea fără să transforme prețul unitar al produsului într-o valoare negativă neclară.

### 44.4. XML e-Factura

Pentru noile documente de storno/retur se folosește factura de tip 380, `Invoice`, cu cod `FACT1`, valori/cantități negative și `BillingReference` către factura inițială. Documentele istorice de tip `CreditNote` 381 rămân păstrate și interpretabile; nu se rescriu retroactiv.

### 44.5. E-mail și SPV

PDF-ul facturii de retur se trimite o singură dată automat după commit, conform politicii. Documentul intră în coada SPV separată de factura pozitivă.

### 44.6. Stoc

Emiterea facturii de retur nu reintră singură produsele în stoc.

---

## 45. NIR-ul de recepție retur client

### 45.1. Acțiune explicită

În fișa facturii de retur apare „Generează NIR de recepție retur” dacă nu există deja. Nu se generează automat.

### 45.2. Condiții

- există factura de retur;
- returul este confirmat;
- există cel puțin o cantitate acceptată urmărită contabil;
- nu există deja NIR pentru aceeași factură/poziție;
- alocările originale pot fi identificate.

### 45.3. Efect

Documentul creează `Intrare în stoc – Retur client` și restabilește:

- numai cantitățile acceptate;
- loturile FIFO originale;
- costurile originale;
- legătura cu factura pozitivă, factura de retur, comanda și clientul.

Produsele neurmărite contabil actualizează numai stocul online, dacă este cazul. Într-un retur mixt, NIR-ul conține numai liniile contabile.

### 45.4. Conținut

- număr și dată;
- client;
- comandă;
- factura pozitivă;
- factura de retur;
- motiv;
- produs/SKU/UM;
- cantitate acceptată;
- cost FIFO unitar;
- valoare;
- lotul original;
- operator;
- note.

### 45.5. Restricții

NIR-ul de retur este blocat împotriva corecțiilor manuale obișnuite și a inversării arbitrare. Orice anulare se face prin fluxul controlat de ștergere a facturii de retur eligibile.

### 45.6. Notificare persistentă

Dacă factura de retur nu are NIR:

- apare notificare în desktop și mobil;
- notificarea duce direct la factură;
- dacă este închisă, rămâne ascunsă numai în ziua curentă;
- reapare în ziua următoare până la rezolvare.

---

## 46. Rambursarea financiară

Statusul `Rambursată` confirmă că banii au fost restituiți. Acesta:

- actualizează plata;
- salvează metoda, suma, data și referința;
- notifică clientul;
- închide fluxul;
- nu produce nicio mișcare de stoc.

Pentru card se folosește aceeași metodă când este posibil. Pentru transfer se păstrează dovada și accesul la IBAN este restricționat.

---

## 47. Ștergerea controlată a facturii pozitive

Este permisă numai dacă simultan:

- este ultima factură eligibilă din serie/sistem;
- nu a intrat niciodată în SPV;
- nu este referită de un document blocat;
- nu există consecințe ulterioare incompatibile;
- operatorul are permisiune ridicată.

Tranzacția:

1. blochează factura și comanda;
2. inversează exact stocul/FIFO;
3. elimină documentul și artefactele;
4. restaurează legăturile comenzii;
5. eliberează numărul numai conform politicii;
6. scrie auditul.

O factură care a intrat în SPV nu se șterge, indiferent dacă a fost acceptată sau respinsă; se corectează fiscal.

---

## 48. Ștergerea controlată a ultimei facturi de retur

Excepția este permisă numai dacă:

- factura de retur este ultima factură eligibilă;
- nu a fost încărcată niciodată în SPV;
- comanda este încă `Retur confirmat` sau a fost `Anulată` prin acel retur;
- plata nu este deja rambursată;
- stocul reintrat nu a fost consumat;
- NIR-ul de retur nu a fost folosit într-un retur către furnizor;
- operatorul are permisiune.

În aceeași tranzacție:

1. se șterge factura de retur și numărul ei;
2. dacă există NIR de retur, se șterge și se inversează `RETURN_IN` și FIFO;
3. se elimină datele active ale returului/anulării;
4. comanda revine la `Livrată`;
5. plata revine exact la starea salvată înainte: în așteptare, plătită sau eșuată;
6. se scrie istoricul;
7. după commit se trimite e-mailul de livrare.

Dacă oricare condiție eșuează, operațiunea nu face modificări parțiale.

---

## 49. Registrul stocurilor

### 49.1. Produse afișate

Registrul contabil afișează numai produsele urmărite contabil. Un registru operațional separat poate afișa toate produsele.

### 49.2. Căutare

Căutare semantică/tolerantă după:

- produs;
- SKU/cod;
- furnizor;
- brand;
- categorie;
- document.

### 49.3. Coloane

- imagine și produs;
- SKU;
- stoc extern;
- stoc online;
- stoc contabil read-only;
- prag minim;
- alertă;
- acțiune de deschidere.

### 49.4. Fișa stocului

- online/contabil;
- total intrări/ieșiri;
- documente NIR;
- jurnal de mișcări;
- dată și tip;
- document sursă;
- operator;
- cantitate plus/minus;
- sold după;
- preț de vânzare;
- cost FIFO;
- alocări și costuri în așteptare.

### 49.5. Ajustare manuală

Este o corecție operațională cu motiv obligatoriu. Nu apare în exportul contabil al facturilor/NIR-urilor ca și cum ar fi un document fiscal. Dacă legislația cere document justificativ, sistemul trebuie să ceară acel document, nu să redenumească ajustarea.

---

## 50. NIR-ul de intrare și relația cu produsul

La recepție, operatorul introduce generic:

- furnizor;
- număr și dată NIR;
- factura/bonul/documentul sursă;
- gestiune;
- poziții;
- SKU sau asociere cu produsul;
- denumire;
- UM;
- cantitate;
- preț de achiziție;
- TVA;
- valoare;
- lot/serie, dacă este cazul;
- documente atașate;
- note.

Asocierea caută mai întâi codul/SKU-ul exact, apoi referințele furnizorului și potrivirile controlate. O potrivire ambiguă cere confirmare; nu asociază automat pe baza unei denumiri vagi.

După confirmare:

- intră stocul contabil;
- se deschid loturile FIFO;
- costurile devin disponibile pentru ieșiri;
- produsul și fișa de stoc arată documentul;
- NIR-ul poate fi exportat PDF/XLSX conform specificației sale.

Editarea sau ștergerea este blocată dacă loturile au fost consumate, cu excepția unui flux formal care inversează toate dependențele.

---

## 51. Exportul contabil al stocurilor

Exportul include numai mișcări documentate și confirmate. Exclude ajustările interne fără document.

### 51.1. Sumar pe produse

- SKU;
- denumire;
- număr mișcări;
- intrări;
- ieșiri;
- sold final;
- valoare finală;
- cantitate cu cost în așteptare;
- observații.

### 51.2. Stoc pe produse

- nr. crt.;
- SKU;
- denumire;
- UM;
- stoc inițial;
- intrări;
- ieșiri;
- stoc final;
- stoc online curent;
- stoc contabil curent;
- valori inițiale/intrări/ieșiri/finale;
- număr mișcări;
- controale de consistență.

### 51.3. Fișă de magazie per produs

- data și ora;
- tip mișcare;
- document;
- partener;
- seria/numărul documentului de intrare;
- seria/numărul documentului de ieșire;
- cantitate intrată;
- cantitate ieșită;
- sold;
- cost unitar;
- valoare;
- operator;
- note.

### 51.4. Mișcări de stoc

- sens;
- dată;
- tip;
- gestiune;
- document;
- partener;
- comandă;
- date document intrare/ieșire;
- SKU/produs/UM;
- cantitate intrată/ieșită;
- sold;
- cost și valoare achiziție;
- preț și valoare vânzare;
- status FIFO;
- cantitate cu cost în așteptare;
- operator;
- note.

### 51.5. Centralizator documente sursă

- sens;
- tip;
- număr și dată;
- partener;
- gestiune;
- seriile/n numerele documentelor;
- poziții;
- cantități;
- valoare stoc;
- valoare vânzare;
- operator.

---

## 52. Notificări și alerte

Sistemul trebuie să genereze notificări pentru:

- comandă nouă;
- plată card confirmată/eșuată;
- solicitare de anulare;
- comandă anulată;
- solicitare de retur;
- retur refuzat/confirmat;
- factură emisă;
- factură de retur emisă;
- NIR de recepție retur lipsă;
- document SPV ajuns la termen;
- document SPV respins/eroare;
- token SPV aproape expirat;
- stoc scăzut/epuizat;
- e-mail eșuat.

Fiecare notificare are:

- tip;
- severitate;
- titlu și explicație;
- entitate țintă;
- deep-link;
- dată;
- stare citit/închis;
- regulă de reapariție, unde este cazul.

Procesarea oportunistă poate porni după cereri normale ale aplicației și continuă server-side chiar dacă utilizatorul închide pagina. Pentru termene exacte și robustețe este recomandat un cron/worker; vizita unui client nu trebuie să fie singura garanție a unei obligații fiscale.

---

## 53. E-mailuri și comunicare

Șabloane distincte pentru:

- comandă înregistrată;
- confirmată;
- în pregătire;
- predată curierului;
- livrată;
- anulare;
- retur solicitat;
- retur refuzat;
- retur confirmat;
- rambursare;
- factură pozitivă;
- factură de retur.

Reguli:

- datele provin din snapshot-uri;
- linkurile folosesc tokenuri securizate;
- e-mailul este trimis după commit;
- eșecul este auditat și reîncercabil;
- mesajele nu includ date bancare complete;
- clientul nu primește note interne;
- atașamentele sunt regenerate/verificate înainte de trimitere.

---

## 54. Auditul obligatoriu

Se auditează:

- cine a creat/editat produsul;
- schimbări de preț, TVA și stoc;
- cine a creat/editat comanda;
- poziții adăugate, eliminate, înlocuite;
- valorile înainte/după;
- schimbări de client și adresă;
- schimbări de status și plată;
- emiterea/ștergerea facturii;
- generarea/ștergerea NIR-ului de retur;
- cereri și decizii de anulare/retur;
- rambursări;
- încărcări și răspunsuri SPV;
- exporturi sensibile;
- accesări ale linkurilor publice, în limitele legale.

Jurnalul conține operator, moment, sursă, entitate, acțiune, motiv, valori înainte/după și ID de corelație. Nu conține parole, tokenuri, certificate sau IBAN complet în clar.

---

## 55. Securitate și protecția datelor

1. Autorizare server-side pe fiecare endpoint.
2. Protecție CSRF pentru sesiunile web.
3. Validare și sanitizare a tuturor intrărilor.
4. Prepared statements/ORM sigur.
5. Uploaduri validate după conținut, dimensiune și extensie.
6. Tokenuri publice puternice și revocabile.
7. Rate limiting la tracking, checkout, recenzii și retururi.
8. Date bancare și tokenuri criptate.
9. Separarea mediilor test/producție.
10. Permisiuni minime pentru ștergeri și SPV.
11. Backup și restaurare testată.
12. Politici de retenție și anonimizare conforme.

---

## 56. Concurență și idempotentă

Chei idempotente distincte pentru:

- plasare comandă;
- webhook plată;
- emitere factură;
- schimbare status;
- anulare;
- confirmare retur;
- factură retur;
- NIR retur;
- upload SPV;
- e-mail automat.

Blocări/tranzacții pentru:

- numărul facturii;
- comanda editată;
- stoc și loturi;
- promoții;
- cantități returnate;
- starea rambursării.

La conflict, clientul primește stare actualizată și mesaj explicit; nu suprascrie în tăcere o schimbare mai nouă.

---

## 57. Contracte API recomandate

### 57.1. Produse

- listare/căutare/paginare;
- creare;
- editare;
- activare/dezactivare;
- ștergere controlată;
- upload/reordonare imagini;
- fișă și istoric;
- export.

### 57.2. Comenzi

- checkout public;
- listare și căutare;
- fișă;
- editare client/livrare;
- adăugare/eliminare/înlocuire poziție;
- schimbare status;
- schimbare status plată;
- retrimitere status;
- tracking public.

### 57.3. Documente

- emitere factură;
- generare aviz;
- fișă factură;
- PDF/XLSX/XML/link/e-mail;
- registru și export;
- transmitere SPV;
- ștergere controlată.

### 57.4. Anulări și retururi

- eligibilitate;
- solicitare publică;
- solicitare operator;
- decizie pe poziții;
- confirmare/refuz;
- emitere retur;
- NIR recepție retur;
- rambursare;
- rollback controlat.

Fiecare endpoint returnează atât rezultatul, cât și `allowed_actions`/motivele de blocare, astfel încât website-ul să poată reda aceeași logică fără să o reinventeze.

---

## 58. Mesaje de eroare

Mesajele trebuie să fie acționabile:

- „Factura nu poate fi emisă: plata cu cardul este încă în așteptare.”
- „Produsul nu poate fi eliminat: comanda trebuie să păstreze cel puțin o poziție.”
- „Factura nu poate fi editată: a intrat în procesarea SPV.”
- „Cantitatea solicitată la retur depășește cantitatea eligibilă.”
- „NIR-ul de retur nu poate fi șters: stocul reintrat a fost deja consumat.”
- „Produsul nu are suficient stoc contabil pentru emitere.”
- „Comanda a fost modificată de alt utilizator. Datele au fost reîncărcate.”

Interfața păstrează datele completate, evidențiază câmpurile și oferă deep-link spre remediere.

---

## 59. Performanță și scalare

- listele sunt paginate server-side;
- căutarea folosește indexuri dedicate;
- imaginile au variante optimizate;
- exporturile mari se generează asincron;
- PDF/XLSX/XML se cache-uiesc pe versiunea snapshot;
- dashboardul nu recalculează toate documentele la fiecare render;
- pollingul SPV este limitat și centralizat;
- workerul poate fi pornit oportunist, dar obligațiile cu termen folosesc programare fiabilă;
- aplicația desktop nu încarcă integral istoricul tuturor comenzilor/produselor.

---

## 60. Matrice minimă de testare

### 60.1. Produs

- creare completă;
- SKU/nume/slug duplicat;
- reduceri invalide;
- galerie la limită;
- produs fără stoc;
- dezactivarea urmăririi contabile cu sold;
- ștergere cu istoric.

### 60.2. Checkout

- PF și PJ;
- câmp obligatoriu lipsă și scroll la primul;
- termeni neacceptați;
- stoc schimbat concurent;
- preț manipulat din browser;
- card succes/eșec/webhook repetat;
- ramburs.

### 60.3. Comandă

- buton Emite/Așteaptă plata/număr factură;
- adăugare, eliminare, înlocuire;
- ultima poziție;
- factură netransmisă/transmisă;
- concurență între doi operatori;
- căutare și paginare.

### 60.4. Factură și stoc

- emitere cu un lot;
- emitere din mai multe loturi FIFO;
- stoc insuficient;
- dublu click;
- eșec PDF după commit;
- inversare exactă;
- produs mixt urmărit/neurmărit.

### 60.5. Anulare

- fără factură;
- ultima factură netransmisă;
- factură netransmisă, dar nu ultima;
- factură transmisă;
- card plătit;
- ramburs;
- cerere după expediere respinsă public;
- anulare manuală după expediere.

### 60.6. Retur

- B2C ziua 14/15/30/31;
- B2B termen;
- retur integral;
- retur parțial;
- cantitate parțial acceptată;
- refuz integral;
- cost transport inițial eligibil/neeligibil;
- cost retur;
- factură pozitivă absentă;
- factură retur idempotentă;
- NIR retur explicit;
- alertă zilnică NIR lipsă;
- rollback permis/blocat.

### 60.7. SPV

- mediu test;
- producție fără confirmare blocată;
- token expirat;
- upload repetat;
- acceptare;
- respingere cu mesaj;
- eroare temporară și retry;
- document intrat în proces și editare blocată;
- referință ANAF căutabilă.

### 60.8. Exporturi

- PDF pe o pagină și mai multe pagini;
- XLSX cu formule/numere reale;
- diacritice;
- retur cu valori negative;
- ZIP complet;
- export mare;
- imagine indisponibilă;
- registru contabil fără ajustări manuale.

---

## 61. Ordinea recomandată de implementare într-un website nou

1. modelul de date și snapshot-urile;
2. autentificare, roluri și audit;
3. catalogul și fișa produsului;
4. stocul documentar și loturile;
5. coșul și checkoutul;
6. registrul și fișa comenzii;
7. editarea controlată a pozițiilor;
8. factura pozitivă și ieșirea din stoc;
9. PDF/XLSX/XML/link/e-mail;
10. trackingul public;
11. anularea;
12. returul public și analiza operatorului;
13. factura de retur;
14. NIR-ul de recepție retur;
15. rambursarea;
16. integrarea SPV;
17. exporturile centralizate;
18. notificările și automatizările;
19. testele de concurență, securitate și recuperare.

Nu se recomandă implementarea UI-ului înaintea serviciilor de domeniu pentru emitere, anulare și retur. Altfel, aceeași regulă va fi duplicată și va diverge între desktop și website.

---

## 62. Criterii de acceptanță finale

Sistemul este considerat complet numai dacă:

1. fiecare comandă afișează acțiunea corectă de factură;
2. cardul neplătit nu poate produce factură;
3. produsele pot fi adăugate/eliminate/înlocuite numai în condițiile permise;
4. documentele transmise nu sunt rescrise;
5. anularea alege automat ștergere sigură sau factură de retur;
6. clientul poate cere anularea numai înainte de expediere;
7. clientul poate cere retur numai după livrare și în termen;
8. returul parțial funcționează pe produs și cantitate;
9. factura de retur corectează valoarea, dar nu stocul;
10. stocul reintră numai prin NIR-ul explicit de retur;
11. FIFO este inversat/restabilit exact;
12. plata revine la starea anterioară la rollback;
13. SPV este idempotent și blochează editarea documentului;
14. PDF, Excel, XML și ZIP se generează din același snapshot;
15. exportul contabil exclude ajustările fără document;
16. toate acțiunile critice sunt auditate;
17. desktopul și website-ul primesc aceleași `allowed_actions` din backend;
18. dublul click și cererile concurente nu dublează documente sau stoc;
19. notificarea NIR lipsă reapare zilnic până la rezolvare;
20. testele integrale acoperă fluxurile pozitiv, anulare, retur parțial și retur integral.

---

## 63. Rezumatul logicii, într-o singură succesiune

Produsul este creat cu identitate, conținut, preț, TVA, stoc și conformitate. Clientul îl comandă, iar backendul validează și snapshot-uiește toate datele. Comanda apare în registru cu butonul fiscal potrivit. Operatorul poate modifica produsele numai cât timp istoricul fiscal permite. Factura pozitivă alocă numărul, fixează valorile și scade documentar stocul din loturi. SPV transmite o copie XML neschimbată și blochează editarea.

Înainte de expediere, anularea poate opri comanda. Dacă factura poate fi eliminată în siguranță, se inversează exact; altfel se emite retur fiscal. După livrare, clientul poate solicita în termen un retur integral sau parțial. Operatorul decide fiecare cantitate. Confirmarea produce factura de retur, dar bunurile reintră în stoc numai când operatorul generează NIR-ul de recepție retur. Rambursarea închide componenta financiară. Orice excepție este tranzacțională, idempotentă, auditată și vizibilă prin notificări și exporturi.

---

## 64. Matrice completă de editare, anulare și ștergere

Acest capitol concentrează regulile de tip „până când?”, „în ce condiții?” și „ce se întâmplă după?”. El trebuie folosit ca sursă directă pentru `allowed_actions` în API și pentru activarea/dezactivarea butoanelor din desktop sau website.

### 64.1. Pragurile fiscale folosite în toate deciziile

Înaintea oricărei editări, backendul clasifică situația într-una dintre stările următoare:

| Cod conceptual | Situație | Consecință generală |
|---|---|---|
| A | Comandă fără factură | Datele comerciale pot fi modificate dacă statusul comenzii permite. |
| B | Factură emisă, `not_sent`, fără upload SPV | Factura poate fi regenerată controlat, păstrând seria și numărul. |
| C | Factură `not_sent`, dar programată pentru SPV | Se poate edita numai după anularea/recalcularea programării; jobul vechi nu trebuie să trimită XML-ul anterior. |
| D | Factură `processing` sau deja încărcată | Orice editare fiscală și ștergere sunt blocate până la răspuns; după upload, corecția se face prin document nou. |
| E | Factură `sent`, acceptată sau cu referință ANAF | Factura este istorică și imuabilă. |
| F | Factură `rejected`/`error` după ce a primit index de upload | Nu se șterge și nu se rescrie ca document nou cu același număr; se remediază conform răspunsului și se retransmite sau se corectează formal. |
| G | Există deja factură negativă/corectată | Formularul simplu este blocat; o a doua corecție cere analiză fiscală manuală. |
| H | Comandă în retur, rambursată sau anulată | Editarea comercială a pozițiilor este blocată. |

Regula decisivă nu este numai textul `spv_status`, ci faptul că documentul a intrat sau nu efectiv în procesul SPV. Prezența unui index/referințe de upload blochează ștergerea chiar dacă ultima încercare este marcată `error` sau `rejected`.

### 64.2. Matricea comenzilor după status

| Acțiune | Nouă | Confirmată | În pregătire | Predată curierului | Livrată | Retur solicitat/refuzat | Retur confirmat | Rambursată | Anulată |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Editare contact client | Da, sub rezerva facturii | Da | Da | Da | Da, dacă factura permite | Nu pentru schimbări comerciale | Nu | Nu | Nu |
| Editare adresă livrare | Da | Da | Da | Numai corecție logistică justificată | Numai informativ/corecție logistică | Nu | Nu | Nu | Nu |
| Adăugare produs | Da | Da | Da | Da dacă factura permite | Numai dacă nu există blocaj fiscal; nerecomandat fără flux formal | Nu | Nu | Nu | Nu |
| Ștergere produs | Da | Da | Da | Da dacă factura permite | Numai dacă documentele permit; altfel corecție | Nu | Nu | Nu | Nu |
| Înlocuire produs | Da | Da | Da | Da | Da numai cu factura inițială și documente de corecție | Nu | Nu | Nu | Nu |
| Emitere factură pozitivă | Da după regula plății | Da | Da | Da | Da numai dacă nu este Anulată/Rambursată | Da în `Retur solicitat` | Completează perechea pozitivă + retur | Nu | Nu |
| Anulare publică | Da | Da | Da | Nu | Nu | Nu | Nu | Nu | Nu |
| Anulare operator | Da | Da | Da | Da, cu tratament fiscal | Da, cu tratament fiscal | Nu ca anulare simplă | Nu | Nu | Deja finală |
| Retur public | Nu | Nu | Nu | Nu | Da, în termen | Există deja flux | Există deja flux | Nu | Nu |
| Ștergere fizică a comenzii | Nu | Nu | Nu | Nu | Nu | Nu | Nu | Nu | Nu |

„Da” în tabel nu înlocuiește verificările privind factura, SPV-ul, stocul, permisiunile și concurența.

### 64.3. Editarea datelor clientului

#### 64.3.1. Câmpuri editabile în formularul rapid

- numele PF sau persoana de contact PJ;
- telefon;
- e-mail.

#### 64.3.2. Câmpuri fiscale PJ afișate separat

- denumirea juridică;
- CUI/CIF;
- numărul Registrului Comerțului;
- sediul social.

În comportamentul desktop de referință, aceste câmpuri fiscale sunt read-only în fișa rapidă. Dacă noul website le face editabile, trebuie să existe un editor fiscal dedicat, cu validare și audit.

#### 64.3.3. Până când se poate edita

1. **Fără factură:** datele se actualizează în comandă.
2. **Factură netransmisă:** datele se actualizează în comandă, payloadul facturii se regenerează cu același număr, iar avizul existent se regenerează.
3. **Factură în procesare SPV:** formularul este blocat. Se așteaptă răspunsul ANAF.
4. **Factură trimisă/acceptată:** identitatea fiscală nu se mai modifică în factura originală.
5. **Factură respinsă sau în eroare după upload:** nu se presupune că este editabilă; decizia se ia după existența indexului de upload și regulile de corecție.
6. **Retur/anulare/rambursare:** modificarea datelor istorice este blocată; o corectare de contact poate fi păstrată separat numai pentru comunicare, fără a rescrie documentele.

#### 64.3.4. Validări

- numele este obligatoriu;
- telefonul este obligatoriu și normalizat;
- e-mailul, dacă există, trebuie să fie valid;
- numele introdus de operator este normalizat consecvent;
- schimbarea este auditată cu înainte/după;
- e-mailurile viitoare folosesc adresa nouă numai după commit.

### 64.4. Editarea datelor de livrare

#### 64.4.1. Câmpuri

- adresă completă;
- localitate;
- județ;
- cod poștal;
- metoda de livrare afișată;
- costul de livrare afișat.

Adresa, localitatea și județul sunt obligatorii. Codul poștal poate fi opțional dacă metoda de livrare nu îl cere.

#### 64.4.2. Efecte

- comanda se actualizează;
- avizul existent se regenerează;
- factura se regenerează numai dacă este încă netransmisă și adresa respectivă face parte din cumpărătorul fiscal;
- factura transmisă păstrează adresa istorică;
- schimbarea logistică după facturare trebuie păstrată separat de adresa fiscală.

#### 64.4.3. Limită temporală

- înainte de expediere: editare normală, sub rezerva facturii;
- după predarea curierului: numai corecție logistică justificată și sincronizare cu transportatorul;
- după livrare: nu se schimbă istoricul livrării; se poate salva separat o adresă de contact pentru operațiuni viitoare;
- în retur/anulare/rambursare: datele inițiale rămân snapshot istoric.

### 64.5. Cantitatea unei poziții existente

Comportamentul de referință trebuie descris fără ambiguitate:

- cantitatea unui produs deja existent în comandă este afișată în fișă;
- formularul desktop actual nu oferă editarea directă a cantității acestei poziții;
- înlocuirea produsului păstrează cantitatea poziției originale;
- la adăugarea unui produs nou se poate alege cantitatea între 1 și 99;
- cantitatea acceptată la retur se poate modifica separat, dar aceasta nu modifică cantitatea comandată.

#### 64.5.1. Dacă noul website implementează editarea directă a cantității

Aceasta este o extensie și trebuie să respecte următoarele reguli:

1. cantitatea nouă este între 1 și 99;
2. produsul urmărit are stoc suficient pentru diferența pozitivă;
3. cantitatea zero se tratează prin „Șterge produs”, nu ca editare tăcută;
4. fără factură se recalculează comanda;
5. cu factură netransmisă se inversează/repostează exact stocul și FIFO, apoi se regenerează aceeași factură și avizul;
6. după upload SPV sau după livrare nu se rescrie factura; diferența se tratează prin retur/corecție și document pozitiv, după caz;
7. promoțiile se transformă în prețuri finale deja convenite înainte de recalculare;
8. modificarea este auditată cu vechea și noua cantitate.

### 64.6. Prețul unei poziții existente

Comportamentul exact de referință este:

- la adăugarea unui produs nou, operatorul poate introduce prețul unitar convenit;
- prețul trebuie să fie mai mare decât zero și sub limita monetară configurată;
- la înlocuirea cu un alt produs, operatorul poate introduce prețul noului produs;
- pentru același produs existent, modificarea directă a prețului este refuzată;
- schimbarea prețului din catalog nu actualizează comenzile existente;
- prețul istoric al facturii transmise nu se rescrie.

Backendul verifică faptul că `product_id` s-a schimbat înainte de a accepta alt preț. Dacă produsul este același și diferența de preț depășește toleranța de rotunjire, cererea este respinsă.

#### 64.6.1. Dacă noul website permite renegocierea directă a prețului

Aceasta trebuie tratată ca extensie explicită:

- motiv obligatoriu;
- permisiune separată;
- preț pozitiv;
- recalcul net/TVA/total;
- eliminarea controlată a promoției, păstrând valorile finale convenite;
- regenerarea facturii numai cât este netransmisă;
- după SPV, document fiscal de corecție;
- audit înainte/după și operator.

### 64.7. Adăugarea unui produs

Este permisă dacă:

- comanda nu este în retur, rambursare sau anulare;
- produsul este activ;
- sursa lui este activă;
- nu există deja în comandă;
- cantitatea este 1–99;
- prețul unitar este pozitiv;
- stocul urmărit este suficient;
- nu există factură trimisă sau în procesare SPV;
- nu există factură pozitivă corectată care blochează o nouă editare simplă.

La salvare:

1. prețurile finale ale pozițiilor vechi sunt fixate;
2. promoția veche este eliberată și scoasă din comandă;
3. noua poziție este adăugată;
4. subtotalul, TVA-ul și totalul se recalculează;
5. factura netransmisă se regenerează cu același număr;
6. ieșirile de stoc și FIFO se reconstruiesc atomic;
7. avizul se regenerează;
8. modificarea se auditează.

### 64.8. Ștergerea unui produs

Este permisă numai dacă:

- poziția există;
- comanda păstrează cel puțin un produs;
- comanda nu este în retur/rambursare/anulare;
- factura nu este `processing` sau `sent`;
- nu există factură corectată care blochează formularul;
- operatorul confirmă impactul.

La salvare:

- poziția este eliminată;
- promoția se eliberează;
- prețurile finale ale pozițiilor rămase se păstrează;
- totalurile se recalculează;
- factura netransmisă păstrează numărul, dar își regenerează payloadul și fișierele;
- ieșirea/FIFO a poziției eliminate se inversează exact;
- avizul se regenerează;
- istoricul păstrează produsul eliminat și operatorul.

După SPV, produsul nu se șterge din comandă prin formularul simplu. Se emite document negativ de corecție/retur.

### 64.9. Înlocuirea produsului

#### 64.9.1. Înainte de blocarea fiscală

- produsul nou trebuie să fie diferit și activ;
- nu poate dubla alt produs din comandă;
- se verifică stocul pentru cantitatea existentă;
- se introduce prețul nou;
- se păstrează cantitatea poziției originale;
- factura netransmisă este revizuită cu același număr;
- stocul/FIFO vechi se inversează, iar produsul nou se alocă;
- avizul se regenerează.

#### 64.9.2. Factură trimisă sau comandă livrată

- factura originală nu se modifică;
- se emite document negativ pentru produsul vechi;
- se emite document pozitiv corectat pentru produsul nou;
- se tratează separat returul fizic și noua ieșire;
- o comandă livrată trebuie să aibă factura inițială;
- se confirmă explicit transportul înlocuirii;
- se alege dacă transportul returului este suportat de client sau firmă;
- dacă îl suportă clientul, valoarea este obligatorie;
- existența unui lanț anterior de corecție poate bloca o nouă înlocuire automată.

### 64.10. Costul transportului și metoda de livrare

Costul transportului nu este un câmp liber editabil în orice moment.

În comportamentul de referință:

- simpla editare a produselor înainte de livrare nu permite schimbarea arbitrară a costului transportului;
- schimbarea costului este acceptată împreună cu înlocuirea unui produs dintr-o comandă livrată și facturată;
- confirmarea separată este obligatorie;
- valoarea trebuie să fie numerică și nenegativă;
- transportul de retur se păstrează distinct de transportul noului produs;
- costurile se reflectă în documentele de corecție, nu prin rescrierea facturii istorice.

### 64.11. Reduceri și promoții după editare

O comandă modificată manual nu se recalculează ca și cum promoția ar fi aplicată din nou în acel moment.

Regulă:

1. se determină valoarea finală deja acceptată pentru fiecare poziție;
2. reducerea globală se repartizează determinist;
3. prețurile finale rezultate devin prețurile pozițiilor păstrate;
4. utilizarea promoției se eliberează;
5. legătura cu promoția și reducerea separată se elimină;
6. produsele noi intră la prețul explicit acceptat;
7. totalurile se recalculează fără dublarea reducerii.

### 64.12. Editarea statusului comenzii

- statusurile normale avansează, nu se mută înapoi;
- operatorul poate sări la un status ulterior;
- salvarea aceluiași status nu retrimite e-mailul;
- retrimiterea statusului curent se face prin buton separat;
- între `Retur solicitat` și `Retur refuzat` se poate reveni înainte de confirmare;
- după `Retur confirmat`, singura continuare normală este `Rambursată`;
- `Anulată` trece prin serviciul fiscal de anulare, nu printr-un simplu UPDATE;
- `Rambursată` cere factura de retur dacă a existat factură pozitivă.

### 64.13. Editarea statusului plății

- `pending`, `paid`, `failed` și `refunded` sunt stări distincte;
- metoda „Card online” nu înseamnă automat „Plătită”;
- factura pe card se emite numai după `paid`;
- trecerea comenzii în `refunded` setează plata `refunded`;
- ștergerea controlată a facturii de retur restaurează exact starea salvată anterior;
- schimbarea plății actualizează numai factura netransmisă;
- orice schimbare este auditată.

### 64.14. Editarea notelor

- nota internă poate fi actualizată fără a fi trimisă clientului;
- observația clientului este snapshot și nu se rescrie de operator ca și cum ar proveni de la client;
- notele nu modifică automat factura;
- o notă fiscală care trebuie să apară pe factură se schimbă numai cât documentul este editabil și se regenerează controlat.

### 64.15. Ștergerea comenzii

O comandă plasată nu se șterge fizic din registru. Nu există buton normal de hard-delete.

Motivul:

- trebuie păstrat istoricul comercial;
- pot exista plăți, e-mailuri, promoții, avize, facturi, stoc și SPV;
- ID-ul și numărul comenzii trebuie să rămână auditabile.

Operațiunea corectă este anularea. Eventuala anonimizare GDPR este un flux separat care păstrează valorile fiscale obligatorii și nu șterge documentele înainte de termenul legal.

### 64.16. Editarea facturii pozitive

Factura nu are un editor liber după emitere. Există două mecanisme:

1. **revizuire controlată a facturii netransmise**: se reconstruiește din comandă, păstrând seria și numărul;
2. **corecție fiscală**: după blocare/SPV se emit documente noi.

Revizuirea controlată este permisă numai dacă:

- factura nu a fost încărcată în SPV;
- nu este în procesare;
- nu există lanț de corecție incompatibil;
- comanda nu este într-o stare terminală blocată;
- modificarea sursă este validă.

Revizuirea regenerează payloadul, PDF-ul, XLSX-ul și XML-ul și reface stocul/FIFO dacă pozițiile s-au schimbat. Numărul rămâne același. Copiile vechi nu mai sunt oferite ca document curent, dar auditul reține modificarea.

### 64.17. Ștergerea facturii pozitive

Butonul „Șterge” apare numai dacă backendul întoarce `can_delete = true`.

Condiții cumulative:

- factura este ultima eligibilă;
- nu a intrat niciodată în SPV;
- nu are index de upload;
- nu este referită de un retur/corecție blocat;
- mișcările ei de stoc pot fi inversate;
- operatorul are dreptul necesar.

Efecte:

1. se șterge factura;
2. seria/numărul sunt eliberate conform politicii;
3. PDF/XLSX/XML/linkurile sunt invalidate;
4. stocul și FIFO se inversează exact;
5. legătura comenzii cu factura se elimină;
6. comanda rămâne în registru;
7. următoarea emitere poate reutiliza numărul numai în această excepție;
8. se scrie auditul.

### 64.18. Ștergerea facturii de retur

Butonul apare numai pentru ultima factură de retur eligibilă și netrimisă în SPV.

Este blocat dacă:

- plata a fost deja rambursată;
- documentul a intrat în SPV;
- stocul reintrat a fost consumat;
- NIR-ul de retur a alimentat un retur către furnizor;
- comanda nu mai este în situația compatibilă;
- documentul nu este ultimul eligibil.

Efectul tranzacțional complet:

- șterge factura de retur;
- elimină numărul ei;
- șterge NIR-ul de retur, dacă există;
- inversează `RETURN_IN` și refacerea FIFO;
- curăță returul/anularea activă;
- readuce comanda la `Livrată`;
- restaurează statusul anterior exact al plății;
- înregistrează istoricul;
- după commit trimite e-mailul de livrare.

### 64.19. Ștergerea avizului

Avizul se poate șterge numai dacă `can_delete` este adevărat și nu este blocat de un flux ulterior. Ștergerea avizului:

- nu șterge factura;
- nu inversează stocul;
- nu anulează comanda;
- elimină documentul logistic și linkurile lui;
- se auditează.

### 64.20. Anularea comenzii — limita exactă

#### Client

- permisă în `Nouă`, `Confirmată`, `În pregătire`;
- ascunsă și refuzată de API de la `Predată curierului` înainte;
- motiv și confirmare explicită;
- după succes se trimite e-mail automat.

#### Operator

- permisă și după expediere/livrare, cu motiv obligatoriu;
- nu reprocesează `Anulată` sau `Rambursată`;
- fără factură: doar anulare comercială;
- ultima factură netrimisă: ștergere controlată;
- factură transmisă sau care nu este ultima: retur fiscal integral;
- plata card intră în rambursare;
- rambursul fără încasare nu creează restituire.

### 64.21. Returul — limita exactă

- public numai din `Livrată`;
- PF: 14 zile legale și extensia comercială configurată până la 30 de zile totale;
- PJ: 14 zile comerciale voluntare în regula de referință;
- cantitatea cerută nu depășește cantitatea comandată și nereturnată;
- returul poate fi integral sau parțial;
- operatorul decide fiecare cantitate;
- toate liniile trebuie decise;
- cel puțin o cantitate acceptată pentru confirmare;
- refuz integral nu emite factură și nu modifică stocul;
- confirmarea emite factura de retur dacă există factura pozitivă;
- NIR-ul de recepție este explicit și separat;
- rambursarea nu adaugă stoc.

### 64.22. Efectul fiecărei acțiuni asupra stocului

| Acțiune | Stoc online | Stoc contabil | FIFO |
|---|---|---|---|
| Plasare comandă | conform politicii de rezervare | fără ieșire fiscală | fără consum definitiv |
| Emitere factură pozitivă | scade pentru produs urmărit | ieșire | consumă loturile |
| Revizuire factură netransmisă | recalculează diferența | inversează și repostează | reconstruiește alocările |
| Ștergere factură eligibilă | restaurează | inversează ieșirea | restaurează loturile |
| Emitere factură retur | fără intrare fizică | fără intrare | fără inversare |
| Generare NIR retur | crește cantitatea acceptată | intrare pentru produse contabile | reface loturile originale |
| Status Rambursată | fără efect | fără efect | fără efect |
| Ajustare manuală online | modifică operațional | nu este document contabil | fără efect contabil |

### 64.23. Răspunsul `allowed_actions` recomandat

Pentru a evita logica duplicată, fișa comenzii/facturii trebuie să primească de la backend:

```json
{
  "allowed_actions": {
    "edit_contact": {"allowed": true, "reason": null},
    "edit_delivery": {"allowed": true, "reason": null},
    "add_item": {"allowed": false, "reason": "Factura a intrat în SPV."},
    "remove_item": {"allowed": false, "reason": "Factura a intrat în SPV."},
    "replace_item": {"allowed": true, "mode": "fiscal_correction"},
    "issue_invoice": {"allowed": false, "reason": "Există deja factura pozitivă."},
    "cancel_order": {"allowed": true, "mode": "full_return_invoice"},
    "delete_invoice": {"allowed": false, "reason": "Documentul are index SPV."}
  }
}
```

Interfața afișează motivul exact lângă controlul dezactivat. Nu încearcă să deducă local regulile dintr-un singur status.

---

## 65. Tabelul „Facturi emise” — toate elementele, badge-urile și efectele

### 65.1. Structura paginii

Pagina conține:

1. cardul `ÎNCASAT` — suma și numărul facturilor cu status `paid`;
2. cardul `DE ÎNCASAT` — suma și numărul facturilor cu status `unpaid`;
3. cardul `DOCUMENTE EMISE` — numărul total al documentelor din registru;
4. căutare;
5. filtre `Toate`, `Plătite`, `Neplătite`;
6. export;
7. paginare sus;
8. lista facturilor;
9. paginare jos.

Facturile de retur au statusul vizual `return`; ele apar în `Toate`, nu sunt însumate automat ca facturi plătite/neplătite decât dacă modelul financiar definește separat acest lucru.

### 65.2. Căutarea

Se caută după:

- serie și număr;
- număr comandă;
- client;
- CUI;
- e-mail;
- total;
- monedă;
- referință ANAF;
- ID de transmitere;
- index de upload;
- ID de download.

Căutarea normalizează diacriticele și literele mari/mici.

### 65.3. Elementele fiecărui card/rând de factură

Fiecare element afișează:

- pictograma documentului;
- seria și numărul, de exemplu `GT 025`;
- badge-ul comercial/fiscal al documentului;
- badge-ul SPV;
- referința ANAF, dacă există;
- programarea SPV, dacă există;
- avertismentul NIR retur lipsă, dacă există;
- numele cumpărătorului;
- comanda sursă;
- data emiterii;
- tema documentului în aplicația-sursă;
- starea e-mailului;
- totalul;
- butonul `Deschide fișa`.

În noul proiect cu o singură temă, textul `tema ...` se omite; el nu are semnificație fiscală.

### 65.4. Badge-ul principal al documentului

| Valoare | Text afișat | Când apare | Efect |
|---|---|---|---|
| `paid` | `PLĂTITĂ` | Încasarea facturii este confirmată. | Intră în indicatorul `ÎNCASAT`. |
| `unpaid` | `NEPLĂTITĂ` | Factura este emisă, dar încasarea nu este confirmată. | Intră în `DE ÎNCASAT`. |
| `return` | `RETUR` | Document negativ de retur/corecție. | Afișează total retur și referința la factura inițială în fișă. |

#### Despre „EMISĂ”

În lista actuală nu există un badge separat `EMISĂ`, deoarece însăși pagina este registrul „Facturi emise”. În fișa comenzii există eticheta `FACTURĂ EMISĂ`, urmată de număr, plată, SPV, total și confirmarea ieșirii din stoc.

#### Despre „ANULATĂ”

O factură fiscală nu este marcată pur și simplu `ANULATĂ` după ce a intrat în circuitul fiscal:

- dacă este ultima și complet eligibilă pentru ștergere, dispare din registrul activ după rollback și operația rămâne în audit;
- dacă nu poate fi ștearsă, factura pozitivă rămâne, iar anularea comenzii este documentată prin factura de retur;
- badge-ul `ANULATĂ` aparține comenzii, nu înlocuiește factura transmisă;
- dacă proiectul dorește un registru de documente șterse/anulate, acesta trebuie să fie un jurnal separat, read-only, nu o rescriere a facturii.

### 65.5. Badge-urile SPV

| `spv_status` | Badge | Explicație vizibilă | Buton în fișă |
|---|---|---|---|
| `not_sent` | `SPV NETRIMISĂ` | Transmitere manuală disponibilă. | `Trimite în SPV` activ. |
| `processing` | `SPV ÎN PROCESARE` | ANAF verifică documentul. | `În procesare ANAF`, dezactivat. |
| `sent` | `SPV TRIMISĂ` | Document acceptat/transmis. | `Trimisă în SPV`, verde/dezactivat. |
| `error` | `SPV EROARE` | Eroare temporară sau operațională. | `Retrimite în SPV`, dacă este eligibil. |
| `rejected` | `SPV RESPINSĂ` | ANAF a respins documentul. | `Retrimite în SPV` după remediere. |

Badge-ul `EROARE` sau `RESPINSĂ` nu înseamnă automat că factura poate fi ștearsă. Dacă documentul are index de upload, rămâne blocat fiscal.

### 65.6. Badge-ul „PROGRAMAT LA ...”

Apare numai dacă:

- factura este încă `not_sent`;
- jobul SPV are status `scheduled`;
- modul este `delayed`;
- există data `scheduled_at`.

Textul este `PROGRAMAT LA ZZ.LL.AAAA`. După transmitere, programarea nu mai este afișată ca stare curentă. Editarea unei facturi netransmise trebuie să recalculeze jobul și să invalideze XML-ul programat anterior.

### 65.7. Badge-ul „REF. ANAF ...”

Apare numai când factura are `spv_status = sent` și există cel puțin una dintre referințele:

- `spv_reference`;
- `upload_index`;
- `spv_submission_id`;
- `download_id`.

Ordinea de preferință este referința SPV explicită, apoi indexul uploadului, ID-ul transmiterii și ID-ul downloadului. Referința este căutabilă în registru și trebuie copiată/exportată fără alterare.

### 65.8. Badge-ul „NIR RETUR LIPSĂ”

Apare când:

- documentul este factură de retur;
- există produse contabile acceptate;
- recepția fizică nu a fost încă înregistrată.

Badge-ul indică o acțiune operațională restantă. Deschiderea fișei oferă `Generează NIR de recepție retur`. După generare, badge-ul și alerta zilnică dispar.

### 65.9. Linia e-mailului

| Situație | Text |
|---|---|
| `email_sent_at` există | `Trimisă la adresa@client.ro` |
| Nu a fost trimisă, dar există e-mail | se afișează adresa, fără stilul „trimisă” |
| Nu există adresă | `Fără e-mail` |

Această linie arată trimiterea documentului, nu plata și nu transmiterea SPV. Retrimiterea e-mailului nu modifică factura.

### 65.10. Totalul

- factura pozitivă afișează `TOTAL FACTURĂ`;
- factura de retur afișează `TOTAL RETUR`;
- moneda este cea snapshot-uită în document;
- valoarea nu se recalculează din catalog la afișare;
- un retur folosește convenția valorică a documentului, iar PDF/XML păstrează semnele fiscale exacte.

### 65.11. Deschiderea fișei

Click pe întregul card sau pe `Deschide fișa` deschide aceeași factură. Nu se bazează doar pe o zonă mică a butonului.

Fișa afișează:

- tipul documentului;
- numărul;
- comanda și data;
- factura pozitivă referită, pentru retur;
- badge-ul principal;
- badge-ul SPV;
- referința ANAF;
- programarea;
- acțiunea SPV;
- eroarea SPV completă;
- emitent și cumpărător;
- poziții;
- plată/scadență/emitent uman;
- reduceri;
- totaluri;
- acțiuni de export și comunicare.

### 65.12. Blocul de eroare SPV

Pentru `error` sau `rejected`, fișa afișează:

- `DOCUMENT RESPINS DE ANAF` sau `EROARE DE TRIMITERE SPV`;
- mesajul real salvat;
- mediul TEST/PRODUCȚIE;
- numărul încercărilor;
- indexul de upload, dacă există.

Tokenurile, certificatul și secretele nu se afișează.

### 65.13. Butonul „Trimite în SPV”

- `not_sent`: activ;
- `processing`: dezactivat;
- `sent`: dezactivat și confirmat vizual;
- `error`/`rejected`: text `Retrimite în SPV`, dacă backendul permite;
- cere confirmare înainte de transmitere;
- reîncarcă fișa după rezultat;
- la eșec afișează eroarea salvată;
- este idempotent și nu face două uploaduri.

### 65.14. Butonul „Obține link”

- creează sau returnează linkul public securizat;
- copiază linkul;
- nu modifică starea plății;
- nu marchează factura trimisă pe e-mail;
- nu trimite în SPV;
- accesul poate fi revocat și auditat.

### 65.15. Butonul „E-mail”

- generează/folosește PDF-ul curent;
- trimite la adresa comenzii;
- la succes actualizează `email_sent_at`;
- la eșec păstrează factura și salvează eroarea;
- poate fi reapelat fără să emită o factură nouă.

### 65.16. Butoanele PDF, XLSX și e-Factura XML

- sunt exporturi read-only;
- folosesc snapshotul facturii;
- nu recalculează din catalog;
- nu schimbă plata;
- nu schimbă statusul SPV;
- nu modifică stocul;
- XML-ul descărcat este versiunea fiscală corespunzătoare documentului.

### 65.17. Butonul „Șterge”

- este ascuns implicit;
- la deschiderea fișei, aplicația cere din nou factura și `can_delete` de la server;
- apare numai dacă backendul confirmă eligibilitatea actuală;
- condițiile sunt reverificate în tranzacția de ștergere;
- pentru factura pozitivă, confirmarea explică refacerea stocului și reutilizarea numărului;
- pentru factura de retur, confirmarea explică ștergerea NIR-ului, rectificarea stocului, revenirea la `Livrată` și e-mailul automat;
- după succes, lista facturilor și comenzilor se reîncarcă.

### 65.18. Ce apare în fișa comenzii despre factură

#### Factură pozitivă existentă

- eticheta `FACTURĂ EMISĂ`;
- numărul;
- `Plătită` sau `Neplătită`;
- SPV trimisă/netrimisă;
- totalul;
- `ieșire de stoc înregistrată`;
- buton `Vezi factura`.

#### Factură neemisă, eligibilă

- `FACTURĂ FISCALĂ`;
- `Factura nu este emisă`;
- explicația că emiterea fixează prețurile, scade stocul și stabilește proveniența loturilor;
- buton `Emite factura`.

#### Card neplătit

- `Factura nu este emisă`;
- explicația că emiterea devine disponibilă după confirmarea plății;
- fără buton activ de emitere.

#### Factură de retur

- `FACTURĂ DE RETUR` sau `FACTURĂ NEGATIVĂ DE CORECȚIE`;
- numărul;
- referința fiscală și comanda;
- SPV trimisă/în așteptare;
- buton `Vezi returul`.

### 65.19. Efectul anulării asupra listei de facturi

| Caz | Ce vede operatorul după anulare |
|---|---|
| Comandă fără factură | Nu apare document nou; comanda este `Anulată`. |
| Ultima factură netrimisă, ștearsă sigur | Factura dispare din registrul activ; auditul păstrează operația; numărul poate fi reutilizat. |
| Factură netrimisă care nu este ultima | Factura pozitivă rămâne și apare factura de retur. |
| Factură intrată în SPV | Factura pozitivă rămâne, iar returul integral apare ca document separat. |

Nu se schimbă badge-ul facturii pozitive în `ANULATĂ` pentru a ascunde istoricul fiscal.

### 65.20. Efectul returului asupra listei

1. `Retur solicitat`: nu apare încă factura de retur.
2. `Retur refuzat integral`: nu apare factura de retur.
3. `Retur confirmat` cu factură pozitivă: apare factura de retur.
4. Retur parțial: factura de retur conține numai cantitățile acceptate.
5. Până la recepția fizică: badge `NIR RETUR LIPSĂ`.
6. După NIR: badge-ul dispare; documentele rămân.
7. `Rambursată`: plata se închide; nu se creează o nouă mișcare de stoc.

### 65.21. Stări care nu trebuie confundate

- `PLĂTITĂ` nu înseamnă `SPV TRIMISĂ`;
- `SPV TRIMISĂ` nu înseamnă e-mail trimis;
- e-mail trimis nu înseamnă plată;
- `RETUR` nu înseamnă recepție fizică;
- `NIR RETUR LIPSĂ` nu înseamnă că factura de retur lipsește;
- `PROGRAMAT LA` nu înseamnă că documentul a fost deja încărcat;
- `RESPINSĂ` SPV nu înseamnă că factura a fost anulată;
- comanda `Anulată` nu autorizează ștergerea facturii transmise.

---

## 66. Criterii suplimentare de acceptanță pentru editări și tabelul facturilor

1. Pentru fiecare buton, backendul întoarce `allowed`, `mode` și `reason`.
2. Datele clientului nu pot rescrie o factură intrată în SPV.
3. Datele de livrare și cele fiscale sunt separate după facturare.
4. Cantitatea existentă nu pare editabilă dacă produsul actual nu suportă această operațiune.
5. Prețul aceluiași produs nu poate fi schimbat prin formularul de înlocuire.
6. Produsul nou permite cantitate 1–99 și preț pozitiv.
7. Eliminarea ultimei poziții este refuzată.
8. Adăugarea/ștergerea regenerează factura netransmisă, avizul și stocul.
9. După SPV, schimbarea produsului folosește corecții, nu UPDATE pe factura originală.
10. Comanda nu are hard-delete.
11. Anularea publică dispare după `În pregătire` și API-ul o refuză.
12. Ștergerea unei facturi este decisă server-side la momentul clickului.
13. O factură cu index SPV nu poate fi ștearsă.
14. Ștergerea returului restaurează plata exact, nu forțat la `Plătită`.
15. Registrul arată corect `PLĂTITĂ`, `NEPLĂTITĂ` și `RETUR`.
16. Registrul arată toate stările SPV și mesajul complet în fișă.
17. `REF. ANAF` este afișată și căutabilă numai când există.
18. `PROGRAMAT LA` apare numai pentru jobul întârziat încă netrimis.
19. `NIR RETUR LIPSĂ` apare și dispare după recepție.
20. E-mailul, PDF-ul, XLSX-ul, XML-ul și linkul nu modifică stocul sau plata.
21. Anularea unei comenzi facturate păstrează documentul pozitiv și creează retur când ștergerea nu este permisă.
22. Nu se inventează un badge `ANULATĂ` pentru a înlocui corecția fiscală.
23. Paginarea există sus și jos și păstrează filtrul/căutarea.
24. Toate schimbările au audit înainte/după.
25. Testele concurente demonstrează că două clickuri nu dublează factura, returul, NIR-ul sau stocul.

