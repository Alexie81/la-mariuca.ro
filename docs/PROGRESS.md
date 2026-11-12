# Progres

Actualizat: 2026-11-12

### 2026-11-03 — Pagini pentru rezultatul comenzii și al plății
- Pagina privată a comenzii are prezentări distincte pentru ramburs, plată cu cardul anulată, eșuată, în așteptare și confirmată. Stările existente de verificare, retur/rambursare și ramburs deja încasat păstrează mesaje corecte. Un parametru din URL nu poate marca plata ca reușită sau modifica o comandă; revenirea din Stripe cu anulare este doar un indiciu de afișare pentru plata încă neconfirmată.
- Designul include antet cu stare, număr, dată și sumă, produse cu fotografii și variante, reduceri, costul livrării, total, adresă și contact. Cardul de ajutor folosește inima standard a magazinului și două acțiuni clare pentru mesaj și telefon. Linkurile funcționează fără JavaScript; verificarea stării nu inițiază plăți sau comenzi noi.
- Prețurile și denumirile vin din snapshoturile comenzii; imaginile sunt ilustrațiile actuale din catalog. Răspunsurile cu token sunt private, fără cache și fără transmiterea refererului. Datele se afișează în Europe/Bucharest.
- Browserul intern a verificat paginile cu date sintetice la 1440, 768, 390 și 320 px: fără overflow orizontal, butoane aliniate și ținte tactile de minimum 49 px, imagini încărcate, acțiunea de reverificare și lipsa erorilor în consolă. Nu s-au modificat comenzi reale pentru previzualizare.
- Au trecut lint PHP, PHPStan, cele 72 template-uri Twig, `git diff --check` și suita integrală PHPUnit: 141 teste / 1772 aserțiuni. Testele dedicate rezultatului acoperă prioritatea stărilor, token invalid, base path, datele comenzii și lipsa modificărilor de stoc/plată la GET. Stripe live, SMTP, concurența MySQL și Apache nu au fost verificate; nu s-a făcut deploy.

### 2026-11-03 — Adresă fără cod poștal obligatoriu
- Județul și localitatea apar înaintea căutării poștale și se pot alege direct. Codul poștal este opțional în checkout, salvarea rapidă și editarea adreselor din cont; valorile completate sunt validate ca șase cifre.
- Selectorul comun de județe/localități nu mai închide lista între pierderea focusului și clickul pe opțiune. Verificat pe telefon la 390 px: apăsarea pe Cluj și Cluj-Napoca păstrează ambele selecții, cu codul poștal gol și valid, fără overflow.
- Au trecut testele de adresă/cont/cod poștal (12 teste / 118 aserțiuni), verificarea sintaxei celor trei module JS, compilarea celor 72 template-uri și `git diff --check`. Nu s-au plasat comenzi sau salvat adrese reale în browser.

### 2026-11-03 — Anunțul promoțional fără link
- Bara ofertelor afișează acum doar mesajul, iconița și eventualul cod, fără legătură către magazin. Au fost eliminate destinația implicită și stilurile specifice linkului; animația și limitele navbarului rămân aceleași.
- Verificat în browser la 1280 și 390 px: niciun link în bară, apăsarea păstrează pagina checkout, animația și iconița de curier sunt prezente, fără overflow orizontal. Au trecut 72 template-uri compilate, lint pentru serviciul modificat, `PromotionAdminTest` (7 teste / 243 aserțiuni) și `git diff --check`. Fără deploy.

### 2026-11-03 — Switch-uri pentru confirmarea comenzii
- Acceptarea termenilor și opțiunea de newsletter au acum carduri cu switch-uri, titluri lizibile, etichetă „Opțional”, stare selectată roz și bifă pe cursor. Toată suprafața cardului poate activa opțiunea; linkul termenilor rămâne separat accesibil.
- Controalele păstrează inputurile native, asocierea cu formularul, valorile reafișate după erori și validarea obligatorie doar pentru termeni. Sunt utilizabile fără JavaScript și din tastatură, cu focus vizibil și animații oprite când este cerută mișcare redusă.
- Verificat în browser la 1280, 768 și 320 px: stări pornit/oprit, tastă Space, lipsa overflow-ului, termeni obligatorii și newsletter opțional. Nu s-au acceptat termenii sau trimis comenzi/abonări; comutarea vizuală a newsletterului a fost anulată. Au trecut compilarea a 72 template-uri, `CheckoutAddressTest` (4 teste / 62 aserțiuni), testele existente pentru păstrarea datelor/recalculare fără JS (2 teste / 23 aserțiuni) și `git diff --check`. Fără deploy în producție.

### 2026-11-03 — Reactivare directă din registrul ofertelor
- Ofertele inactive au buton verde „Reactivează”, cu text vizibil inclusiv pe desktop și confirmare separată. Pe telefon ocupă un rând complet, iar editarea și ștergerea rămân dedesubt, cu pictogramele și textele aliniate. Mesajul dezactivării indică acum această acțiune directă.
- Reactivarea schimbă doar starea și data actualizării, păstrând valabilitatea, regulile, utilizările și configurația barei. Ofertele șterse nu pot fi reactivate; perioadele viitoare/expirate nu devin aplicabile doar prin reactivare. Rutele cer autorizare admin și CSRF și funcționează și fără JavaScript.
- Verificate în browserul intern tabelul și confirmarea la 1680×950, plus butoanele și dialogul la 320×740, fără overflow sau erori în consolă. Oferta locală a rămas inactivă; verificările cu salvare au rulat doar în baza izolată de teste. Au trecut cele 7 teste de administrare promoții / 243 aserțiuni, PHPStan, lint pentru fișierele PHP modificate, compilarea a 72 template-uri, sintaxa JS și `git diff --check`. Nu s-a făcut deploy și nu s-a verificat concurența MySQL.

### 2026-11-03 — Editare, ștergere și confirmări pentru oferte
- Registrul de cupoane și promoții oferă editare cu date precompletate, dezactivare și ștergere. Confirmările sunt dialoguri native în afara tabelului, astfel încât zona de scroll nu le mai taie. Au butoane aliniate, închidere cu Escape, revenirea focusului la acțiunea inițială și pagini de confirmare funcționale fără JavaScript.
- Editarea reutilizează validările de creare, păstrează identitatea și utilizările ofertei, convertește corect bani/Lei și datele UTC/Europe-Bucharest și permite reactivarea din selectorul stării. Ștergerea marchează oferta eliminată și o oprește, păstrând înregistrările folosite de istoricul comenzilor. Rutele sunt protejate prin autorizare admin și CSRF; modificările rulează în tranzacții.
- Verificat în browserul intern la 1680×950, 1280×720, 390×844 și 320×740: confirmările de dezactivare/ștergere, formularul de editare, lipsa overflow-ului orizontal și alinierea acțiunilor. Oferta existentă nu a fost modificată sau ștearsă în browser. Consola nu a raportat erori.
- Au trecut lint PHP, compilarea a 72 template-uri Twig, PHPStan, sintaxa JavaScript și PHPUnit (129 teste / 1613 aserțiuni). Cele 6 teste pentru administrarea ofertelor acoperă inclusiv editarea, unicitatea codului, păstrarea utilizărilor, ștergerea, rutele inexistente, autorizarea și CSRF. Suita PHPUnit a rulat direct prin wrapperul PHP local; lansarea prin scriptul Composer nu transmite extensia locală mbstring către procesul copil. Nu s-au verificat concurența MySQL, Apache, SMTP sau Stripe și nu s-a făcut deploy în producție.

## VERIFIED

- Fundația noului panou de administrare este disponibilă direct la `/admin`: vizitatorii neautentificați
  primesc o pagină de login dedicată, iar conturile active cu rol `administrator` sau `operator` ajung într-un
  Dashboard responsive cu statistici, fluxul comenzilor, starea catalogului, comenzile recente și stocurile
  care necesită atenție. Navigația expune momentan exclusiv Dashboardul, conform etapei curente, deși rutele
  administrative existente rămân protejate server-side. Interfața are drawer tactil pe telefon, numărători și
  bare animate cu fallback fără JavaScript și suport `prefers-reduced-motion`.
- Loginul admin are CSRF, limitare la 6 încercări în 15 minute, mesaj generic la eșec și verificare strictă de
  rol. Opțiunea „Ține-mă minte” folosește token selector/validator aleator, păstrează numai hash-uri în baza de
  date, este legată de user-agent, rotită la fiecare restaurare, revocată la logout și expiră în 30 de zile prin
  cookie `HttpOnly; SameSite=Strict` (`Secure` în HTTPS). Migrarea `015_admin_remember_tokens` există pentru
  SQLite și MySQL, iar fluxul rămâne compatibil cu verificarea TOTP.
- Verificări pentru această etapă: 40 șabloane Twig compilate, lint PHP fără erori, JavaScript valid,
  PHPStan fără erori și PHPUnit 73 teste / 527 aserțiuni, inclusiv login admin, respingerea clienților,
  restaurarea și rotirea sesiunii persistente și revocarea tokenului la deconectare.

- Repository-ul inițial a fost inspectat; logo-urile sursă au rămas neatinse.
- PHP CLI 8.4.25 și extensiile locale necesare au fost identificate; wrapperul activează DLL-urile
  fără modificarea configurației globale.
- Composer a instalat lockfile-ul real fără `--ignore-platform-reqs`; auditul nu a raportat
  advisories la ultima rulare.
- Migrările SQLite `001` și `002` au fost aplicate pe baza locală; seed-ul demo încarcă 12 produse.
- Lint PHP: fără erori. Cele 31 de șabloane Twig compilează. PHPStan nivel 5: fără erori.
- PHPUnit: 20 de teste, 81 de aserțiuni, toate trecute. Sunt acoperite slug/money, cicluri categorie,
  pagini/sitemap, redirecturi, căutare cu sugestii de categorii și produse, preț server-side, idempotency,
  rezervări/anulare, cupoane, re-encodare media și TOTP/recovery.
- Smoke HTTP: `/`, categorie, produs, coș, checkout, sitemap și robots au răspuns 200.
- Smoke browser desktop/mobil: fără scroll orizontal la 390 px, meniu mobil funcțional, produs
  adăugat în coș, checkout ramburs finalizat, admin autentificat și dashboard afișat.
- Navbar verificat la 1532 px și 390 px: logo-ul vechi are acum derivat PNG transparent și o
  dimensiune desktop compactă de maximum 185 px; Favorite și Coș folosesc derivate transparente din referințele
  aprobate, iar Cont păstrează conturul SVG subțire și rotunjit; headerul compact, badge-urile,
  acțiunile desktop sunt aliniate orizontal iconiță–text, mini-coșul animat, meniul mobil în ordinea aprobată și
  sugestiile de căutare sunt funcționale. La hover/focus, inimile Favorite se umplu progresiv
  de jos în sus, în aceeași nuanță cu conturul și cu unda limitată strict la interior; acțiunea
  Autentificare rămâne fixă pe verticală la hover. Animațiile respectă
  `prefers-reduced-motion`. Badge-urile numerice folosesc acum un gradient coral tip perlă, contur
  fin, umbră discretă, reflex animat și o pulsație mai lentă, fără să acopere pictogramele. Starea de
  coș gol folosește acum un mesaj cald, aceeași pictogramă
  de coș ca navbarul, animată la intrare și însoțită de inimioare care cad în coș, plus un CTA
  către colecții; toate sunt adaptate pentru desktop și mobil. Paginile goale Coș și Favorite
  folosesc aceeași familie de panouri animate. Pe telefon, iconițele Coș și Favorite navighează
  direct către paginile lor, indiferent dacă listele sunt goale sau conțin produse.
  Pe desktop, dacă lista Favorite este goală, acțiunea din navbar deschide un mini-panou animat
  echivalent cu mini-coșul; când conține produse, continuă să deschidă pagina Favorite.
- Tipografia storefrontului folosește acum Fraunces pentru titluri și accente editoriale și Nunito Sans
  pentru text, navigație și formulare. Ambele familii variabile sunt servite local, includ licențele
  OFL și folosesc fallback-uri de sistem plus ajustări discrete de kerning/letter-spacing. Logo-ul
  grafic rămâne neschimbat.
- Faviconul folosește acum monograma rotundă `LM` din `logo-uri/`, prin derivate PNG transparente
  optimizate la 32 px, 192 px și 180 px pentru browser și Apple touch icon; sursa originală rămâne
  neschimbată.
- Căutarea din navbar urmează referința desktop/mobil: lupă la început, buton coral rotund,
  scurtături rapide și panou de sugestii separat pe categorii și produse, cu prețuri și link către
  toate rezultatele. Controlul nativ al câmpului de căutare a fost înlocuit cu un buton de ștergere
  compact, accesibil și aliniat vizual cu paleta boutique. Pe mobil, scurtăturile de categorii sunt
  integrate în panoul de rezultate, ca să nu mai plutească peste conținutul paginii; în overlay-ul
  meniului mobil sunt ascunse pentru a păstra căutarea aerisită. Funcționalitatea rămâne un
  enhancement peste formularul GET server-rendered. Toate câmpurile de căutare din storefront au
  acum un placeholder cald, în gri discret: „Caută hăinuțe, rochițe, trusouri...” pe desktop/tabletă
  și „Ce cauți pentru cel mic?” pe telefon; căutarea după SKU și categorii continuă să funcționeze.
  Scurtăturile de căutare folosesc carduri cu gradient și umbră fină, plus un hover cu reflex
  discret; aceleași elemente sunt adaptate în grila panoului mobil. Lupa decorativă de la începutul
  câmpului a fost eliminată din bara normală pe toate dimensiunile;
  butonul coral din dreapta rămâne singurul indicator vizual al acțiunii și textul primește mai mult spațiu.
  În overlay-ul meniului mobil, unde butonul coral este ascuns, lupa reapare în stânga câmpului ca
  reper vizual, fără să afecteze bara compactă normală.
  Scurtăturile de sub căutare nu mai sunt hardcodate: ele folosesc toate categoriile principale
  publicate din același arbore al bazei de date ca meniul. Miniatura media este afișată numai când
  categoria are imagine asociată; altfel rămâne doar denumirea, fără pictogramă generică.
  Când lățimea disponibilă nu permite afișarea tuturor categoriilor, rândul poate fi glisat și primește
  controale adaptive așezate lângă primul și ultimul card, pentru mouse și tastatură; rotița mouse-ului
  navighează orizontal prin listă fără ca săgețile să acopere miniaturile. Activarea unei săgeți păstrează
  rândul deschis inclusiv atunci când controlul folosit dispare la capătul listei. Pe lățimile de tabletă,
  întregul rând apare într-un panou alb translucid, cu contur și umbră discrete, astfel încât fotografia
  hero-ului să nu se mai vadă printre cardurile de categorie.
- Hero-ul paginii principale folosește acum o fotografie editorială originală generată pentru proiect,
  cu bebeluș, pisicuță cu fundă pe cap și decor crem–roz, text HTML în două culori, două CTA-uri și beneficii comerciale.
  Pe ecranele ultrawide, masca albă se estompează mai devreme pentru a păstra pisicuța vizibilă fără a reduce
  lizibilitatea textului din stânga.
  CTA-urile păstrează dimensiunea compactă, dar folosesc acum gradient coral–roz, contur auriu discret,
  reflex interior pe varianta secundară și săgeți circulare animate. CTA-ul plin își deplasează fluid
  gradientul la hover, fără reflex alb sau salt de culoare; ambele au stări focus și reduced-motion dedicate.
  Pe mobil fotografia rămâne deasupra, iar conținutul urcă într-un panou alb cu margine SVG organică,
  iar pe telefoanele înguste conținutul textual începe mai sus în interiorul panoului, fără deplasarea
  formei curbate, pentru a păstra vizibile ambele CTA-uri. Titlul este limitat la trei rânduri pe telefon,
  cu „începuturi frumoase” păstrat integral și spațiat corect pe ultimul rând,
  iar pe ecrane ultrawide fotografia rămâne `cover` și se estompează gradual în fundal la stânga,
  sus și jos de la 2200 px. La aceeași lățime, întregul hero este limitat la un cadru editorial
  central de 1800 px, astfel încât fotografia nu mai fuge până la marginea monitorului; laptopurile
  Full HD și tabletele păstrează încadrarea `cover`.
  Fotografia are parallax progresiv: se deplasează mai lent pe axa verticală la scroll și răspunde fin
  cursorului pe desktop, în timp ce pe ecranele tactile rămâne doar mișcarea verticală. Efectul este
  dezactivat complet când utilizatorul preferă mișcare redusă.
  înclinată din stânga sus spre dreapta jos, cu butoane pe toată lățimea și avantaje așezate vertical.
  Pe ecrane de minimum 2200 px, fotografia ocupă o zonă dedicată în dreapta și păstrează `cover`,
  pentru a umple complet cadrul central fără goluri vizuale.
- Secțiunea imediat următoare hero-ului este acum o prezentare editorială a colecțiilor, cu primele
  trei carduri evidențiate, o grilă de două coloane pe telefon și carduri animate prin apariție la
  scroll, reflex luminos, zoom fin, deplasarea conținutului și detalii grafice reactive la hover/focus.
  Zoom-ul imaginilor este mai pronunțat și rămâne fluid, iar introducerea secțiunii folosește acum
  un panou editorial translucid, cu expresii-cheie evidențiate și un accent auriu–coral. Rombul din
  supratitlu a fost înlocuit cu o floare animată discret, dezactivată la mișcare redusă.
  Eticheta repetitivă din interior a fost eliminată, astfel încât fiecare card păstrează doar numele
  categoriei și acțiunea „Descoperă”. Conținutul este
  alimentat exclusiv din categoriile principale publicate și publice din baza de date
  (`parent_id IS NULL`), în aceeași ordine ca bara principală de navigare: Nou-născuți, Fetițe,
  Băieți, Costume tradiționale, Botez, Încălțăminte și Cadouri. Categoriile temporare „Ținute de ocazie”
  și „Pijamale” au fost eliminate la cerere printr-o migrare separată. Categoria „Accesorii” și subcategoriile sale
  sunt arhivate și ascunse din storefront, fără ștergerea produselor asociate. Miniatura asociată categoriei este
  afișată când există; în lipsa ei se folosește un fundal grafic neutru, fără a inventa o fotografie
  de produs. Subcategoriile nu sunt incluse în această secțiune.
  Bara desktop păstrează denumirea completă „Costume tradiționale” și distribuie lățimea fiecărei opțiuni
  după conținut; separatoarele verticale se repoziționează automat între noile dimensiuni ale elementelor.

Pe desktop sunt păstrate în capsulă maximum șase categorii principale; restul sunt mutate în panoul
„Mai multe categorii”, accesibil din controlul cu două linii din stânga. La micșorarea lățimii, bara măsoară
spațiul real și mută succesiv ultima categorie obișnuită înainte ca textul ori separatoarele să se suprapună.
„Toate produsele” este afișat permanent înainte de „Nou-născuți”, trimite către pagina dedicată `/magazin/`
și nu este mutat în panoul de overflow; în meniul mobil apare imediat după „Acasă”, înaintea categoriilor din
baza de date. Pagina Magazin listează toate produsele publicate prin componenta comună de card, cu sortare,
paginare și layout responsive de patru, trei sau două coloane. Antetul Magazin este un interludiu editorial
compact, cu un singur titlu și un medalion decupat pe pisicuța cu fundiță din imaginea de brand; pe telefon
tipografia și medalionul se reduc fără suprapunere, iar ornamentele secundare sunt simplificate.
Sub antet, numărul total de produse și selectorul de sortare sunt reunite într-o bară editorială inspirată
din referința vizuală, adaptată paletei La Măriuca; selectorul aplică sortarea imediat cu JavaScript și păstrează
un buton de trimitere în fallback-ul fără JavaScript. Bara rămâne pe un singur rând și fără overflow la 390 px.
„Noutăți” și „Reduceri” rămân permanent fixate în dreapta și nu sunt mutate în panou. Categoria publicată
„Încălțăminte” rămâne inclusă în calcul și în ordinea navigației, fără duplicare în baza de date.
Categoria „Cadouri” are acum o imagine editorială originală optimizată WebP și un arbore public pe trei
niveluri: Cadouri → Cadouri pentru bebeluși → Seturi cadou. Navigația include categoriile publicate chiar
și fără imagine sau produse; miniatura rămâne opțională, iar nodurile fără media sunt afișate doar textual.
Grila colecțiilor folosește rânduri flexibile: cele trei carduri editoriale ocupă primul rând, iar cele patru
carduri compacte umplu integral al doilea rând. Pe tabletă și telefon sunt păstrate două carduri pe rând,
iar ultimul card impar se extinde pe lățimea disponibilă, fără celulă goală. Recalcularea overflow-ului
desktop urmărește numai schimbarea lățimii; expandarea submeniurilor mobile nu mai declanșează o buclă de
mutări DOM și redimensionări.
Pentru testarea configurației cu opt categorii principale a fost adăugată categoria publică „Jucării”. La
exact opt carduri, grila desktop se distribuie 4 + 4; pe telefon rămâne 2 + 2, fără spații neocupate.
Antetul secțiunii „Noutăți” are pe telefon un panou editorial compact, cu „Abia sosite în” pe primul rând și
„boutique” pe al doilea, accent decorativ discret și o acțiune tip capsulă simplă și compactă. Hover-ul
acțiunii păstrează o culoare stabilă, fără flash alb; spațiul gol dintre titlu și carusel a fost eliminat, fără a
modifica prezentarea desktop.
Subcategoriile din meniul mobil au fost refăcute ca rânduri tactile 2D, cu miniaturi mai mari, contrast și
spațiere mai bune; pe tabletă, primul nivel folosește două coloane pentru a evita panourile late și goale.
Dropdown-urile desktop folosesc acum miniaturi pătrate, compacte și consistente, cu textul și săgeata
aliniate într-un rând editorial; imaginile nu mai măresc necontrolat înălțimea panoului.
- După caruselul „Abia sosite în boutique”, homepage-ul include un banner editorial dedicat costumelor
  tradiționale: imaginea categoriei este în stânga și conținutul în dreapta pe desktop, iar pe telefon
  compoziția devine verticală. Steagul României, firul decorativ, fotografia, etichetele și CTA-ul folosesc
  animații fine, dezactivate automat pentru utilizatorii care preferă mișcare redusă. Subtitlul „purtată cu
  bucurie.” rămâne pe un singur rând, iar etichetele folosesc simbolurile feminin, masculin și stea.
- Între colecțiile vizuale și selecția de produse, homepage-ul include secțiunea editorială „Povestea
  noastră”, refăcută ca interludiu editorial compact și minimalist: fără panou supradimensionat, cu reper
  vertical, valori tipografice și un fir-inimă animat cu valuri caligrafice înalte, permanent vizibile și
  fără o bordură dreaptă care să le aplatizeze vizual. CTA-ul și medalionul
  trimit către pagina „Despre noi”; medalionul conține exclusiv logo-ul boutique-ului, fără fotografie,
  legendă ori alte elemente vizuale, iar mișcarea este dezactivată pentru `prefers-reduced-motion`. Pe
  telefon, compoziția este compactă: medalionul redus este aliniat cu CTA-ul, spațiul vertical inutil a fost
  eliminat, iar firul decorativ rămâne vizibil la baza secțiunii. Inima firului are un SVG separat, cu
  proporții păstrate pe orice lățime, astfel încât redimensionarea mobilă nu o mai îngustează sau deformează;
  traseul este continuu și nu mai introduce goluri animate între fir, inimă și terminație.
- După colecții, homepage-ul afișează secțiunea editorială „Alese cu drag pentru cei mici”, cu
  patru produse reale din catalog pe desktop și două pe rând pe telefon. Cardurile folosesc fotografia
  produsului sau, până la încărcarea fotografiilor individuale, miniatura categoriei asociate; includ
  favorite cu CSRF, denumire, preț, disponibilitate și acțiune directă pentru coș ori alegerea mărimii.
  Designul cardului urmează referința aprobată: fotografie aproape pătrată cu colțuri moi, etichetă
  suprapusă, Favorite într-un control circular și un CTA coral complet, cu pictograma coșului și săgeată.
  Pe telefon, CTA-ul păstrează „Adaugă în coș” pe un singur rând printr-un ritm tipografic și spațieri
  compacte, fără să reducă înălțimea tactilă a butonului.
  Pentru produsele care pot fi adăugate direct, CTA-ul este acum un toggle asincron sincronizat între
  toate aparițiile produsului din carusele: după adăugare rămâne verde, afișează o bifă animată și textul
  „Adăugat în coș” pe desktop, respectiv „Adăugat” pe telefon; următorul click scoate produsul din coș și
  readuce starea inițială. Starea este randată și server-side după reîncărcarea paginii.
  Hover-ul mărește fotografia discret și adaugă numai o umbră fină, iar mișcarea este eliminată prin
  `prefers-reduced-motion`. Produsele pot fi selectate individual din administrare prin opțiunea
  „Afișează în «Alese cu drag» pe homepage”; dacă nu este bifat niciun produs, repository-ul afișează
  automat cele mai recente patru produse publicate. Comportamentele de selecție, fallback și ordonarea
  produselor după publicare sunt acoperite de test de integrare.
- Ordinea secțiunilor comerciale este acum Hero → Descoperă colecțiile → Alese cu drag → Colecția de
  botez → Noutăți. Bannerul de botez folosește o compoziție editorială originală, optimizată WebP, în care
  se disting clar un costumaș pentru băieți, o rochiță și un trusou complet. Secțiunea are acum un cadru
  luminos, aliniat pe desktop la aceeași lățime de 1500 px ca bara principală de categorii, titlu editorial,
  filtre vizuale pentru cele trei familii de produse, CTA modern și animații
  discrete cu fallback pentru mișcare redusă, plus o versiune stivuită pentru telefon. Accentul din antet
  folosește o lumânare de botez SVG animată și o insignă editorială roz–auriu pentru eticheta colecției, iar sigiliul „Aleasă cu drag”, descrierea editorială și semnătura
  ornamentală au suprafețe, accente aurii-coral și o ierarhie adaptată tematicii ceremoniale. Atât CTA-ul, cât și
  fotografia editorială conduc accesibil către categoria completă de botez, iar „Noutăți alese cu drag” folosește
  independent cele mai noi produse publicate.
  Smoke HTTP pentru homepage a răspuns 200 și a confirmat toate cele trei secțiuni,
  textul cerut și opt carduri de produs; verificarea vizuală la 390 px a confirmat ancorarea corectă sub
  header, grila în două coloane și bannerul de botez adaptat.
- „Abia sosite în boutique” este acum un carusel cu butoane, gesturi tactile și autoderulare, alimentat
  automat cu maximum 15 produse publicate în ordinea `published_at DESC, id DESC`. Cardurile din această
  selecție primesc insigna animată „Recent adăugat”, scurtată responsive la „Nou” pe telefon, fără a exclude
  produsele afișate în selecția editorială. Titlul secțiunii are acum o compoziție editorială echilibrată,
  cu „boutique” evidențiat în rozul brandului, subliniere fină și un separator auriu–coral discret.
- Separatorul desktop dintre căutare și categorii este acum un element dedicat de 3 px, independent de
  glow-ul capsulei de categorii, astfel încât nu mai poate fi acoperit de stilurile meniului. Gradientul
  estompat auriu–roz inspirat din logo se deplasează continuu printr-un model repetabil fără salt la reluare. Pe tabletă și telefon este ascuns, iar
  preferința `prefers-reduced-motion` păstrează o versiune statică.
- Bara desktop de categorii este prezentată ca o capsulă alb-roz translucidă, cu margine și umbră
  discrete, separatoare verticale și chevroane coral în medalioane circulare plate, tratate în stil
  Material 3. Starea de hover folosește o suprafață tonală auriu-crem inspirată din logo, fără
  gradiente, reflexe sau umbre tridimensionale; chevronul SVG este centrat geometric și nu depinde
  de metrica fontului. Conținutul rămâne
  alimentat din arborele publicat al bazei de date; „Noutăți” și „Reduceri” rămân filtre speciale.
  O zonă invizibilă de tranziție între categorie și dropdown păstrează panoul deschis când cursorul
  este coborât spre subcategorii, inclusiv la deplasări ușor diagonale.
  Dropdownul subcategoriilor folosește acum un panou compact și plat, cu rânduri aerisite, stare
  activă roz-pal, chevroane SVG centrate și o tranziție scurtă de apariție în stil Material 3.
  „Noutăți” și „Reduceri” au pe desktop pictograme SVG dedicate — sclipire și etichetă — în
  medalioane tonale plate, cu o stare auriu-crem discretă la hover și focus.
- Meniul mobil folosește un overlay boutique complet: logo și mesaj de brand centrate, căutare
  integrată, categorii în carduri cu pictograme, mesaj editorial și acțiuni Favorite/Cont/Coș
  fixate în partea de jos. Fundalul floral cu pisicuță este servit ca asset public dedicat și
  randat în două zone cu proporțiile originale păstrate, inclusiv pe ecrane foarte înguste. Decorul
  superior rămâne în antet, iar pisicuța este legată de finalul listei, astfel încât nu se suprapune
  peste categorii pe telefoane scurte. Antetul fix are acum un fundal opac dedicat, lista revine
  la început la fiecare deschidere, iar pe telefoanele joase se activează un ritm compact care
  păstrează toate categoriile deasupra barei de acțiuni, inclusiv la 320 × 700 px. Animația panoului nu mai creează un
  context transformat, astfel încât bara Favorite/Cont/Coș rămâne ancorată corect în viewport.
  Deschiderea și închiderea au acum tranziții complete: antet și logo cu apariție fluidă, carduri
  eșalonate, pictograme elastice, linie decorativă trasată și bară de acțiuni glisantă; închiderea
  păstrează panoul în DOM până la finalul animației și suportă revenirea rapidă. Pentru utilizatorii
  cu `prefers-reduced-motion`, tranzițiile sunt eliminate.
  Varianta de tabletă folosește o coloană coerentă de maximum 820 px pentru căutare, categorii
  și ilustrația editorială, plus o zonă de siguranță separată sub antet, astfel încât prima
  categorie nu mai intră sub câmpul de căutare.
  Pe telefon, zona de siguranță dintre antetul fix și listă este acum mărită și adaptată pe
  înălțimea ecranului, astfel încât primul card rămâne complet vizibil și în afara umbrei căutării.
  Navigarea mobilă separă acum acțiunile: apăsarea pe card deschide categoria, iar butonul-chevron
  de minimum 44 px extinde subcategoriile. Arborele este randat recursiv pentru oricâte niveluri,
  stările `aria-expanded` sunt sincronizate, iar „Noutăți” și „Reduceri” nu afișează chevron.
  Extinderea și strângerea folosesc o tranziție fluidă pe înălțimea reală a conținutului, cu
  estompare și deplasare discretă; varianta `prefers-reduced-motion` rămâne instantanee.
  Primul card este acum „Acasă”, disponibil exclusiv în meniul mobil, cu o pictogramă-căsuță
  desenată progresiv, inimă pulsatorie, sclipiri și halo discret; starea activă este anunțată prin
  `aria-current`, iar toate efectele sunt dezactivate de `prefers-reduced-motion`.
  Miniaturile din meniu provin exclusiv din imaginea categoriei asociată în media; dacă imaginea
  lipsește, medalionul este omis complet și textul se realiniază automat, fără icon generic.
  Au fost generate și asociate în baza locală imagini editoriale coerente pentru cele șase
  categorii principale vizibile: „Nou-născuți”, „Fetițe”, „Băieți”, „Costume tradiționale”, „Botez” și
  „Încălțăminte”. Categoria „Costume tradiționale” are subcategoriile „Fetițe” și
  „Băieței”, fiecare cu propria imagine. Derivatele WebP optimizate sunt păstrate în
  `public/assets/images/categories/`, iar seederele demo creează sau actualizează idempotent categoriile
  și asocierile media în baza locală existentă și la o instalare nouă.
  Cardurile translucide păstrează contrastul textului.
  Legăturile rămân server-rendered și utilizabile fără JavaScript.
- Acțiunile „Favorite” și „Adaugă în coș” din storefront folosesc acum submit progresiv cu
  `fetch`, păstrând CSRF-ul și fallback-ul server-rendered fără JavaScript. La succes, pagina nu
  se mai reîncarcă: badge-urile din antet se actualizează imediat, inimile își sincronizează starea
  pe toate cardurile aceluiași produs, iar butonul de coș confirmă temporar „Adăugat în coș”.
  Confirmările și erorile sunt afișate într-un toast modern ancorat jos, care urcă fluid, se poate
  închide manual și respectă `prefers-reduced-motion`; vechiul mesaj Flash din partea de sus a fost
  eliminat și fallback-ul fără JavaScript folosește aceeași prezentare. Endpointurile returnează
  JSON numai când clientul îl solicită explicit și continuă să redirecționeze în fluxul clasic.
  Verificarea reală în browser a confirmat pentru ambele acțiuni URL neschimbat, badge-uri actualizate,
  stări animate și mesajele din partea de jos. La eliminare, starea selectată este retrasă imediat
  pe toate aparițiile produsului, inclusiv cât timp cursorul rămâne deasupra inimii; o animație
  separată golește inima, contractă halo-ul și confirmă acțiunea printr-un toast distinct. Selectarea
  folosește un puls elastic, halo și particule discrete, iar ambele tranziții respectă mișcarea redusă.
  Lint PHP, compilarea celor 31 de template-uri Twig,
  PHPStan, verificarea sintaxei JavaScript și suita PHPUnit (19 teste, 65 de aserțiuni) trec integral.
  Toastul rămâne vizibil 2,8 secunde; există cel mult o notificare, iar un mesaj nou actualizează
  cardul existent printr-un singur crossfade pe loc, reluând progresul fără suprapunere, deplasare
  verticală sau reaplicarea animației inițiale. Starea finală a crossfade-ului rămâne activă până
  la următorul mesaj ori până la închiderea toastului, prevenind flash-ul și relansarea tardivă a
  animației de intrare.
- Contul QA temporar a fost șters. Comanda demo `LM-2026-000001` rămâne în baza locală ca dovadă
  a smoke testului și nu conține date reale.

## 2026-10-21 — Newsletter editorial și footer responsive

- Zona finală a storefrontului a fost refăcută într-un stil editorial: banner newsletter cu fotografie
  originală, centru liber pentru conținut, formular compact, ornament cu inimă și linie animată.
- Footerul desktop are acum aceeași lățime vizuală ca navigația, logo, descriere, iconuri sociale,
  categorii principale preluate din arborele bazei de date, coloane informative și legale, avantaje și
  marcaje pentru metodele de plată. Cele patru panouri de navigare au lățimi și înălțimi egale pe desktop,
  iar conținutul panourilor mai scurte este distribuit echilibrat pe verticală.
- Semnătura de sub logo are un ornament liniar cu inimă, text echilibrat și accent tipografic coral pe
  „alese cu grijă”; pe mobil ornamentul și mesajul sunt centrate deasupra iconurilor sociale.
- Grupul social folosește acum patru simboluri vectoriale 2D — Facebook, Instagram, TikTok și Vinted —
  cu inel interior, accent auriu și animație de apariție; Pinterest și YouTube au fost eliminate.
- Avantajele „Livrare rapidă” și „Alese cu grijă” au fost mutate imediat sub rețelele sociale și refăcute
  ca două mini-carduri editoriale, cu pictograme vectoriale originale pentru transport și cadou; rămân
  vizibile și într-o compoziție compactă, pe două coloane, în footerul mobil.
- Marcajele metodelor de plată folosesc acum sigle SVG locale, cu proporțiile și culorile de brand pentru
  Visa, Mastercard, Apple Pay, Google Pay și Stripe; pe desktop sunt așezate sub avantajul „Alese cu grijă”,
  iar pe telefon apar imediat după acordeonul „Contul meu”. Reprezentările anterioare construite din text/CSS
  au fost eliminate. Apple Pay și Google Pay folosesc wordmark-uri curate în carduri 2D uniforme, fără
  contururile rigide ale vechilor pictograme.
- Pictograma ANPC pentru Soluționarea Alternativă a Litigiilor este disponibilă în panoul „Legal” pe desktop
  și imediat sub metodele de plată pe telefon; ambele variante trimit către pagina oficială SAL.
- Copyrightul folosește acum o compoziție editorială compactă, cu marcaj circular, numele boutique-ului
  evidențiat și drepturile păstrate pe un al doilea nivel tipografic. Semnătura CAB IT este aliniată în
  dreapta copyrightului pe desktop și imediat sub acesta pe telefon, cu link către site-ul realizatorului;
  creditul este prezentat liber, fără casetă, cu text HTML lizibil și grupul centrat corect în ambele layouturi.
- Coloana „Descoperă” începe cu „Toate produsele”, afișează inițial maximum patru categorii principale
  și dezvăluie progresiv următoarele grupuri de maximum patru prin butonul „… mai multe”; Noutățile și
  Reducerile rămân linkuri speciale separate, iar fără JavaScript toate categoriile rămân vizibile.
- Coloanele „Descoperă”, „Informații”, „Legal” și „Contul meu” folosesc panouri editoriale 2D pe desktop,
  iconuri tematice redesenate (geantă boutique, carte, scut și profil cu inimă) și stări hover fără umbre.
  Pe mobil, acordeoanele au animații line de deschidere și
  restrângere bazate pe înălțime, opacitate și translație, cu fallback pentru `prefers-reduced-motion`.
- Pe telefon, fotografia folosește un decupaj dedicat, formularul este vertical, iar coloanele sunt
  acordeoane tactile. Fără JavaScript, legăturile rămân server-rendered și vizibile; scriptul doar
  sincronizează starea deschis/închis la schimbarea breakpointului.
- Consimțământul newsletter folosește un switch tactil 2D, cu stare neutră albă și stare activă coral,
  bifă vizibilă, cursor glisant, spațiere optică egalizată în interior și focus accesibil din tastatură.
- Pe telefon, cardul newsletter ocupă acum întreaga lățime a viewportului; verificarea responsive la
  367 px a confirmat dimensiunile `left: 0`, `right: 367`, fără spații laterale reziduale.
- Imaginea generată este păstrată versionat și optimizată în `public/assets/images/footer-newsletter-v1.webp`; sursele
  existente din `logo-uri/` nu au fost modificate.
- Verificări reale: pagina răspunde HTTP 200, lint-ul proiectului și suita PHPUnit (21 teste,
  91 de aserțiuni) trec, iar previzualizarea în browser a confirmat desktopul, mobilul, deschiderea
  acordeonului și lipsa erorilor în consolă.
- Formularul newsletter este deocamdată prezentare UI; persistarea adreselor, confirmarea abonării și
  integrarea cu un furnizor de email rămân neimplementate până la alegerea serviciului și aprobarea
  explicită a configurării SMTP/marketing.

## 2026-10-21 — Hero personalizat cu fotografia de referință

- Imaginea principală a Hero-ului a fost refăcută pornind de la fotografia furnizată, păstrând chipul,
  expresia, coafura și ținuta tradițională alb-albastră a fetiței.
- Compoziția păstrează atmosfera crem-roz, pisicuța cu fundă și spațiul luminos din stânga necesar
  textului, butoanelor și decupajelor responsive existente.
- Varianta nouă este salvată versionat în
  `public/assets/images/hero/la-mariuca-hero-v4-sora-costum-traditional.png`; imaginea Hero anterioară
  a rămas intactă pentru revenire sau comparație.
- Verificări reale: Hero-ul a fost inspectat vizual pe desktop și telefon, fără decuparea chipului sau
  pierderea ținutei; pagina răspunde HTTP 200, nu produce erori în consola browserului, lint-ul trece,
  iar suita PHPUnit rulează cu succes toate cele 21 de teste și 91 de aserțiuni.

## 2026-10-23 — Panouri Favorite și Coș

- Panourile desktop pentru Favorite și Coș au fost refăcute ca elemente editoriale compacte, cu
  pictograme dedicate, ierarhie tipografică mai clară, fundal crem-roz și acțiuni pe toată lățimea.
- Coșul afișează separat numărul de produse și totalul estimat, iar Favorite păstrează un mesaj
  scurt și cald; ambele panouri păstrează și variantele ilustrate pentru starea goală.
- Actualizările asincrone reconstruiesc acum aceeași structură vizuală după adăugare sau eliminare,
  fără refresh și fără revenirea la vechile casete de text.
- Animația de intrare și tranzițiile sunt scurte și plate, fără flash alb, iar pentru
  `prefers-reduced-motion` mișcarea este dezactivată.
- Pictogramele rețelelor sociale din footer folosesc acum cursorul `grab`, care devine `grabbing`
  în timpul apăsării, pentru feedback-ul tactil solicitat.
- Verificări reale: ambele panouri populate au fost inspectate vizual în browserul desktop, pagina
  răspunde HTTP 200, lint-ul și compilarea celor 31 de șabloane Twig trec, PHPStan nu raportează
  erori, iar PHPUnit rulează cu succes toate cele 21 de teste și 91 de aserțiuni.

## 2026-10-23 — Contact WhatsApp global

- Toate paginile storefront includ acum un buton WhatsApp fix în colțul din dreapta-jos, furnizat de
  layoutul comun și accesibil prin tastatură și cititoare de ecran.
- Pe telefon este afișată exclusiv sigla într-un medalion alb de 56 px; pe desktop componenta pornește
  compact și se extinde fluid la hover sau focus pentru a afișa „Hai sa vorbim!”.
- Designul folosește o capsulă albă cu text verde și un medalion WhatsApp verde cu siglă albă, cerc
  dublu, pulsație, salut animat al pictogramei, bule de conversație cu indicator de scriere și reflexie
  fină; animațiile sunt eliminate pentru `prefers-reduced-motion`.
- Butonul se ridică automat deasupra toasturilor și se ascunde cât timp meniul mobil ocupă ecranul,
  prevenind suprapunerile cu alte acțiuni importante.
- Destinația și mesajul inițial pot fi configurate prin `WHATSAPP_NUMBER` și `WHATSAPP_MESSAGE`;
  fără număr, linkul deschide WhatsApp cu mesajul pregătit și permite alegerea conversației.
- Verificări reale: componenta a fost inspectată la 1920 px și 390 px, inclusiv starea desktop
  extinsă, fără erori în consola browserului; homepage-ul, o categorie și un produs răspund HTTP 200
  și conțin componenta, cele 31 de șabloane Twig se compilează, PHPStan nu raportează erori, iar
  PHPUnit rulează cu succes toate cele 21 de teste și 91 de aserțiuni.

## 2026-10-23 — Pagina Favorite

- Pagina `/favorite/` folosește acum exact componenta comună de produs din homepage, cu imagine,
  categorie, preț comparativ, stoc, variante, personalizări, Favorite și acțiunea corectă pentru coș.
- Datele produselor favorite sunt hidratate pentru cardul comun, iar produsele retrase sau
  nepublicate sunt eliminate din sesiune pentru ca numărul din navbar să rămână corect.
- Antetul paginii a fost refăcut ca o compoziție compactă și directă: fără acțiuni redundante, cu
  simbolul comun de Favorite lângă „Selecția ta”, numărul actualizat live în medalion și eticheta
  „alese de tine”; grila afișează patru carduri pe desktop, trei pe ecrane intermediare și două pe mobil.
- Eliminarea unui produs funcționează asincron, animă ieșirea cardului, actualizează contoarele și
  afișează automat starea goală când este eliminat ultimul produs; submitul server-side rămâne fallback.
- Mișcările decorative sunt oprite pentru `prefers-reduced-motion`, iar starea goală reutilizează
  panoul accesibil existent.
- Eticheta „Lista se actualizează automat” a fost eliminată pentru un antet de listă mai simplu.
  Întârzierile animațiilor cardurilor și meniului folosesc acum exclusiv clase și selectori din
  stylesheet, iar inimile animate din navbar folosesc variante CSS predefinite; pagina Favorite nu
  mai generează stiluri inline blocate de politica CSP.
- Verificări reale: pagina populată și starea goală au fost testate în browser la lățime desktop,
  390 px și 320 px; eliminarea ultimului produs actualizează medalionul la zero și afișează starea
  goală fără refresh, iar produsul de test a fost readăugat la final. După corecția CSP, reload-ul cu
  patru produse a confirmat zero stiluri inline și zero erori în consolă. Compilarea celor 31 de
  șabloane Twig, lint-ul, PHPStan, verificarea sintaxei JavaScript și PHPUnit (21 teste,
  91 de aserțiuni) trec.

## 2026-10-23 — Filtre sticky în Magazin

- Pagina `/magazin/` are acum un panou editorial de filtrare în stânga, cu imagini și contoare reale
  pentru categoriile principale, disponibilitate și buget maxim calculat din prețurile variantelor active.
- Filtrele sunt aplicate server-side, păstrează sortarea și paginarea, includ descendenții categoriei
  selectate și au fallback complet fără JavaScript; selecțiile filtrate sunt marcate `noindex`.
- Panoul rămâne sticky sub header cât timp sunt parcurse produsele, are acțiunea principală vizibilă și
  o stare goală dedicată; pe telefon devine un acordeon compact, închis implicit prin enhancement JS.
- Antetul „Rafinare / Filtre” și zona cu „Arată produsele / Resetează filtrele” sunt independente de
  lista scrollabilă din mijloc, astfel încât ambele rămân permanent accesibile pe desktop; antetul este
  un rând modern și compact, cu pictogramă de reglaje și titlu clar.
- Selectorul de buget are valoarea într-o capsulă separată, progres vizual sincronizat și un cursor
  personalizat, fără suprapuneri la valoarea maximă sau pe ecranele mobile; pasul este de 1 leu, iar
  interacțiunea animă discret cursorul, progresul și capsula valorii, cu respectarea `prefers-reduced-motion`.
  Tragerea folosește un control precis propriu, astfel încât un pixel deplasat înseamnă exact 1 leu chiar
  dacă bara este mai îngustă decât numărul valorilor din interval.
- Numărul din butonul „Arată produsele” este recalculat automat la schimbarea categoriei,
  disponibilității sau bugetului, înainte ca filtrele să fie aplicate paginii; cererile sliderului sunt
  temporizate și cele depășite sunt anulate.
- Categoriile sunt prezentate ca mini-carduri compacte cu toate colțurile uniform rotunjite, imagini
  pătrate clare, numere rotunde și o stare selectată bine delimitată, păstrând totodată comenzile radio
  accesibile din tastatură; spațiul dintre titluri și controale a fost redus uniform pentru categorii,
  disponibilitate și buget, iar comportamentul nativ al elementelor `legend` a fost normalizat pentru ca
  separatoarele să nu mai treacă lipit peste titluri.
- Filtrul de categorii redă acum întreaga ierarhie recursivă, inclusiv subcategorii ale subcategoriilor;
  fiecare nivel poate fi selectat separat, ramurile au controale accesibile de extindere, iar ramura
  selecției curente se deschide automat. Fără JavaScript, arborele rămâne complet vizibil și utilizabil.
- Catalogul păstrează patru coloane pe desktop, cu carduri ușor micșorate, trei coloane pe tabletă și
  două pe telefon.
- Magazinul are paginare server-side completă și un selector „Produse pe pagină” așezat în partea
  stângă a paginatorului, cu valorile permise 12, 24 și 50; filtrele și sortarea păstrează alegerea,
  iar valorile arbitrare revin sigur la 12.
  Controlul de paginare afișează intervalul curent, totalul produselor, numărul paginii, săgeți și
  numere cu puncte de suspensie pentru cataloage mari; pe mobil devine un panou compact, cu zona
  numerelor derulabilă doar dacă nu mai încape pe un ecran foarte îngust.
- Verificări reale: filtrul Botez a redus catalogul de la 12 la 3 produse și resetarea a restaurat toate
  cele 12; la 1440 px sunt patru carduri de aproximativ 216 px și panoul de 250 px, iar la 390 px sunt
  două coloane fără overflow. Selectorii 24 și 50 au fost verificați în browser, iar valoarea 24 s-a
  păstrat după aplicarea filtrului Botez. Lint-ul, 32 de șabloane Twig, PHPStan și PHPUnit (22 teste,
  120 de aserțiuni) trec; consola browserului nu raportează erori.

## IMPLEMENTED_UNVERIFIED

- Schema MySQL/MariaDB InnoDB/utf8mb4 și query-urile portabile; motorul real nu este disponibil local.
- Stripe Checkout hosted + webhook semnat/idempotent pentru confirmarea plății; lipsesc chei test.
- SMTP Symfony Mailer în producție și cron pentru outbox/expirări; local s-a verificat transportul
  privat prin fișiere, nu livrarea SMTP.
- Configurarea `APP_BASE_PATH` și `APP_ROOT` pentru shared hosting; Apache nu este disponibil în PATH.
- TOTP UI/admin, secret criptat și recovery codes; logica a fost testată automat, nu cu o aplicație
  Authenticator reală în browser.

## BLOCKED_EXTERNAL_CONFIG

- Versiunea PHP și extensiile hostingului, Apache `mod_rewrite`/`AllowOverride`, DocumentRoot,
  permisiunile, cron și structura `public_html`/`httpdocs`.
- MySQL/MariaDB real și testele cu două conexiuni concurente pentru ultima bucată/cupoane.
- Chei Stripe test/live, endpoint HTTPS public și configurarea webhookului.
- SMTP, identitatea expeditorului și politica de retry/monitorizare a hostingului.
- Date comerciant, taxe, tarife/zone curier, prag transport, politici juridice și retenție.
- Logo/favicon/fotografii finale, asocieri media și aprobarea designului.

## Incomplet față de specificația integrală

- UI complet CRUD pentru variante/atribute, filtre multi-atribut și ghiduri pe gamă;
- configurator de meniu/mega-menu și carduri promoționale;
- asociere media cu produse/categorii, seturi responsive `srcset` și focal point;
- verificare email/resetare parolă, adrese client, merge favorite și retrimitere token comandă guest;
- interfață admin pentru clienți, atribute, livrare, plăți și setări sensibile pe roluri;
- import CSV cu scriere (există export și dry-run/reimport validation, scrierea este blocată explicit);
- refund sync Stripe, reconciliere completă a evenimentelor out-of-order și plăți tardive;
- stoc retur după recepție, cupoane editabile/arhivabile și colecții dinamice calculate automat;
- consimțământ cookies/analytics complet, export/ștergere date și politică de retenție aplicată;
- suită completă de acceptanță cerută, inclusiv upload malițios HTTP, reset token și SMTP failure.

## Următorul pas exact

Configurează un MySQL/MariaDB local de test și rulează instalarea din bază goală, apoi adaugă testul
concurent cu două conexiuni pentru ultima bucată. După aceea se poate continua UI-ul de variante,
atribute și filtre fără schimbarea contractelor existente.
## 2026-10-20 — Buton produs 2D

- Butonul „Adaugă în coș” din cardurile de produs folosește acum un design plat, fără umbre, reflexii sau ridicare pe hover.
- Cardurile editoriale de produs au fost rafinate pentru cumpărare: pe mobil CTA-ul are text puțin mai mare și o zonă tactilă de minimum 48 px, iar pe desktop distanța dintre titlu și preț este mai compactă fără să se piardă alinierea între carduri.
- Hover-ul cardurilor rulează în 240 ms, cu zoom discret de 3% și ridicare de 3 px; toate aceste transformări sunt eliminate automat pentru `prefers-reduced-motion`.
- Confirmarea asincronă la adăugarea în coș include acum miniatura și denumirea produsului plus acțiunea „Vezi coșul”, fără refresh sau întreruperea navigării. Imaginea principală/fallback-ul de categorie este disponibil și în pagina produsului pentru această confirmare.
- CTA-ul „Alege mărimea” folosește o pictogramă dedicată de riglă gradată, înclinată, în locul coșului, astfel încât produsele cu variante de mărime să fie diferențiate imediat de cele care pot fi adăugate direct.
- Rigla din CTA este mărită și are un contur ușor mai ferm pe mobil și desktop, fără să modifice înălțimea butonului.
- Pentru un card cu mai multe mărimi, CTA-ul este simplificat la „Alege”; pictograma de riglă păstrează clar contextul acțiunii.
- „Selecția La Măriuca” este prezentată într-un carusel responsive cu patru produse vizibile pe desktop și câte două pe tabletă/telefon. Swipe-ul și dragul sunt active doar până la 1024 px, desktopul folosește butoanele anterior/următor și auto-derularea la un interval de 1,5 secunde, iar contorul și progresul rămân sincronizate. După săgeți, butoanele produselor, swipe sau drag, caruselul așteaptă exact 3 secunde și apoi reia automat ciclul, inclusiv când controlul apăsat a păstrat focusul. Hoverul pasiv și navigarea exclusiv din tastatură continuă să suspende mișcarea, iar `prefers-reduced-motion` dezactivează autoplay-ul și tranzițiile. Produsele selectate manual din administrare apar primele, apoi selecția este completată automat cu produse recente până la opt carduri.
- Starea de hover schimbă doar paleta și animă discret marcajul lateral și săgeata; feedback-ul de adăugare păstrează o animație scurtă fără efect 3D.
- Produsele fără stoc folosesc un CTA 2D distinct „Stoc epuizat / Vezi detalii”, cu pictogramă de colet barat, indicator animat și fundal crem-roz în mișcare discretă; linkul rămâne disponibil către detaliile produsului și respectă `prefers-reduced-motion`.
- Toasturile ascund explicit miniatura și acțiunea atunci când tipul notificării nu le folosește, prevenind apariția casetelor goale și ruperea mesajului de favorite pe lățimi mici.
- Săgeata este un chevron SVG, pictograma coșului este mai generoasă, iar marcajul vertical decorativ apare doar pe desktop.
- Chevronul butonului este ascuns pe telefon și rămâne vizibil doar pe ecranele desktop.
- Favoritele din cardurile produselor reutilizează acum aceeași pictogramă cu două inimi ca navbarul, inclusiv în stările hover, selectat și eliminat.
- Cardurile limitează titlurile la două rânduri, reduc distanța până la preț și aliniază acțiunile la baza cardului; pe mobil butonul are o singură pictogramă și text mai lizibil.
- Produsele cu variante multiple sau personalizare obligatorie trimit către pagina de opțiuni, iar trusoul demo are o fotografie proprie care prezintă conținutul pachetului.
- „Costumaș «Micul gentleman»” are două variante demo reale, 2 ani și 4 ani, fiecare cu stoc separat; cardul trimite către selectorul de mărime.

## 2026-10-20 — Utilitar local Curățător Windows 11

- A fost adăugat separat, în `tools/WindowsCleaner/`, un utilitar WinForms românesc pentru Windows 11 x64, publicat ca executabil autonom cu o singură piesă.
- Curățarea folosește ținte explicite, nu urmărește junction-uri/legături simbolice, păstrează temporarele mai noi de 24 de ore și nu include registry cleaner, debloat, oprirea serviciilor sau ștergerea cache-ului Windows Update.
- Categoriile acoperă temporare, cache-uri de miniaturi/DirectX/browsere, rapoarte și dump-uri, Coșul de reciclare și cache-ul DNS; opțiunile cu risc de pierdere a diagnosticelor ori de ștergere definitivă sunt nebifate implicit.
- Optimizarea RAM este opțională și reduce doar working set-ul proceselor neesențiale din sesiunea utilizatorului, fără închiderea aplicațiilor; procesele Windows critice și shell-ul sunt excluse.
- Verificări reale: build Release cu avertismente tratate ca erori, smoke test de scanare/ștergere într-un director temporar izolat, verificarea refuzului pentru Documents și inițializarea interfeței pe fir STA — toate au trecut. Testul pentru legături simbolice se rulează automat numai când configurația Windows permite crearea lor.
- Curățarea pe datele reale ale calculatorului și execuția optimizării RAM nu au fost lansate în timpul testului. Executabilul local este autonom, dar nu este semnat digital.
- Filtrul ierarhic de categorii din Magazin a fost simplificat vizual: miniaturile apar numai când există o imagine reală, fără inițiale sau casete goale, denumirile lungi se împart sigur pe rânduri, iar selectorul, numărul și controlul de extindere ocupă coloane separate fără suprapuneri.
- Categoriile și subcategoriile din filtrul Magazin folosesc checkboxuri cu selecție multiplă; interogarea reunește ramurile selectate împreună cu descendenții lor, elimină duplicatele și păstrează selecțiile în sortare și paginare. Opțiunea „Toate produsele” se deselectează imediat când este bifată orice ramură și se reactivează automat când nu mai există nicio selecție.
- Catalogul local include o colecție editorială suplimentară de 30 de produse, distribuită în toate cele opt categorii principale publice. Fiecare produs are text complet, stoc, preț, relații cu întreaga ramură de categorii și o imagine WebP proprie; seederul `seed:catalog` este tranzacțional și idempotent.
- Pagina de produs afișează acum imaginea principală din `product_images` (sau imaginea categoriei ca rezervă), cu text alternativ și prezentare pătrată responsive; mesajul de galerie incompletă rămâne doar pentru produsele fără nicio imagine disponibilă.
- Cardurile din ambele carusele ale paginii principale rămân interactive inclusiv în copiile temporare folosite pentru bucla infinită: hoverul și linkurile nu mai sunt blocate, iar un click pe suprafața liberă a cardului deschide produsul fără să interfereze cu gesturile, favoritele sau coșul.
- Pe desktop, coloana filtrului lateral din Magazin a fost lărgită discret de la maximum 250 px la 272 px, astfel încât denumirile lungi ale categoriilor să nu mai fie fragmentate excesiv, păstrând grila cu patru produse.
- Pagina de căutare folosește acum un hero editorial responsive, sugestii rapide de categorii, o stare fără rezultate utilă și cardurile moderne ale catalogului; căutările reușite afișează produsele înaintea zonei de descoperire, în patru coloane pe desktop și două pe telefon. Controalele de ștergere și închidere ale căutării au fost redesenate și centrate, cu stări clare pentru hover, focus și atingere.
- Rezultatele căutării sunt paginate server-side câte 12, 24 sau 50 de produse; paginatorul apare numai când totalul depășește limita aleasă și păstrează termenul căutat între pagini. Au fost verificate pagina a doua, selectorul de limită, layoutul desktop și cel de 390 px, fără overflow ori erori în consolă.

## 2026-10-24 — Controale și grilă responsive în Magazin

- Sub 1101 px, contorul produselor este înlocuit de un carusel pe un singur rând cu toate categoriile principale, carduri cu patru colțuri rotunjite uniform, miniaturi numai pentru categoriile care au imagine și link direct spre pagina categoriei. Caruselul avansează în buclă la fiecare 1,7 secunde, acceptă swipe/drag și repornește intervalul complet după orice gest; fără JavaScript rămâne o listă orizontală derulabilă. De la 1101 px revine contorul, odată cu filtrul complet din stânga.
- Grila Magazinului păstrează explicit două coloane pe telefon, trei pe tabletă și patru pe calculator. Sub 340 px, CTA-ul produsului se scurtează vizual la „Adaugă”, fără schimbarea etichetei accesibile complete.
- Pe telefon, selectorul de sortare folosește etichetele scurte „Noi”, „Crescător”, „Descrescător”, „Nume A–Z” și „Nume Z–A”, astfel încât selecția să fie afișată integral; de la tabletă în sus sunt restaurate automat formulările complete. Cele două ordini alfabetice sunt aplicate server-side, iar vechiul parametru `sortare=name` este normalizat la A–Z.
- Sub 1101 px, filtrarea îmbunătățită cu JavaScript se deschide într-un drawer din dreapta, cu overlay estompat, tranziții simetrice la deschidere și închidere, antet propriu și buton X. Dialogul blochează scrollul paginii, mută focusul pe închidere, îl păstrează în panou și se închide prin X, Escape sau click pe overlay; pe desktop rămâne panoul sticky existent.
- Paginatorul de telefon este un singur card editorial: numărul de produse pe pagină și poziția curentă formează antetul, iar butoanele paginilor sunt grupate într-o bandă separată, cu dimensiuni tactile și fără overflow inclusiv la 320 px.
- Pe dispozitivele cu hover, fotografia fiecărui produs are un zoom fluid până la `1.09×`, cu accent discret de saturație, contrast, luminozitate și reflexie; navigarea din tastatură folosește un zoom mai calm, iar preferința pentru mișcare redusă dezactivează tranzițiile.
- Verificarea reală în browser a confirmat două coloane la 320/390/450 px, trei la 800/978 px și patru la 1280 px, fără overflow orizontal; eticheta „Descrescător” încape integral la 320 px, iar la 800 px selectorul revine la „Preț descrescător”. Caruselul a fost inspectat la 390 și 978 px: avansează după 1,7 secunde, rămâne oprit cât timp are focus, reia intervalul complet după interacțiune, omite corect imaginea categoriei „Jucării” și navighează spre `/categorie/jucarii/`; la 1280 px este ascuns, contorul reapare și filtrul este sticky. Drawer-ul a fost verificat la 390 și 978 px, inclusiv închiderea prin X, Escape și overlay, revenirea focusului și lățimea maximă de 430 px. Paginatorul reproiectat a fost verificat vizual la 390 și 320 px, iar regula de hover și tranziția de focus au fost confirmate într-un browser cu pointer fin, fără erori în consolă.
- Verificări locale trecute: lint PHP, compilarea tuturor celor 32 de template-uri Twig, PHPStan fără erori și PHPUnit cu 23 teste / 135 aserțiuni. Comenzile Composer standard rămân blocate în mediul CLI implicit deoarece instalarea PHP nu încarcă niciun `php.ini` și, implicit, nici OpenSSL; aceleași verificări au fost rulate cu DLL-urile de extensii existente încărcate explicit.

## 2026-10-24 — URL-uri ierarhice pentru categorii

- Categoria și fiecare subcategorie folosesc o adresă canonică formată din întregul lanț de părinți, de exemplu `/categorie/fetite/rochite/rochite-ocazie/`, fără limită fixă de niveluri.
- Vechile URL-uri plate ale subcategoriilor răspund cu redirect permanent 301 și păstrează query string-ul; un lanț de părinți inventat sau un părinte nepublicat răspunde 404.
- Meniurile, caruselul Magazinului, homepage-ul, sugestiile de căutare, breadcrumb-urile produselor și sitemap-ul emit direct URL-urile ierarhice, evitând redirecturile în navigarea internă.
- Paginile de categorie și produs includ breadcrumb HTML complet și date structurate `BreadcrumbList`; canonical-ul categoriei folosește aceeași adresă ierarhică.
- Redirecturile de slug sunt validate pe fiecare segment al ierarhiei, astfel încât o adresă canonică veche rămâne recuperabilă fără ca un lanț arbitrar de categorii să fie acceptat.
- Verificări locale trecute: lint PHP, compilarea tuturor celor 32 de template-uri Twig, PHPStan fără erori, smoke HTTP pentru 301/200/404, canonical, breadcrumb și sitemap, plus PHPUnit cu 31 de teste / 158 de aserțiuni. Extensiile PHP existente au fost încărcate explicit deoarece runtime-ul CLI implicit nu folosește niciun `php.ini`.

## 2026-10-24 — Pagini uniforme pentru categorii

- Paginile de categorie și subcategorie reutilizează limbajul vizual al Magazinului: antet editorial, toolbar de sortare, carduri de produs, paginator și grila responsive cu două coloane pe telefon, trei pe tabletă și patru pe desktop.
- Portretul editorial afișează imaginea reală asociată categoriei sau subcategoriei numai când aceasta există. În lipsa imaginii, antetul ocupă elegant toată lățimea și nu mai afișează pisica ori alt fallback decorativ.
- Listarea folosește aceleași date bogate și aceleași acțiuni ca Magazinul, cu sortare după noutate, preț sau nume și opțiuni de 12, 24 ori 50 de produse pe pagină.
- Verificarea vizuală în browser a fost făcută la 390, 800 și 1280 px, atât pentru categoria „Fetițe”, care are imagine, cât și pentru subcategoria „Rochițe de ocazie”, care nu are imagine. Au fost confirmate absența fallback-ului, grila 2/3/4 și lipsa overflow-ului orizontal.
- Verificări locale trecute: lint PHP, compilarea tuturor celor 32 de template-uri Twig, PHPStan fără erori și PHPUnit cu 33 de teste / 168 de aserțiuni.

## 2026-10-24 — Filtre dinamice în categorii

- Magazinul și paginile de categorie/subcategorie folosesc aceeași componentă Twig pentru toolbar, filtre, rezultate și paginator, eliminând diferențele de structură și stil dintre ele.
- Într-o categorie sunt propuse automat numai subcategoriile publice din ramura curentă, cu numărul de produse calculat inclusiv pentru descendenți. Parametrii pentru categorii din afara ramurii sunt ignorați server-side.
- Disponibilitatea, numărul de rezultate și intervalul de preț sunt calculate în contextul categoriei sau subcategoriei curente. O subcategorie finală păstrează numai filtrele aplicabile, fără un grup gol de subcategorii.
- Caruselul de categorii rămâne exclusiv în Magazin; paginile de categorie și subcategorie afișează contorul de produse pe mobil, urmat de sortare și butonul drawer-ului de filtre.
- Verificarea vizuală reală a confirmat layout-ul cu două coloane la 390 px, trei la 800 px și patru plus filtrul lateral la 1280 px. Drawer-ul a fost verificat atât pentru „Fetițe”, cu subcategorii dinamice, cât și pentru „Rochițe de ocazie”, fără grup de subcategorii.
- Verificări locale trecute: lint PHP, compilarea tuturor celor 33 de template-uri Twig, PHPStan fără erori și PHPUnit cu 35 de teste / 193 de aserțiuni.

## 2026-10-24 — Căutare mobilă editorială

- Panoul de sugestii folosește acum un antet compact și carduri separate pentru categorii și produse, cu un CTA persistent către toate rezultatele. Pe telefon, categoriile rapide formează un singur rând glisabil; pe desktop, categoriile și produsele sugerate sunt așezate în două coloane aerisite.
- Sugestiile folosesc fotografiile reale din catalog. O categorie sau un produs fără imagine disponibilă nu mai primește emoji, inițială, miniatură generică ori spațiu rezervat; textul ocupă direct lățimea cardului.
- Săgețile acțiunilor sunt chevroane SVG centrate geometric în cercurile lor, fără abaterea de bază produsă de vechiul caracter tipografic `›`.
- Fundalul panoului este complet opac, scrollbarul intern este ascuns, iar rândul glisabil are o estompare discretă la margine pentru a comunica gestul fără ca ultimul element tăiat să pară o eroare. La 320 px este blocat overflow-ul orizontal al paginii.
- Când căutarea este deschisă pe telefon, headerul este fixat în viewport, pagina din spate este blocată și estompată, iar WhatsApp este ascuns temporar; panoul rămâne utilizabil inclusiv dacă interogarea pornește de la mijlocul sau finalul paginii.
- Verificarea reală în browser a fost făcută la 390 și 320 px, inclusiv după derularea paginii, și la 1280 px cu interogarea „cost”: URL-urile ierarhice, imaginile reale, lipsa miniaturilor false, cardurile desktop, închiderea panoului și CTA-ul persistent au fost confirmate vizual.
- Verificări locale trecute: lint PHP/JavaScript, răspunsul JSON live pentru imagini și PHPUnit cu 35 de teste / 198 de aserțiuni. Runtime-ul CLI a necesitat încărcarea explicită a extensiilor instalate, deoarece nu folosește implicit un `php.ini`.

## 2026-10-24 — CTA principal homepage

- Butonul principal „Descoperă produsele” din hero deschide acum pagina `/magazin/` în locul ancorei interne către selecția de produse.
- Verificări locale trecute: lint-ul proiectului și răspuns HTTP 200 pentru `/magazin/`.

## 2026-10-24 — Căutare semantică unificată

- Căutarea rapidă din navigație și pagina `/cautare/` folosesc același serviciu de normalizare și clasare, astfel încât ordinea și relevanța produselor sunt consecvente în ambele interfețe.
- Diacriticele, singularul/pluralul, termenii echivalenți uzuali din catalog și greșelile mici de tastare sunt tolerate. Toți termenii importanți ai interogării trebuie însă să aibă o potrivire suficient de puternică în nume, categorii, descriere ori SKU, prevenind afișarea produselor fără legătură.
- Un SKU complet este comparat fără diferență între litere mari și mici și returnează exclusiv produsul său. Un nume exact sau o potrivire unică foarte apropiată a numelui returnează tot un singur produs și nu amestecă sugestii de categorii.
- Indexul este construit din toate produsele publicate, variantele active și categoriile existente în baza de date la fiecare cerere nouă; produsele adăugate ulterior intră automat în căutare, fără o listă manuală de produse. Lista manuală acoperă numai echivalențe lingvistice românești.
- Verificarea live a confirmat potrivirea unică pentru `lm-b-001-2a` și `costumas micul gentelman`, rezultatul corect pentru `rochii botez`, căutarea semantică `crestinare` și lipsa rezultatelor pentru combinațiile incoerente `rochie baieti` și `rochie astronaut`. Lint-ul, compilarea celor 33 de template-uri Twig, PHPStan și PHPUnit cu 39 de teste / 217 aserțiuni trec.

## 2026-10-24 — Galerie „Cuburi moi «Poveste»”

- Produsul are două fotografii editoriale noi, optimizate WebP la 960 × 960 px: un cadru de sus și un cadru apropiat cu cubul lună în prim-plan. Imaginea principală existentă este păstrată, iar cele trei imagini au texte alternative proprii.
- Pagina de produs încarcă toate imaginile asociate din `product_images` și afișează o galerie cu miniaturi accesibile, stare activă și tranziție discretă; în lipsa JavaScriptului rămân vizibile fotografia principală și miniaturile.
- Seederul catalogului extins asociază toate cele trei imagini în ordine la instalările noi, iar baza locală a fost actualizată idempotent cu cele două fișiere media suplimentare.
- Verificarea reală în browser a confirmat cele trei miniaturi și schimbarea corectă a imaginii principale pentru ambele fotografii noi. Lint-ul PHP/JavaScript, compilarea celor 33 de template-uri Twig, PHPStan și PHPUnit cu 39 de teste / 219 aserțiuni trec.

## 2026-10-24 — Pagină de produs premium responsive

- Pagina produsului folosește acum un layout editorial La Măriuca: galerie mare, panou de cumpărare separat, preț și SKU bine ierarhizate, selector de variantă, control cantitate și CTA de coș unitar cu restul magazinului.
- Pe desktop, galeria are rail vertical cu miniaturi, navigare anterior/următor, stare de stoc, favorit și zoom. Pe telefon, fotografia ocupă aproape toată lățimea, cu colțuri moi, săgeți tactile și indicator tip capsulă; swipe-ul schimbă imaginile fără să blocheze derularea verticală.
- Favoritul suprapus peste fotografie reutilizează exact assetul cu două inimi din navbar, inclusiv starea selectată și animațiile existente, în locul unei pictograme independente.
- Pe telefon, titlul produsului folosește o scară tipografică mai compactă și întreaga lățime a panoului, evitând ruperea disproporționată a numelor lungi și păstrând mai mult spațiu vizual în dreapta.
- Pe desktop, titlul produsului are acum o scară maximă mai temperată și folosește lățimea disponibilă a panoului, fără limita îngustă care îl rupea prematur pe două rânduri.
- Pe telefon, panoul complet de cumpărare se extinde până la marginile viewportului, păstrând spațiere interioară confortabilă și colțurile editoriale rotunjite.
- SKU-ul este afișat direct într-o capsulă lângă categoria produsului, deasupra titlului, fără eticheta redundantă „Cod produs”; capsula continuă să se actualizeze la schimbarea variantei.
- Breadcrumb-ul paginii de produs se oprește la categoria curentă și nu mai repetă numele produsului deasupra galeriei.
- Produsul demo „Cuburi moi «Poveste»” păstrează prețul curent de 139 RON și are preț comparativ de 169 RON, inclus și în seeder pentru refacerea coerentă a catalogului local.
- Descrierea scurtă rămâne alimentată din câmpul `short_description` al fiecărui produs și este prezentată într-un cartuș editorial propriu, cu eticheta „Pe scurt”, assetul cu inimioare folosit deja în navbar și accente La Măriuca responsive; pe telefon, textul, iconița și spațierea sunt compactate proporțional.
- Lightbox-ul accesibil afișează imaginea mare, contorul curent, navigare din săgeți sau tastatură, swipe, zoom între 100% și 200% și închidere animată prin buton sau Escape.
- Zona de detalii a devenit un grup de panouri semantice pentru descriere, nota informativă, materiale și dimensiuni. Pagina rămâne funcțională fără JavaScript, iar tranzițiile respectă `prefers-reduced-motion`.
- Prețul, SKU-ul, stocul și textul CTA-ului se actualizează la schimbarea variantei fără a distruge pictogramele butonului; confirmarea asincronă de adăugare în coș păstrează noua structură vizuală.
- Pagina de produs construiește dinamic grupurile de variante din `options_json` (mărime, culoare sau orice grup viitor), solicită explicit fiecare alegere și menține butonul „Adaugă în coș” blocat până la rezolvarea unei combinații valide, aflate în stoc, și completarea tuturor opțiunilor obligatorii; fără JavaScript rămâne disponibil selectorul complet de variantă, marcat `required`.
- Starea persistentă „În coș” folosește acum un verde salvie mai rafinat, cu bifă albă într-un cerc translucid și contrast echilibrat. Același toggle, aceeași animație și aceeași a doua apăsare pentru scoaterea variantei din coș sunt disponibile atât pe cardurile catalogului, cât și pe pagina produsului.
- Pe telefon și tabletă, cantitatea și CTA-ul produsului sunt păstrate pe același rând; selectorul de cantitate ocupă aproximativ 35% din spațiul util, iar butonul folosește restul.
- Avantajele produsului sunt prezentate într-un panou premium cu trei carduri compacte, iconițe reliefate și fundal cald; pe mobil rămân pe un singur rând și folosesc tipografie adaptată spațiului disponibil.
- Panoul de avantaje folosește întreaga lățime a cardului de produs pe telefon și tabletă, fără să introducă scroll orizontal; conținutul intern rămâne aliniat și protejat de marginile ecranului.
- Pe telefon, panoul de avantaje închide acum cardul de produs până la marginea inferioară, fără banda goală produsă anterior de padding-ul de jos.
- Produsul „Cuburi moi «Poveste»” are 42 de variante reale rezultate din 7 mărimi și 6 culori. Prețul fiecărei combinații este suma dintre prețul mărimii și suplimentul culorii, cu SKU, stoc și preț comparativ proprii; migrarea actualizează instalațiile existente, iar seederul păstrează aceeași structură pentru instalările noi.
- Aceleași 42 de variante includ și grupul obligatoriu „Dimensiune”, cu valoarea „1 cm” și supliment de 10 Lei aplicat prețului final și prețului comparativ.
- În grila selectorilor de variante, ultimul grup ocupă automat ambele coloane când numărul grupurilor este impar; astfel „Dimensiune” are aceeași lățime totală ca rândul „Mărime” + „Culoare”, iar unicul grup al unui produs ocupă întotdeauna rândul complet.
- Pe desktop, galeria foto a produsului rămâne vizibilă integral sub antet la derulare în interiorul zonei principale, se redimensionează după înălțimea utilă a ecranului și se oprește înainte de descriere; cardul cu titlul și opțiunile nu mai este sticky. Pe tabletă și telefon, galeria revine la fluxul normal al paginii.
- Controalele verticale ale miniaturilor sunt contextuale: dispar complet când toate imaginile încap, iar săgețile sus/jos apar separat numai când există conținut disponibil în direcția respectivă.
- În lightbox-ul galeriei, interacțiunea tactilă permite zoom continuu între 100% și 400% prin pinch și repoziționarea imaginii prin tragere când este mărită; pe desktop, rotița mouse-ului controlează zoomul în jurul poziției cursorului. Translația este limitată automat pentru a nu pierde imaginea în afara ecranului. Gesturile și rotița nu schimbă fotografia, navigarea între imaginile mărite rămânând exclusiv în butoanele laterale.
- În lightbox-ul desktop, fotografia se dimensionează după înălțimea efectiv rămasă între antet și indicațiile de utilizare, păstrând raportul original; nu mai este decupată în partea de jos de dimensiunea calculată anterior direct din viewport.
- Pe telefon, imaginea mărită este centrată atât vertical, cât și orizontal în zona utilă dintre bara de control și indicațiile galeriei.
- Cardurile produselor reduse afișează automat în colțul stânga-sus al imaginii o bulină roz cu procentul calculat din prețul curent și prețul comparativ. Bulina, valoarea și simbolul `%` au animații discrete, iar produsele marcate simultan ca noutăți păstrează ambele insigne fără suprapunere; toate animațiile sunt oprite când utilizatorul preferă mișcare redusă.
- Prețurile și reducerile cardurilor din magazin, categorii, căutare, homepage, colecții și favorite provin din aceeași variantă implicită, autoritativă în baza de date, folosită la prima încărcare a paginii de produs. Suplimentele variantelor rămân aplicate după reducerea prețului de bază, fără a schimba suma reducerii de bază; filtrele și sortarea folosesc aceeași valoare afișată. Indiferent dacă reducerea comercială este definită ca sumă fixă sau procent, insigna o exprimă procentual din prețul final și o rotunjește normal la cel mai apropiat număr întreg.
- Pe telefon, cardurile reduse așază prețul vechi tăiat deasupra prețului actual, pe rânduri separate și fără despărțirea monedei, pentru a evita înghesuirea în coloanele înguste; layout-ul de tabletă și desktop rămâne neschimbat.
- Pagina produsului are acum un breakpoint dedicat tabletelor între 701 și 1100 px: galeria rămâne mare și centrată, cardul informațional trece dedesubt pe toată lățimea, iar grupurile de variante folosesc două coloane cu ultimul grup pe rând complet. Telefonul și desktopul își păstrează compozițiile proprii.
- Zona editorială a paginii de produs folosește acum o navigație sticky cu ancore pentru Descriere, Informații produs și Recenzii, inspirată de structura Maison Bébé și redesenată integral în identitatea La Măriuca. Descrierea lungă primește progresiv un control „Vezi mai mult”, informațiile sunt generate din SKU, categorie, grupurile dinamice de variante și câmpurile produsului, iar secțiunea de recenzii are o stare goală pregătită pentru date viitoare; fără JavaScript, toate informațiile rămân vizibile și ancorele funcționează nativ.
- Pe telefon, cardurile editoriale Descriere, Informații produs și Recenzii folosesc întreaga lățime a paginii, eliminând retragerea laterală a containerului fără a reduce spațiul interior al conținutului.
- Pe desktop, avantajele produsului au revenit la prezentarea aerisită cu iconițe și separator simplu; panoul compact, full-width și cele trei carduri rămân neschimbate pe tabletă și telefon.
- Verificarea vizuală reală a fost făcută la 390, 1024 și 1440 px. Au fost testate schimbarea tuturor imaginilor, lightbox-ul, zoom-ul la 125%, închiderea animată, toggle-ul coșului, calculul prețului pentru combinații de variante și navigația editorială a produsului. Lint-ul PHP/JavaScript, compilarea celor 33 de template-uri Twig, PHPStan și PHPUnit cu 41 de teste / 285 de aserțiuni trec.

## 2026-10-25 — Recenzii moderate și produse similare

- Pagina de produs afișează acum nota medie, distribuția pe stele și recenziile aprobate într-o compoziție editorială responsive. Recenzia demonstrativă locală pentru „Cuburi moi «Poveste»” rămâne identificată intern și nu este creată automat în producție, fără etichete DEMO în interfața publică.
- Formularul public de recenzie include CSRF, honeypot, limitare la 5 trimiteri pe oră, validare server-side, acord explicit pentru publicare și starea inițială `pending`; emailul nu este afișat public, iar achiziția verificată se calculează din comenzile livrate.
- Selectorul notei folosește cinci stele tactile care se colorează progresiv și au animații discrete de ridicare, „pop” și sclipire la selectare, cu focus vizibil din tastatură și dezactivarea animațiilor prin `prefers-reduced-motion`.
- Administrația are o pagină dedicată pentru aprobarea, respingerea sau readucerea în așteptare a recenziilor. Comanda explicită `review:demo [slug]` poate instala idempotent exemplul local pentru demonstrații.
- Pentru verificarea vizuală a listelor mai lungi, comanda explicită `review:demo-pack [slug]` completează idempotent exemplul inițial cu încă zece recenzii locale, identificate numai intern ca demo și fără a imita achiziții verificate.
- Recenziile aprobate sunt prezentate într-un carusel infinit și accesibil: o recenzie pe telefon, două pe tabletă și trei pe desktop, cu glisare tactilă, drag, butoane, contor, progres și autoderulare lentă care se oprește la interacțiune. Cardurile păstrează înălțimi coerente, cititoarele de ecran primesc poziția curentă, iar săgețile controalelor își păstrează contrastul vizibil la hover și focus.
- Eticheta „Povești de la comunitate” folosește aceeași inimioară dublă standard din navbar și cardurile de produs, redimensionată discret pentru bara caruselului.
- Nota medie pornește o singură dată cu un efect de numărare până la valoarea reală când intră în viewport. Stelele mediei redau și fracția exactă (de exemplu, la 4,8 a cincea stea este umplută 80%) în medalioane mari, fără umbre, cu muchii rotunjite și auriul cald inspirat din logo; distribuția pe note și stelele recenziilor folosesc aceeași paletă. Rândurile distribuției au bare aurii animate și feedback vizual compact la interacțiune, iar acțiunea „Scrie o recenzie” folosește o stea aurie animată și un CTA coerent cu aceeași identitate. `prefers-reduced-motion` afișează direct valoarea finală și elimină animațiile.
- Bara editorială Descriere / Informații produs / Recenzii nu mai păstrează padding deasupra taburilor atunci când se fixează sub navbar, eliminând banda goală percepută dintre cele două fără suprapuneri sau tăierea controalelor.
- Pe telefon, secțiunea editorială începe acum imediat după cardul produsului: marginea mobilă suplimentară a fost eliminată, iar headerul păstrează numai 6 px de respiro alb sub câmpul de căutare. Bara sticky suprapune cu 2 px îmbinarea și nu mai desenează a doua bordură superioară, evitând banda mare sau bordura dublă. Ajustările sunt limitate strict la pagina de produs.
- Textele lungi sunt scurtate predictibil numai la limita unui cuvânt, cu o limită dedicată ecranelor înguste, iar acțiunea „Vezi tot →” rămâne vizibilă după fragment inclusiv pe telefon. Aceasta deschide recenzia integrală într-un dialog responsive, cu autor, notă, dată și insigne, închidere prin X, Escape sau fundal și refacerea automată a fragmentului la redimensionare. Recenzia demonstrativă a Ioanei are 912 caractere pentru verificarea reală a acestui flux.
- Acțiunea „Scrie o recenzie” deschide formularul într-un dialog responsive cu fundal estompat, buton X, închidere prin Escape sau click pe fundal, revenirea focusului și animații simetrice. Fără JavaScript, același formular rămâne disponibil direct în fluxul paginii.
- Formularul de recenzie cere numărul comenzii în locul titlului opțional și verifică server-side că acea comandă aparține emailului introdus și conține produsul recenzat. Un număr inexistent păstrează dialogul și datele completate, marchează câmpul cu roșu și afișează sub el mesajul „Nu am putut găsi numărul comenzii.”; recenzia nu este salvată.
- Toate câmpurile obligatorii au validare vizuală coerentă înainte de trimitere, cu rezumat, contur și explicație roșie. Acordul pentru publicare este acum un switch obligatoriu, iar trimiterea asincronă păstrează formularul deschis la erori și continuă să aibă fallback HTML funcțional fără JavaScript.
- Produsele fără recenzii nu mai afișează scorul gol, stelele inactive și distribuția cu zerouri. În locul panoului apare numai o stare editorială compactă pentru prima impresie, cu acces direct la formularul „Scrie prima recenzie”.
- După recenzii apare un carusel cu până la 15 produse recomandate dinamic după categoria principală, termeni comuni, context, apropierea de preț și disponibilitate. Produsul curent este exclus, iar selecția funcționează automat și pentru produsele viitoare.
- Caruselul este adaptat pentru telefon, tabletă și desktop, acceptă gesturi și drag, are navigare cu butoane, contor, progres și autoderulare care se reia după interacțiune; cardurile păstrează toate acțiunile existente de produs.
- Verificarea vizuală locală a confirmat caruselul cu 11 recenzii, autoderularea, deschiderea și închiderea animată a celor două dialoguri, afișarea acțiunii „Vezi tot →” pentru recenzia de 912 caractere, noul formular cu număr de comandă și starea compactă a produselor fără recenzii. Pachetul complet păstrează media 4,8 și nu creează duplicate după rulări consecutive. Verificări locale trecute: lint PHP/JavaScript, compilarea tuturor celor 35 de template-uri Twig, PHPStan fără erori și PHPUnit cu 48 de teste / 339 de aserțiuni.

## 2026-10-25 — Destinația Noutăți

- Linkurile „Noutăți” din meniul principal, footer și secțiunea dedicată de pe homepage trimit uniform la Magazin cu `sortare=new`, astfel încât selectorul să fie deja setat pe „Cele mai noi”; vechea destinație din pagina de căutare nu mai este folosită.
- Magazinul acceptă și sortarea `discount`, disponibilă în selector ca „Reduceri: mari–mici”. Procentul este calculat server-side din prețul curent și prețul comparativ al variantei implicite autoritative; produsele reduse sunt ordonate de la cel mai mare procent la cel mai mic, iar produsele fără reducere continuă lista în ordinea noutății. Linkurile „Reduceri” din meniu și footer deschid direct această sortare în Magazin.

## 2026-10-25 — Pagina editorială „Despre noi”

- Ruta `/pagina/despre-noi/` folosește acum un template editorial dedicat, fără să schimbe randarea generică a paginilor Contact, Livrare, Legal sau a celorlalte pagini administrabile.
- Textul integral al poveștii La Măriuca Boutique este structurat într-un hero cald, povestea numelui Maria, secțiunea despre proiectul de familie, parcursul de la primele zile la aventurile copilăriei și un final cu acces direct la Magazin.
- Compoziția reutilizează exclusiv imaginile și simbolurile deja aprobate ale boutique-ului. Imaginile editoriale au texte alternative descriptive și nu sunt prezentate ca fotografii ale Mariei.
- Cartușul „Maria” a fost redesenat ca o piesă editorială stratificată, cu monograma completă, inimioara standard a brandului, ornament auriu și eticheta „Din familia noastră”. Animația a fost mutată de pe medalion pe simbolul interior, astfel încât cercul inimioarei rămâne centrat geometric în orice cadru al animației.
- Fotografia reală a Mariei a fost prelucrată într-un portret editorial 4:5: fața, identitatea, coafura, poziția și costumul popular sunt păstrate ca repere, iar fundalul aglomerat este înlocuit cu un studio crem–roz pudrat. Varianta WebP optimizată înlocuiește fotografia generică din secțiunea despre familie.
- Migrarea `011_about_page_story.sql` actualizează titlul, conținutul, descrierea SEO și starea paginii existente atât pentru SQLite, cât și pentru MySQL; seederul păstrează aceeași poveste și aceleași metadate pentru instalările noi.
- Verificarea vizuală reală a fost făcută la 390, 800 și 1440 px: pagina nu produce overflow orizontal, hero-ul, cartușul Maria și galeriile se reașază coerent, iar imaginile vizibile se încarcă fără erori de consolă.
- Verificări locale trecute: lint PHP, compilarea tuturor celor 36 de template-uri Twig, PHPStan fără erori și PHPUnit cu 50 de teste / 356 de aserțiuni.

## 2026-10-25 — Pagina Contact și conversația WhatsApp

- Ruta `/pagina/contact/` folosește acum un template editorial dedicat, responsive, cu hero în trei rânduri, simulare de conversație, monograma circulară LM și formular complet în identitatea La Măriuca.
- Numărul public de contact este configurat central ca `0763 715 974` (`40763715974` pentru WhatsApp). Butonul WhatsApp global de pe toate paginile, formularul și acțiunile rapide folosesc aceeași sursă de configurare.
- Hero-ul oferă două acțiuni rapide: apel direct și deschiderea conversației WhatsApp fără text precompletat. Sub ele, o capsulă informativă neinteractivă afișează adresa `contact@la-mariuca.ro`. Formularul separat construiește un mesaj ordonat după nume, subiect, datele opționale de contact și mesajul vizitatorului.
- Formularul este trimis local prin POST cu CSRF, validează server-side toate câmpurile, păstrează valorile sigure când există erori și cere acord explicit înainte de a deschide WhatsApp. Emailul și telefonul sunt opționale; când sunt completate sunt validate și incluse în mesaj.
- WhatsApp nu trimite automat nimic: vizitatorul ajunge în conversație cu textul pregătit și îl poate verifica înainte de trimitere. Site-ul nu persistă datele formularului și nu le expune în URL-ul paginii Contact.
- Migrarea `012_contact_whatsapp_page.sql` actualizează conținutul și metadatele SEO atât pentru SQLite, cât și pentru MySQL; seederul păstrează aceeași configurație pentru instalările noi.
- Verificări locale trecute după finisarea interfeței: lint PHP, compilarea tuturor celor 37 de template-uri Twig, PHPStan fără erori și PHPUnit cu 54 de teste / 395 de aserțiuni.
- Footerul afișează în coloana „Informații” telefonul și adresa de email configurate central; ambele sunt acționabile (`tel:` / `mailto:`) și apar uniform pe toate paginile storefrontului.

## 2026-10-26 — Navigare magazin și footer pe tabletă

- Acțiunea „Descoperă colecțiile” din panourile goale pentru coș și favorite deschide acum direct `/magazin/`, inclusiv după actualizarea dinamică a panourilor prin JavaScript și în instalările cu `APP_BASE_PATH`.
- Footerul folosește până la 1024 px aceeași structură compactă, pe un singur flux și cu secțiuni acordeon, ca pe telefon; configurația desktop cu panouri pe coloane începe de la 1025 px.

## 2026-10-26 — Pagina Coș

- Pagina `/cos/` a fost reconstruită în stilul editorial La Măriuca pentru telefon, tabletă și desktop. Starea goală are acum o compoziție caldă, un îndemn clar către Magazin și scurtături către Noutăți, Cadouri și Botez.
- Starea cu produse folosește carduri responsive cu imagine, variantă, SKU, personalizare, cantitate, eliminare și totaluri clare; rezumatul comenzii rămâne ușor accesibil și păstrează formularele POST cu CSRF existente.
- Serviciul coșului livrează explicit către view imaginea variantei sau imaginea principală a produsului și textul alternativ aferent, fără interogări din Twig.
- Starea goală păstrează o singură recomandare către Noutăți; cardurile redundante pentru Cadouri și Botez au fost eliminate. Toate inimile decorative folosesc acum iconița standard dublă din navbar, inclusiv în antet, capsula „Alese cu grijă” și compoziția ilustrată.
- Verificarea vizuală locală a fost făcută la 390, 960 și 1440 px, fără overflow orizontal și fără erori în consola browserului. Verificări locale trecute: lint PHP, compilarea tuturor celor 37 de template-uri Twig, PHPStan fără erori și PHPUnit cu 56 de teste / 414 aserțiuni.
- Cantitatea produselor din coș folosește acum un selector compact cu minus și plus, aliniat vertical cu eticheta „Cantitate”. Modificarea se aplică asincron, actualizează linia, numărul produselor, subtotalul, reducerea și totalul fără reîncărcarea paginii și păstrează formularul POST drept fallback fără JavaScript.
- Acțiunea secundară din antetul produselor este acum o capsulă „Magazin”, cu iconiță, săgeată și stare de hover/focus coerentă; denumirea lungă „Continuă cumpărăturile” nu mai produce ruperi inestetice pe ecrane înguste.
- Variantele tehnice fără opțiuni reale nu mai afișează în coș fallbackul „Mărime standard”. Rezumatul variantei rămâne vizibil numai când clientul a ales efectiv opțiuni precum mărime, culoare sau dimensiune.
- În pagina produsului, fiecare grup de variante selectează inițial prima valoare compatibilă disponibilă, inclusiv grupurile cu o singură valoare; clientul poate schimba ulterior selecția, iar filtrarea combinațiilor imposibile și prețul/stocul autoritativ rămân active.
- Caruselul de produse similare afișează trei carduri pe tabletă și aplică butoanelor aceeași configurație compactă ca pe telefon: text și iconiță centrate, fără cercul lateral care comprima eticheta.
- Verificarea vizuală locală a confirmat la 960 px cele trei recomandări și butoanele compacte, preselectarea `0–6 luni` / `Roșu` / `1 cm`, selectorul de cantitate și eliminarea fallbackului din coș. Lint PHP/JavaScript, compilarea celor 37 de template-uri Twig, PHPStan și PHPUnit cu 58 de teste / 431 de aserțiuni trec.

## 2026-10-26 — Pagina de autentificare

- Ruta `/cont/autentificare/` folosește acum o experiență editorială dedicată La Măriuca: povestea contului și avantajele sale sunt separate vizual de formularul de acces, într-un singur card cald, complet rotunjit și coerent cu paleta boutique-ului.
- Formularul păstrează neschimbate ruta POST, tokenul CSRF, numele câmpurilor și regulile de autentificare existente. Câmpurile au iconițe, stări clare de focus, autocomplete corect și un control accesibil pentru afișarea sau ascunderea parolei; fără JavaScript, autentificarea continuă să funcționeze normal.
- Acțiunea pentru înregistrare este prezentată separat, fără să concureze cu butonul principal, iar inimioarele folosesc iconița standard existentă a brandului.
- Pagina `/cont/inregistrare/` folosește aceeași identitate responsive, cu prenume, nume, email, telefon și parolă de minimum 8 caractere. Telefonul este obligatoriu în HTML și în validarea server-side, acceptă formate românești uzuale și este salvat normalizat internațional; migrarea `014_user_phone.sql` adaugă câmpul fără a invalida conturile deja existente.
- Ambele pagini oferă acțiunea „Continuă cu Google”, cu simbolul color oficial și chevron SVG. Fluxul server-side este pregătit cu OAuth 2.0/OpenID Connect, `state`, nonce și PKCE, validarea audienței și identități separate în migrarea `013_google_oauth_identities.sql`; conturile de administrare nu pot fi asociate automat. Fiecare mediu este activat explicit prin credențialele Google și `GOOGLE_OAUTH_ENABLED=true`.
- Configurația OAuth poate citi credențialele și din `GOOGLE_CREDENTIALS_FILE`, astfel încât JSON-ul descărcat din Google Cloud să rămână în afara repository-ului și a webrootului. Configurația locală a fost activată prin această referință privată; răspunsul de pornire a fost verificat fără expunerea secretului și conține redirectul local exact, `state`, nonce și challenge PKCE `S256`.
- Pentru deployment, aplicația detectează în producție fișierul standard privat `storage/private/google-oauth-client.json` și construiește redirectul Google din `APP_URL`; cu `APP_URL=https://la-mariuca.ro` callback-ul devine automat cel de producție. Fișierul rămâne ignorat de Git și trebuie transferat separat pe hosting, în afara webrootului.
- `docs/GOOGLE_OAUTH_CHECKLIST.md` păstrează pașii exacți pentru Google Cloud, transferul secretului, `.env`, migrări și smoke testul live; `AGENTS.md` cere explicit parcurgerea lui la orice deployment și interzice declararea integrării ca verificată înainte de testul real HTTPS.
- Layout-ul are compoziții distincte pentru telefon, tabletă și desktop. Pe telefon, panoul editorial este eliminat complet și formularul începe direct cu acțiunile contului; pe tabletă și desktop compoziția în două zone rămâne. Verificarea vizuală locală a fost făcută la 390 px, 820 px și 1200 px; au fost confirmate rearanjarea responsive, lipsa overflow-ului și schimbarea accesibilă „Arată parola” / „Ascunde parola”.
- Verificări locale trecute: lint PHP/JavaScript, compilarea tuturor celor 37 de template-uri Twig, PHPStan fără erori și PHPUnit cu 63 de teste / 470 de aserțiuni.
- Fluxul Google verifică acum explicit existența extensiei PHP `curl` înainte de redirecționare și afișează cauza sigură a erorilor de configurare/autorizare în locul mesajului generic. Checklistul de deployment precizează că serverul local trebuie pornit prin `bin/php-local.ps1`, care încarcă `curl` împreună cu driverele bazei de date.
- Scriptul PHP local detectează acum automat bundle-ul CA disponibil prin `SSL_CERT_FILE`, distribuția PHP sau instalarea Git și îl configurează pentru cURL/OpenSSL. Astfel, schimbul OAuth validează certificatul Google în loc să eșueze cu `cURL error 60`; verificarea HTTPS către endpointul Google a răspuns fără eroare de certificat.

## 2026-10-26 — Centrul contului clientului

- Pagina `/cont/` a fost reconstruită ca un centru complet și responsive pentru client, cu rezumat personal, statistici, navigare rapidă, comenzi, detalii personale, adrese salvate și informații despre accesul în cont.
- Istoricul comenzilor afișează numărul, data, starea comenzii, plata, totalul, articolele și adresa de livrare. Comenzile de vizitator cu același email verificat sunt asociate automat contului, fără a modifica comenzile altor clienți.
- Clientul își poate actualiza numele și telefonul, iar emailul verificat rămâne protejat până la existența unui flux dedicat de reverificare. Conturile conectate prin Google sunt semnalate clar în zona de securitate.
- Agenda de livrare acceptă maximum zece adrese, fiecare cu alias propriu — de exemplu „Acasă”, „Birou” sau „Bunici” — destinatar, telefon, stradă, localitate, județ, cod poștal și indicații pentru curier. Adresele pot fi adăugate, editate, șterse sau declarate implicite; proprietatea este verificată server-side la fiecare operație.
- Checkout-ul recunoaște clientul autentificat, precompletează datele sale și oferă selector pentru adresele salvate, cu adresa implicită aleasă automat. Formularul continuă să funcționeze complet și fără JavaScript.
- Toate operațiile de profil și adresă folosesc POST cu CSRF, validare server-side, interogări pregătite și tranzacții pentru schimbarea adresei implicite. Interfața are confirmare la ștergere, editare accesibilă și compoziții distincte pentru telefon, tabletă și desktop.
- Verificarea vizuală locală a confirmat centrul contului și precompletarea checkout-ului pe telefon și desktop. Verificări locale trecute: lint PHP/JavaScript, compilarea tuturor celor 38 de template-uri Twig, PHPStan fără erori și PHPUnit cu 66 de teste / 492 de aserțiuni.
- Zona „Adresele mele” folosește acum o grilă compactă în care adresele și acțiunea de adăugare au aceeași greutate vizuală. Cardurile diferențiază clar aliasul, destinatarul, strada, localitatea, telefonul și adresa implicită, iar acțiunile de editare, implicită și ștergere au iconițe și feedback modern la interacțiune. Formularul nou se extinde pe toată lățimea numai când este deschis, eliminând spațiul gol nefolosit de pe desktop și păstrând fluxul pe o singură coloană pe telefon.
- Hero-ul contului pe telefon are acum o compoziție dedicată: avatarul cu inițialele clientului devine reper vizual, titlul și numele au proporții mai echilibrate, emailul și verificarea stau pe același rând, iar statisticile sunt integrate într-un panou compact la baza cardului. Ornamentele și fundalul au fost recalibrate pentru ecrane înguste fără a modifica varianta desktop.
- Editarea unei adrese se deschide pe telefon într-un modal real, independent de card: fundalul este estompat, pagina nu mai derulează în spate, antetul identifică aliasul editat, iar formularul are o singură ramă și scroll propriu. Starea tactilă de hover nu mai poate transforma cardul în reper pentru elementul fix, eliminând încadrarea multiplă și tăierea formularului.
- Câmpurile județ și localitate din agenda contului și checkout folosesc selectoare dependente cu căutare, în stilul componentelor Bootstrap, dar desenate în identitatea La Măriuca. După încărcarea nomenclatorului nu acceptă text liber: căutarea doar filtrează lista, iar valoarea poate proveni exclusiv dintr-o opțiune disponibilă; aceeași combinație este validată din nou server-side.
- Nomenclatorul local este generat din registrul oficial INS SIRUTA S1 2026 și conține 42 de unități de nivel județean și 13.755 de localități. Municipiul București este opțiunea de nivel județean, iar „București — Sector 1” până la „București — Sector 6” sunt opțiuni în selectorul dependent „Localitate / sector”, conform ierarhiei oficiale. Căutarea tolerează scrierea fără diacritice, însă datele sunt salvate în forma oficială.
- Selectoarele de județ și localitate se închid acum la click pe fundalul mobil estompat, pe orice zonă exterioară panoului sau prin tasta Escape; interacțiunile din căutare și lista de opțiuni păstrează panoul deschis până la alegere.
- Pe desktop, panoul selectorului măsoară spațiul disponibil și alege automat deschiderea în sus sau în jos, fără să intre sub headerul sticky. Editorul de adresă deschis primește propriul strat vizual deasupra secțiunilor următoare, astfel încât formularul și selectorul nu mai sunt acoperite de cardul „Securitate”.
- Zona „Securitate” a contului folosește carduri responsive distincte pentru email, metoda de acces și sesiunea curentă, cu stări vizuale explicite și un panou de confidențialitate. Conturile Google afișează simbolul oficial multicolor în locul literei generice „G”, iar conturile clasice păstrează o iconiță dedicată parolei.

## 2026-10-26 — Navigarea panoului de administrare

- Bara laterală a administrației a fost reconstruită ca un panou boutique compact, cu sigla La Măriuca, etichete editoriale, stare activă clară și profilul Paulei integrat discret. Până la introducerea modulelor următoare, navigarea păstrează exclusiv Dashboardul.
- Pe desktop, bara poate fi restrânsă într-o coloană compactă cu iconița Dashboard; controlul de redeschidere rămâne în capul barei sub forma unui chevron `>`, fără să apară un meniu hamburger în headerul conținutului. Preferința este memorată local, iar conținutul și topbarul se reașază prin aceeași tranziție lină. Pe telefon, meniul devine un panou flotant centrat, cu fundal estompat și închidere din X, din fundal sau cu Escape.
- Hero-ul Dashboardului are acum o compoziție mai compactă pe desktop și telefon: titlu recalibrat, ornament redus, spațiere verticală mai mică și metadate discrete, fără a schimba datele afișate.
- Fâșia dintre marginea superioară a ecranului și topbarul fix folosește un blur complet, astfel încât textele care trec dedesubt la scroll nu mai rămân lizibile deasupra navbarului; sub navbar nu se aplică blur suplimentar. Ceasul din header afișează acum ore, minute și secunde și se actualizează din JavaScript în fiecare secundă, în fusul `Europe/Bucharest`.
- Avertizarea amplă pentru configurarea verificării în doi pași a fost eliminată din Dashboard; funcționalitatea TOTP rămâne disponibilă în backend, dar nu mai întrerupe fluxul vizual al paginii principale.
- Verificarea vizuală locală a confirmat stările extinsă/restrânsă la 1526 px și deschiderea/închiderea panoului la 390 px. JavaScriptul trece verificarea de sintaxă, toate cele 40 de template-uri Twig se compilează, PHPStan nu raportează erori, iar PHPUnit trece cu 73 de teste și 527 de aserțiuni.

## 2026-10-26 — Dashboard administrativ analitic

- Dashboardul folosește acum structura completă „Prezentare generală”, desenată integral în identitatea La Măriuca: antet editorial, selector de perioadă, indicatori, grafic, notificări și tabelul comenzilor recente. Reperul vizual extern a fost folosit doar pentru ierarhia informației, nu pentru copierea stilului.
- Perioadele `7 zile`, `Săptămâna curentă`, `O lună`, `3 luni`, `6 luni`, `Un an`, `Tot timpul` și intervalul personalizat schimbă server-side valorile și graficul. Limitele calendaristice sunt calculate în `Europe/Bucharest`, iar persistența rămâne UTC.
- Vânzările reprezintă încasări confirmate nete după rambursări; comenzile anulate și returnate nu intră în comenzile valide. Clienții noi sunt conturile create în perioada aleasă, iar stocul redus numără variantele active epuizate sau cu maximum trei unități disponibile.
- Graficul se adaptează automat la zile, săptămâni sau luni, păstrează scroll tactil pentru perioade lungi și oferă controale explicite de derulare. Barele, numerele și cardurile au animații discrete, stări de hover/focus și respectă preferința de reducere a mișcării.
- Notificările reunesc comenzile și plățile recente, iar tabelul comenzilor se transformă pe telefon în carduri compacte cu etichete clare. Toate zonele au stări goale reale și nu inventează valori atunci când baza de date nu conține activitate.
- Verificarea finală a inclus desktop, tabletă și telefon în browser, inclusiv animațiile și consola fără erori. Lint-ul PHP, compilarea celor 40 de template-uri Twig, PHPStan, verificarea de sintaxă JavaScript și suita PHPUnit cu 73 de teste și 532 de aserțiuni trec integral.
- Pe telefon, antetul „Prezentare generală” este acum un card editorial compact, iar comenzile recente au ierarhie mobilă dedicată: comandă și acțiune, client, apoi status, dată și total. Toate săgețile de acțiune din Dashboard folosesc SVG-uri centrate geometric în locul caracterelor tipografice `>`.
- Tipografia funcțională a Dashboardului mobil a fost mărită separat de titluri: filtrele, etichetele KPI, descrierile, notificările, datele și detaliile comenzilor rămân acum lizibile fără a pierde structura compactă.
- Explicațiile redundante de sub cei patru indicatori principali au fost eliminate, lăsând eticheta și valoarea să comunice informația direct; cardurile mobile au fost compactate corespunzător.
- Meniul administrativ mobil nu mai este un modal centrat: funcționează ca drawer lateral autentic, ancorat de marginea stângă, cu intrare și ieșire lină în direcția butonului hamburger.
- Headerul editorial cu salutul dinamic al Paulei a fost readus deasupra raportului analitic. Salutul folosește ora Europe/Bucharest și alternează între „Bună dimineața”, „Bună ziua” și „Bună seara”, iar compoziția rămâne compactă pe toate dimensiunile.
- Tipografia funcțională a Dashboardului a fost mărită și pe desktop: filtrele de perioadă, data și ora, etichetele KPI, notificările, axele graficului și tabelul comenzilor sunt lizibile fără să schimbe proporțiile titlurilor editoriale.
- Dashboardul și autentificarea administrativă declară aceleași faviconuri de 32 px, 192 px și Apple Touch Icon ca storefrontul, astfel încât identitatea din tab și de pe ecranul principal rămâne unitară în toate zonele site-ului.
- Numărul și săgeata fiecărei comenzi recente din Dashboard deschid acum pagina individuală a comenzii, nu lista generală. Ruta administrativă protejată afișează produsele, variantele, datele clientului și livrării, stările, plățile și rezumatul financiar într-un layout responsive; notificările pentru comandă/plată trimit la aceeași destinație.
- Pe telefon și tabletă, formularul perioadei personalizate rămâne ancorat în viewport chiar dacă Dashboardul este deja derulat; animația secțiunii nu mai transformă reperul elementului `fixed` și nu mai împinge formularul în afara ecranului. După aplicarea intervalului, filtrul rămâne evidențiat ca activ, însă panoul se închide automat la reafișarea raportului.
- Navigarea administrativă include acum tabul „Comenzi”, cu stare activă atât în registru, cât și în pagina individuală. Registrul folosește paginare server-side și afișează cu tipografie lizibilă numărul comenzii, clientul cu email și telefon, metoda și starea plății, statusul comenzii, data/ora în Europe/Bucharest și totalul; tabelul desktop se transformă în carduri operaționale pe telefon și tabletă. Căutarea server-side acoperă datele clientului și livrării, AWB-ul, produsele/SKU-urile, totalul și stările, înțelege etichete românești precum „ramburs”, „plătită” sau „expediată”, iar filtrul de status și paginarea păstrează toate criteriile active.
- Comanda locală `php bin/console.php seed:orders-demo [număr]` generează explicit comenzi demonstrative cu clienți, produse, metode de plată, statusuri și AWB-uri variate, fără a intra în seed-ul implicit al testelor. Pentru verificarea registrului au fost încărcate 25 de comenzi demo în baza locală; acestea nu fac parte din migrații și nu sunt date de producție.

## 2026-10-26 — Centrul de notificări administrative

- Navigarea administrativă include acum tabul „Notificări”, cu un contor real al evenimentelor necitite pentru administratorul autentificat. Centrul reunește comenzile noi, plățile confirmate/eșuate/rambursate, stocul redus sau epuizat și recenziile care așteaptă moderare, fără a duplica datele operaționale într-un jurnal paralel.
- Inboxul are filtre cu contoare, mod „doar necitite”, paginare server-side, marcarea individuală sau în masă ca citit și legături directe către comanda ori produsul relevant. Starea de citire este persistentă și separată pentru fiecare administrator.
- Fiecare notificare poate fi eliminată individual din inbox, iar filtrul activ oferă o ștergere în masă cu confirmare în doi pași. Eliminarea este doar o preferință per administrator: comenzile, plățile, stocul și recenziile sursă nu sunt șterse. Interfața folosește o ieșire animată cu glisare, estompare și repoziționare FLIP a cardurilor rămase; formularul POST clasic rămâne fallback fără JavaScript.
- Preferințele pentru cele patru tipuri de notificări sunt salvate server-side prin formulare POST protejate CSRF. Alertele browserului folosesc acum Web Push real, cu Service Worker, abonamente separate pe dispozitiv și VAPID: pot ajunge în fundal conform capabilităților browserului și sistemului de operare, fără Node.js ori furnizor extern.
- Interfața păstrează identitatea La Măriuca pe desktop, tabletă și telefon: hero editorial, carduri cu severitate vizuală, dată/valoare lizibilă, comutatoare accesibile, stări goale și animații care respectă preferința de reducere a mișcării.
- Migrarea `016_admin_notifications.sql` adaugă tabelele de citire și preferințe atât pentru SQLite, cât și pentru MySQL, fără a modifica migrările deja existente.
- Migrarea `017_admin_web_push.sql` adaugă abonamentele, evenimentele și coada de livrare cu retry pentru SQLite și MySQL. Comanda nouă `push:process` procesează reîncercările din cron, iar comenzile, plățile Stripe confirmate, pragurile de stoc și recenziile noi încearcă livrarea imediat după închiderea tranzacției.
- Migrarea `018_admin_notification_dismissals.sql` păstrează separat notificările eliminate de fiecare administrator, în variante SQLite și MySQL. Dispozitivele Web Push afișează acum iconița corectă pentru calculator sau telefon și o etichetă derivată din informațiile permise de browser; modelul Android apare când Client Hints îl expune, în timp ce numele calculatorului și modelul exact al iPhone-ului rămân intenționat inaccesibile paginilor web.
- Cheile VAPID sunt generate local în `storage/private/web-push-vapid.json`, nu sunt afișate și nu intră în Git. `docs/WEB_PUSH_DEPLOYMENT.md` păstrează pașii exacți pentru Apache/shared hosting, HTTPS, cron, instalarea iOS și verificarea pe domeniul real.
- Verificarea vizuală locală a confirmat inboxul, noile acțiuni de ștergere, iconițele dispozitivelor și noua acțiune de salvare a preferințelor. Lint-ul PHP, verificarea de sintaxă JavaScript, compilarea celor 42 de template-uri Twig, PHPStan și PHPUnit cu 76 de teste / 586 de aserțiuni trec integral prin runtime-ul PHP local al proiectului.

## 2026-10-26 — Registrul produselor din administrare

- Navigarea administrativă are acum grupul distinct „Produse și clienți” și tabul „Produse”, disponibil identic în bara extinsă, varianta compactă și drawerul mobil. Starea activă se păstrează în registru, la adăugarea unui produs și în editare.
- Registrul produselor folosește paginare și căutare server-side după denumire, slug, SKU, categorie ori variantă. Tabelul afișează produsul și imaginea sa, SKU-ul implicit, categoria principală, prețul, stocul online disponibil, stocul fizic contabil, rezervările, statusul și acțiunile permise.
- „Stoc online” este calculat autoritativ din totalul fizic minus rezervările variantelor active. „Stoc efectiv (Conta)” citește soldurile contabile documentare ale produsului (produs-mamă și/sau combinații), inclusiv valori negative; pentru datele demo vechi fără sold contabil persistat rămâne fallback-ul la stocul fizic. Valorile nu sunt duplicate sau calculate în Twig, iar soldurile negative au stare vizuală distinctă în registrul de produse.
- Pe telefon și tabletă, tabelul se transformă în carduri operaționale cu aceeași informație, fără scroll orizontal și fără pierderea acțiunilor de editare ori ștergere.
- Acțiunea de ștergere înlocuiește vizualizarea rapidă și folosește confirmare într-un dialog responsive, POST cu CSRF și feedback prin mesaj administrativ. Ștergerea este sigură și reversibilă la nivel de date: produsul este retras din magazin, scos din catalogul activ, variantele sunt dezactivate și coșurile active sunt curățate, în timp ce produsul, inventarul și istoricul comenzilor rămân păstrate în baza de date.
- Numărul de variante nu mai este afișat sub denumire, iar prețul are o proporție mai discretă pentru a păstra ierarhia vizuală a rândului.
- Verificarea vizuală locală a confirmat registrul desktop și dialogul de ștergere fără a modifica produse reale. Lint-ul PHP, sintaxa JavaScript, compilarea celor 42 de template-uri Twig, PHPStan și PHPUnit cu 77 de teste / 611 aserțiuni trec integral prin runtime-ul PHP local al proiectului.
- Barele graficului de vânzări folosesc acum o scară Y numerică strictă, raportată la valoarea maximă a perioadei, nu o înălțime procentuală dependentă de geometria CSS Grid. Zero lei produce o scară `0` și doar un marcaj roz discret de 4 px la bază, valoarea maximă produce `1` (100%), iar toate celelalte valori sunt proporționale între aceste limite pe desktop, tabletă și telefon. Acțiunile de editare/ștergere din registrul produselor au dimensiuni și stări focus coerente, fără conturul nativ negru al browserului; pe desktop, celula rămâne în fluxul tabelului pentru ca separatorul fiecărui rând să fie continuu.
- Punctele pur decorative din dreapta taburilor barei laterale au fost eliminate; numai contorul real de notificări necitite mai ocupă acea zonă atunci când există informație utilă.
- Adăugarea și editarea produselor se deschid acum direct din registru într-un atelier modal comun, fără navigare pe o pagină separată. Formularul este împărțit în identitate, date comerciale, poveste editorială, detalii utile și SEO, păstrează antetul și acțiunile accesibile în timpul scrollului și devine editor aproape full-screen pe telefon. Salvarea folosește POST cu CSRF și răspuns JSON, afișează erorile în modal și reîmprospătează catalogul numai după succes; linkurile și pagina completă rămân fallback funcțional fără JavaScript.
- Antetul atelierului este acum sticky în interiorul suprafeței derulabile, cu fundal opac estompat; titlul, contextul produsului și închiderea rămân vizibile fără să acopere câmpurile pe desktop, tabletă sau telefon.
- Numele produsului este verificat asincron în timp ce administratorul tastează, cu stare verde pentru titlu disponibil și stare roșie pentru duplicat. Editarea exclude produsul curent, butonul de salvare rămâne blocat cât timp verificarea este în curs sau titlul este duplicat, iar aceeași regulă este impusă obligatoriu în serviciul server-side pentru cererile clasice și AJAX.
- SKU-ul implicit este propus automat în format secvențial `LM-AA-NNN`, dar rămâne complet editabil înainte de salvare. Disponibilitatea sa este verificată live fără diferență între litere mari și mici, cu mesaje verde/roșu și blocarea salvării la duplicat; la editare este exclusă varianta curentă, iar serverul generează în continuare un SKU sigur dacă formularul clasic este trimis cu valoarea goală.
- Câmpul „Slug (numele linkului)” se completează automat din titlu la adăugare, rămâne editabil manual și își verifică live disponibilitatea. La editare nu schimbă automat linkul public existent, produsul curent este exclus corect din verificare, iar un slug duplicat este respins și server-side. Atât slug-ul, cât și SKU-ul sunt generate automat la salvare atunci când câmpurile lor sunt trimise explicit goale.
- Câmpul „Categorii” permite acum asocierea reală a produsului cu mai multe categorii și subcategorii. Selectorul are căutare fără diacritice, checkboxuri, rezumatul selecției și un arbore vizual cu ramificații continue pentru orice adâncime, fără etichete tehnice de nivel; pe telefon devine un bottom-sheet cu fundal estompat, acțiuni compacte și control propriu pentru golirea căutării. Serverul validează toate categoriile, persistă legăturile în `product_categories` și păstrează compatibilitatea cu categoria principală internă.
- Editorul modal de produse păstrează headerul și bara de salvare complet fixe; scrollul este izolat în conținutul formularului pe desktop, tabletă și telefon.
- Indicatorul din footerul editorului este conectat la starea reală a formularului: „Up to date” folosește o bulină verde animată, iar orice modificare nesalvată trece imediat la „Unsaved” cu bulină portocalie animată; textul rămâne neutru și mai lizibil.
- Acțiunea de închidere din selectorul de categorii nu mai afișează textul „Gata”; este acum un buton circular compact și accesibil, cu săgeata încadrată geometric printr-o grilă proprie. Stilul dedicat nu mai poate fi suprascris de regula generală a butoanelor din admin, astfel cercul interior rămâne perfect centrat pe desktop și telefon.
- Editorul de produs protejează modificările nesalvate la „Renunță”, X, click pe fundalul din afara modalului și Escape. Starea „Unsaved” deschide o confirmare responsive în stilul La Măriuca, cu opțiuni clare pentru continuarea editării sau ieșirea fără salvare; formularele nemodificate se închid direct.
- La adăugarea unui produs nou, starea implicită este acum „Publicat”, iar câmpul de preț pornește gol cu placeholderul corect „0,00”; editarea păstrează starea și prețul existente.
- Verificarea în browser a confirmat deschiderea, închiderea și încărcarea datelor pentru ambele moduri ale atelierului, stările disponibil/duplicat ale titlului și selectorul multi-categorie pe desktop și telefon, fără a modifica produse reale. Lint-ul PHP, sintaxa JavaScript, compilarea celor 45 de template-uri Twig, PHPStan și PHPUnit cu 77 de teste / 681 de aserțiuni trec integral prin runtime-ul PHP local al proiectului; testele de integrare acoperă generarea automată din câmpuri goale, validarea fără diferență de majuscule, excluderea valorii proprii la editare pentru titlu, SKU și slug, precum și persistența mai multor categorii.

## 2026-10-31 — CRM furnizori și metadate comerciale ale produsului

- Navigarea administrativă include acum tabul „Furnizori” în grupul „Produse și clienți”. Registrul funcționează ca un CRM compact cu alias, firmă, CUI, persoană de contact, telefon, email, website, adresă, notițe, stare, căutare server-side și paginare responsive.
- Adăugarea și editarea furnizorilor se deschid din registru în același modal responsive, cu header sticky, conținut derulabil propriu, salvare asincronă, validare server-side și feedback vizual. Pagina completă rămâne exclusiv fallback funcțional fără JavaScript, conform cerinței de accesibilitate progresivă.
- Ștergerea cere confirmare, păstrează produsele și elimină doar asocierea lor cu furnizorul, în interiorul unei tranzacții. Aliasul este obligatoriu și unic fără diferență între majuscule și minuscule; toate celelalte date ale furnizorului sunt opționale.
- Editorul de produs are sub SKU și slug selectorul opțional „Furnizor”, afișat ca `ALIAS — NUME FIRMĂ`, plus câmpul opțional „Brand / Producător”. Furnizorul este validat server-side, iar brandul completat este inclus în datele structurate `Product` ca `Brand`, pentru consumatori precum Google Merchant.
- Migrarea `019_suppliers.sql` există separat pentru SQLite și MySQL și adaugă tabela furnizorilor plus relația opțională `products.supplier_id` cu `ON DELETE SET NULL`. Migrarea SQLite și fluxul complet au fost verificate local; migrarea MySQL nu este declarată verificată pe un server real până la rularea din mediul de producție/staging.
- Verificarea directă în browser a confirmat că „Furnizor nou” se deschide peste registru ca modal. Lint-ul PHP, compilarea tuturor celor 49 de template-uri Twig și PHPStan trec fără erori, iar suita PHPUnit trece cu 78 de teste și 710 aserțiuni.
- Comutatorul „Vizibil pentru Google” a fost mutat din datele comerciale în secțiunea „Căutare — Prezentare pentru Google”, lângă titlul SEO și meta description; numele câmpului și regula de indexare au rămas neschimbate.
- Registrul furnizorilor folosește acum aceeași paradigmă ca registrul produselor: tabel compact pe desktop și carduri-listă operaționale pe tabletă și telefon, cu identitate, contact, email, telefon, CUI, număr de produse, status și acțiuni pe fiecare rând.
- Modalul furnizorilor are trei zone structurale independente: header fix, corp cu scroll propriu și footer fix. Bara de derulare nu mai trece prin antet sau acțiuni, iar butonul de salvare păstrează textul și săgeata centrate pe aceeași linie la toate dimensiunile.
- Rândurile furnizorilor au fost recalibrate pentru lizibilitate și aspect contemporan: tipografie funcțională mai mare, avatar și identitate mai clare, iconițe pentru email și telefon, capsule pentru CUI, număr de produse și stare, plus acțiuni de editare și ștergere perfect aliniate într-un grup cu dimensiuni identice.
- Inițialele furnizorilor sunt prezentate ca monograme circulare în identitatea La Măriuca, cu gradient roz, inel interior, accent auriu și interacțiune discretă la hover, în locul pătratelor generice.
- Contorul produselor asociate folosește fontul sans al interfeței și cifre tabulare, eliminând aspectul decorativ nepotrivit al fontului editorial pentru valori numerice mici.
- Literele monogramelor furnizorilor folosesc același font sans modern și o greutate clară; forma circulară, inelul și accentul auriu au fost păstrate.
- Punctul de stare al monogramei este conectat la datele furnizorului: verde pentru furnizor activ și galben-maroniu pentru furnizor inactiv; etichetele `activ` / `inactiv` sunt afișate cu litere mici pentru un aspect mai calm.
- Cardurile mobile ale furnizorilor nu mai moștenesc înălțimea celulelor desktop: fiecare zonă are înălțime automată, informațiile sunt grupate compact, statusul ocupă doar lățimea necesară, iar spațiile verticale fără conținut au fost eliminate.
- Registrul mobil a fost regândit ca agendă de contacte: identitate și status sus, persoană de contact, două acțiuni mari `Sună` / `Email`, detalii comerciale compacte și un footer cu `Editează` / `Șterge`. Telefonul folosește un URL `tel:` fără separatori, emailul folosește `mailto:`, iar website-ul are o zonă de atingere de 44 px. Emailurile se pot împărți pe rânduri, iar datele lipsă sunt afișate fără linkuri inactive.
- Verificare: 49 de template-uri Twig compilate, testul `SupplierAdminTest` trecut (35 de aserțiuni). Randare cu template-ul real și date fictive în Chrome headless la 320, 375, 390, 430, 768, 820, 1000, 1024, 1440 și 1920 px: fără depășirea paginii; acțiunile mobile au minimum 44 px. Capturi inspectate pentru telefon și tabletă, inclusiv nume lungi și contacte lipsă. Verificarea linkurilor s-a făcut fără lansarea unui apel sau trimiterea unui email; testele vizuale sunt locale în `storage/cache/qa-suppliers/` (ignorat de Git).
- Registrul produselor a fost refăcut exclusiv pentru telefon (maximum 600 px): imagine de 80×88 px, denumire completă, categorie și SKU grupate, preț și status pe același rând, casete aliniate pentru stoc online și stoc Conta, plus acțiuni `Editează` / `Șterge` de 48 px. Stocul redus, stocul epuizat și cantitățile rezervate rămân vizibile. Căutarea are text de 16 px și ținte tactile de 48 px.
- Verificare produse mobil: 49 de template-uri compilate; testul `testProductsRegistryUsesInventorySearchAndActiveNavigation` trecut (91 de aserțiuni). Chrome headless a randat template-ul real cu date fictive la 320, 360, 390, 430, 600, 601, 768, 1000, 1440 și 1920 px, fără depășire orizontală a paginii. Verificate nume/SKU lungi, preț mare, lipsa imaginii, stoc redus/zero și rezervări. Comparația automată cu măsurătorile anterioare confirmă că layoutul, fonturile și culorile existente sunt neschimbate la toate lățimile verificate peste 600 px. Capturile și scripturile de verificare sunt în cache-ul local ignorat de Git.
- Pictograma tabului „Furnizori” este acum o strângere de mână, în stilul de contur al navigării admin. Verificare: 49 de template-uri Twig compilate și captură inspectată în Chrome headless, cu template-ul real și date fictive.

## 2026-10-31 — Opțiuni și variante comerciale ghidate

- Atelierul de produs explică acum fluxul în limbaj simplu: administratorul adaugă alegerile clientului, de exemplu „Mărime” și „Culoare”, iar combinațiile comerciale sunt construite automat. Rezumatul arată permanent câte grupuri, valori și variante rezultă, fără termeni tehnici inutili.
- Fiecare combinație are propriile date autoritative: SKU automat sau manual, EAN/cod de bare, preț întreg, stoc disponibil, stoc nelimitat, urmărire separată în Conta și reducere opțională. Reducerea acceptă procent sau sumă fixă, validează limita față de preț și afișează imediat prețul final.
- Dacă produsul nu are opțiuni, interfața prezintă direct un singur preț și un singur stoc, fără denumirea artificială „Varianta 1”. Dacă există opțiuni, toate combinațiile sunt păstrate sincronizat și validate server-side înainte de salvare.
- Pe telefon, fiecare combinație comercială folosește acum un flux strict pe o singură coloană: prețul, stocul, EAN-ul și reducerea sunt carduri tactile succesive, cu lățime completă. Regulile mai specifice ale produsului simplu nu mai pot reconstrui coloanele de desktop, iar configurarea reducerii se deschide vertical sub EAN, fără suprapuneri sau texte strivite.
- Verificarea într-un viewport Chrome real de `390×844 px` confirmă `innerWidth = scrollWidth = 390 px`, fără depășire orizontală; cardurile pentru preț, EAN și reducere au aceeași lățime utilă de 340 px și curg succesiv. Lint-ul PHP, 49 de template-uri Twig, PHPStan și suita PHPUnit cu 88 de teste / 841 de aserțiuni trec integral.
- Stocul nelimitat este respectat în catalog, coș și checkout: produsul rămâne disponibil fără diminuarea inventarului fizic, iar indicatorii de stoc redus și notificările nu îl raportează eronat. Prețul și varianta trimise de browser nu devin niciodată sursa de adevăr.
- Migrarea `020_product_variant_commerce.sql` există separat pentru SQLite și MySQL și adaugă câmpurile comerciale fără modificarea migrărilor deja livrate. Migrarea SQLite a fost aplicată și verificată local; varianta MySQL rămâne de rulat și verificat într-un mediu MySQL real înainte de producție.
- Secțiunile „Opțiunile produsului” și „Preț, stoc și stare” au înălțime naturală și conținut complet vizibil în modal, inclusiv pe telefon. Confirmarea modificărilor nesalvate are acum acțiuni aliniate: text centrat, iconiță centrată geometric în cerc și ținte tactile corespunzătoare.
- Verificare vizuală în Chrome local la 1440×1000 și 390×844 px: secțiunile nu mai sunt comprimate, headerul și footerul modalului rămân fixe, scrollul aparține doar conținutului, iar dialogul „Ieși fără salvare” este aliniat pe desktop și telefon. Verificări automate trecute: sintaxă JavaScript, lint PHP, 49 de template-uri Twig compilate, PHPStan fără erori și PHPUnit cu 78 de teste / 736 de aserțiuni.
- Cardurile fiecărei alegeri au fost transformate într-un configurator vizual: pas numerotat, accent auriu-roz, contor dinamic de opțiuni, numerotarea fiecărei valori și stări clare de hover, focus și apăsare. Adăugarea intră fluid în listă, ștergerea glisează elementul și reașază opțiunile rămase, iar preferința sistemului pentru mișcare redusă dezactivează efectele.
- Pe telefon, cardul renunță la coloana laterală care consuma spațiu: pasul și ștergerea stau într-un header compact, câmpurile folosesc întreaga lățime, fonturile inputurilor au 16 px și toate acțiunile au minimum 40–48 px. `scroll-margin` păstrează câmpul activ sub antetul fix al modalului. Verificare Chrome cu formularul real la 1280×900 și 390×844 px: zero overflow, adăugarea și eliminarea unei valori funcționale, footer fix și conținut tactil lizibil.
- Identitatea produsului are acum un SKU principal persistent, separat de SKU-urile combinațiilor. Produsul simplu folosește acel cod fără să îl repete vizual în secțiunea comercială, iar trecerea produs simplu → combinații → produs simplu păstrează SKU-ul principal și preferința de urmărire în Conta. Produsele vechi care aveau SKU-ul implicit reutilizat de prima variantă sunt migrate transparent la prima salvare, prin generarea unui cod distinct pentru combinație.
- Urmărirea produsului simplu în stocurile Conta pornește bifată și rămâne memorată independent; combinațiile nou create pornesc nebifate. Dacă ultima alegere este eliminată, interfața și serviciul restaurează exact setarea produsului-mamă, fără a prelua accidental valoarea ultimei combinații.
- „Stare” și „Ales cu drag” apar acum imediat sub antetul „Preț, stoc și stare” și rezumatul „Un singur preț” / numărul de combinații, înaintea câmpurilor comerciale. Codul EAN rămâne opțional și nu mai este limitat artificial la 8–14 cifre; este păstrat exact în forma furnizată, cu unicitatea verificată în continuare.
- Imediat după secțiunea „Preț, stoc și stare”, atelierul include acum „Reducere generală”. Administratorul poate alege procent sau sumă fixă și poate copia reducerea într-un singur pas către toate combinațiile; valorile sunt validate față de fiecare preț întreg, iar după aplicare orice combinație poate fi ajustată independent. Configuratorul este progresiv, nu introduce câmpuri tehnice în salvarea server-side și se rearanjează într-o singură coloană tactilă pe telefon.
- Controlul Conta al produsului-mamă rămâne permanent vizibil sub „Stare”, inclusiv când există combinații. Regula este explicită și fără dubluri: o combinație bifată folosește exclusiv SKU-ul ei; o combinație nebifată cade în SKU-ul principal dacă produsul-mamă este urmărit; dacă ambele sunt debifate nu se creează mișcare contabilă. Comanda, plata și statisticile de vânzare rămân independente de această alegere.
- Migrarea `021_product_primary_commerce.sql` există separat pentru SQLite și MySQL și adaugă `products.primary_sku` plus `products.track_in_conta`, cu backfill din varianta implicită. Migrarea `022_accounting_stock_routing.sql` adaugă snapshot-ul țintei pe liniile de comandă și registrul separat `accounting_stock_balances` / `accounting_stock_movements`, unde soldul poate deveni negativ înainte de recepția NIR. Migrarea SQLite `022` a fost aplicată local; variantele MySQL rămân de verificat pe un server MySQL real înainte de producție.
- Comanda, plata, confirmarea și expedierea nu mai produc mișcări în registrul contabil. Expedierea consumă doar rezervarea și stocul operațional online. O ieșire contabilă este permisă exclusiv prin `AccountingStockService::recordInvoice()`, cu număr de factură obligatoriu, iar o intrare exclusiv prin `AccountingStockService::recordNir()`, cu număr NIR obligatoriu. Ambele operații sunt idempotente pe document. Factura folosește bucket-ul salvat pe linia comenzii — combinație, produs-mamă sau nicio mișcare când urmărirea Conta era oprită — iar NIR-ul folosește SKU-ul contabil configurat pe poziția recepționată.
- Separatoarele vizuale dintre grupurile navigării admin rămân vizibile și când bara laterală este restrânsă, fără a introduce linii între fiecare iconiță.
- Registrul produselor afișează prețul final al variantei implicite și, numai când reducerea este activă în baza de date, valoarea reducerii între paranteze, ridicată vizual ca exponent, plus prețul întreg tăiat imediat în dreapta. Reducerea procentuală apare ca `(10%)`, cea fixă drept `(11,90 Lei)`, iar produsele vechi fără tip salvat folosesc diferența reală dintre `compare_price_bani` și `price_bani`.
- „Descriere scurtă” este limitată la maximum 200 de caractere în adăugare și editare. Formularul afișează un contor live, evidențiază apropierea de limită și blochează tastarea peste prag; serviciul validează independent lungimea Unicode și respinge cererile ocolite. Verificarea în browser a confirmat `40/200`, actualizarea până la `200/200` și imposibilitatea introducerii celui de-al 201-lea caracter.
- Verificări finale: lint PHP și sintaxă JavaScript fără erori, 49 de template-uri Twig compilate, PHPStan fără erori și PHPUnit cu 83 de teste / 812 aserțiuni. Browserul local confirmă ordinea accesibilă `Preț, stoc și stare` → rezumat → `Stare` → `Ales cu drag` → urmărirea SKU-ului principal → combinații → `Reducere generală`; cardul are înălțime naturală atât în forma compactă, cât și în configurarea extinsă, inclusiv la 390×844 px. Testul contabil dedicat acoperă și două combinații distincte din aceeași comandă care cad pe același SKU principal și trebuie agregate într-o singură mișcare; verifică explicit și că expedierea singură nu atinge registrul contabil. Registrul produselor a fost verificat suplimentar pe desktop și la 390×844 px: prețul redus, valoarea reducerii și prețul întreg tăiat rămân pe aceeași linie, fără overflow orizontal.

## 2026-10-31 — Editor editorial pentru descrierea completă

- Câmpul „Descriere completă” din atelierul produsului a devenit un editor vizual în stilul La Măriuca, cu formatare bold, italic și subliniat, liste cu puncte sau numerotate, inserare de imagini și o suprafață editorială care arată direct structura finală a conținutului. Textarea clasică rămâne fallback funcțional când JavaScript nu este disponibil.
- Bara editorului vizual este împărțită acum în două grupuri clare — formatare și imagini — cu butoane complet rotunjite, iconițe și texte centrate, stare activă roz, acțiune aurie pentru inserarea imaginii și stări hover, focus și dezactivat distincte. Controalele păstrează ținte tactile de minimum 44 px și devin două rânduri orizontal derulabile pe telefon, fără să piardă etichetele esențiale.
- Imaginile pot fi încărcate din selector ori lipite direct din clipboard, apoi mutate cu drag-and-drop sau cu butoanele sus/jos. Fiecare imagine are configurare pentru dimensiune procentuală, aliniere și text alternativ, plus mâner tactil de redimensionare; click dreapta deschide aceleași opțiuni pe desktop.
- Încărcarea imaginilor folosește o rută administrativă protejată CSRF și pipeline-ul media existent. Descrierea este curățată obligatoriu pe server înainte de persistență: sunt păstrate numai elementele editoriale permise, imaginile locale și proprietățile de layout necesare, iar scripturile, handler-ele, CSS-ul periculos și imaginile externe încorporate sunt eliminate.
- Storefrontul afișează descrierea bogată prin filtrul Twig sigur, iar căutarea și recomandările extrag textul vizibil din HTML înainte de normalizare. Descrierile vechi în text simplu sunt convertite automat în paragrafe, iar tagurile legacy `b` / `i` emise de unele browsere sunt normalizate semantic la `strong` / `em`.
- Verificarea directă în browser a confirmat editorul în modal pe desktop și la 390×844 px, inclusiv formatarea reală a unei selecții, toolbarul tactil, headerul/footerul fixe și starea `Unsaved`, fără salvarea produsului folosit pentru probă. Verificări automate trecute: lint PHP, sintaxă JavaScript, 49 de template-uri Twig compilate, PHPStan fără erori și PHPUnit cu 88 de teste / 833 de aserțiuni.
- Descrierea lungă din pagina produsului se extinde și se restrânge acum printr-o tranziție măsurată pe înălțimea reală a conținutului, în locul saltului dintre `max-height` și `auto`. Mișcarea folosește aceeași curbă lină în ambele sensuri, se recalculează după încărcarea imaginilor și la schimbarea breakpointului, iar preferința `prefers-reduced-motion` rămâne respectată.
- Câmpul „Întreținere” a fost eliminat din formularul produsului din admin, iar informația „Îngrijire” nu mai este randată în secțiunea publică „Informații produs”. Coloana existentă din baza de date a fost păstrată pentru compatibilitate și pentru a evita o migrare distructivă inutilă.
- Câmpul „Conținutul pachetului” a fost eliminat din formularul produsului și din afișarea publică, iar „Accesorii neincluse” a devenit „Notă informativă”. Coloanele existente din baza de date au fost păstrate pentru compatibilitate.
- Zona SEO din editorul produsului este acum ghidată și responsive: are recomandări și contoare de caractere, previzualizare Google actualizată în timp real din titlu/descriere/nume/slug și o stare explicită pentru indexare. Fără JavaScript rămân disponibile câmpurile și valorile inițiale randate de server.
- Grila scrollabilă a modalului păstrează acum fiecare secțiune la înălțimea sa reală (`max-content`), astfel încât zona SEO nu mai poate fi comprimată la un rând subțire. Verificarea vizuală a confirmat înălțimea completă de 550 px pe desktop și 960 px la 390×844 px, cu headerul și footerul modalului fixe.
- Verificarea din browser a confirmat animația desktop `350 → 1418 px` și înapoi, plus animația mobilă la 390×844 px `280 → 1066 px` și înapoi; „Îngrijire” nu mai apare în DOM. Lint-ul PHP, sintaxa JavaScript, 49 de template-uri Twig, PHPStan și suita PHPUnit cu 88 de teste / 833 de aserțiuni trec integral.

### 2026-10-31 — SEO produse completat automat, cu suprascriere manuală
- Titlul din Google se completează în timp real din numele produsului cât timp câmpul SEO nu a fost editat manual; descrierea din Google copiază integral descrierea scurtă, inclusiv peste recomandarea vizuală de 160 de caractere.
- Golirea unui câmp SEO reactivează completarea automată, iar orice valoare introdusă manual rămâne independentă de numele sau descrierea produsului.
- Salvarea pe server aplică aceleași reguli fără JavaScript: un titlu SEO gol devine numele produsului, iar o meta descriere goală devine descrierea scurtă.
- Verificarea în browser a confirmat sincronizarea în timp real, păstrarea unei descrieri de 173 de caractere, suprascrierea manuală și revenirea la modul automat prin golire. Lint-ul PHP, 49 de template-uri Twig, PHPStan și întreaga suită PHPUnit cu 88 de teste / 841 de aserțiuni trec integral.

### 2026-10-31 — Grup de navigare pentru stoc și achiziții
- Navigarea administrației include acum grupul distinct „Stoc și achiziții”, poziționat imediat sub „Produse și clienți”. Tabul „Furnizori” a fost mutat în noul grup, păstrând ruta și starea activă existente.
- Verificări locale trecute: lint PHP, compilarea celor 49 de template-uri Twig, PHPStan fără erori și cele două teste de integrare vizate, cu 63 de aserțiuni în total.

### 2026-10-31 — Galerie foto administrabilă pentru produse
- În „Povestea produsului”, înaintea descrierii scurte, atelierul include acum o galerie foto în stilul La Măriuca. Administratorul poate încărca simultan imagini JPEG, PNG sau WebP, până la limita clar afișată de 12 fotografii.
- Fotografiile pot fi reordonate prin drag-and-drop pe desktop, prin gest tactil de pe mâner pe telefon și tabletă sau din tastatură cu săgețile stânga/dreapta. Prima fotografie este imaginea principală; orice altă fotografie poate fi mutată în prima poziție prin acțiunea „Alege principală”.
- Fiecare card are acțiune proprie de ștergere, previzualizare, poziție și stare principală. Acțiunile „Șterge” și „Adaugă poze” folosesc cursor de buton, ținte tactile mari, animații de inserare/ștergere/rearanjare și respectă `prefers-reduced-motion`.
- Ordinea și imaginea principală sunt persistate tranzacțional în `product_images`, folosind identificatorii media validați server-side. Formularul limitează și pe server galeria la 12 imagini și respinge identificatori media inexistenți.
- Încărcarea folosește pipeline-ul media existent și o rută administrativă protejată CSRF. Răspunsul JSON include identificatorul media necesar salvării relației cu produsul; imaginile utilizatorului din `logo-uri/` nu sunt modificate.
- Previzualizarea rezultatului Google din același atelier folosește acum sigla „G” oficială, multicoloră, livrată ca asset SVG local; nu depinde de resurse externe și rămâne clară pe ecrane cu densitate mare.
- Verificare: randare separată în browser headless la 1440×900 și 390×844 px, fără folosirea ferestrelor utilizatorului; sintaxa JavaScript, lint-ul PHP, 49 de template-uri Twig, PHPStan și suita PHPUnit cu 88 de teste / 854 de aserțiuni trec integral. Testul de integrare verifică ordinea salvată a imaginilor și reafișarea ei la editare.

### 2026-10-31 — Informațiile produsului echilibrate pentru trei câmpuri
- Când secțiunea „Informații produs” conține exact trei carduri, al treilea ocupă acum întregul rând pe desktop și tabletă, eliminând jumătatea goală din grila cu două coloane. Eticheta păstrează o lățime compactă, iar valoarea lungă primește restul spațiului.
- Pe telefon se păstrează afișarea existentă într-o singură coloană. Versiunea assetului CSS a fost actualizată pentru invalidarea cache-ului.
- Verificări locale trecute: lint PHP, compilarea celor 49 de template-uri Twig și testul de integrare vizat pentru pagina produsului, cu 12 aserțiuni.

### 2026-10-31 — Sold contabil negativ în registrul produselor
- Coloana „Stoc efectiv (Conta)” citește acum soldul real din `accounting_stock_balances`, însumând eventualele solduri ale produsului-mamă și combinațiilor sale. Pentru date demo mai vechi, fără niciun sold contabil creat, registrul păstrează temporar fallback-ul la inventarul fizic existent.
- Soldurile negative sunt evidențiate separat de valoarea zero, cu stare roz-roșiatică și explicația „sold contabil negativ”, atât în tabelul desktop, cât și în cardurile de telefon.
- Pentru verificarea locală, SKU-ul `LM-26-029` are un sold contabil demo de `-1`, fără document și fără mișcare contabilă; stocul său online nu a fost modificat.
- Verificări locale trecute: răspuns HTTP 200 și conținut real confirmat pentru filtrarea după `LM-26-029`, lint PHP, compilarea celor 49 de template-uri Twig, PHPStan fără erori și suita PHPUnit completă cu 88 de teste / 858 de aserțiuni.

### 2026-10-31 — Indicator dashboard pentru solduri Conta negative
- Al patrulea indicator din dashboard nu mai numără variantele cu stoc online redus. El afișează acum numărul produselor al căror sold contabil total este negativ.
- Soldurile produsului-mamă și ale combinațiilor sale sunt însumate, iar fiecare produs este numărat cel mult o dată. Cardul folosește o stare vizuală de atenționare și eticheta explicită „Stoc Conta negativ”.
- Verificări locale trecute: dashboard HTTP 200 cu valoarea reală `1`, lint PHP, compilarea celor 49 de template-uri Twig, PHPStan fără erori și suita PHPUnit completă cu 88 de teste / 859 de aserțiuni.

### 2026-10-31 — CRM complet pentru categorii
- Grupul „Produse și clienți” include acum tabul „Categorii”, poziționat imediat după „Produse” și înainte de „Stoc și achiziții”. Registrul afișează categoriile și subcategoriile într-un arbore tabelar compact, cu ramuri continue, retrageri reale pentru orice adâncime, slug, fotografii reale, număr de ordine pentru rădăcini, produse asociate, stare și acțiuni. ID-urile tehnice au fost eliminate din tabel, modal și pagina alternativă de editare; vechile etichete `N1`/`N2`, coloana de nivel, coloana redundantă de traseu și casetele-placeholder pentru fotografiile inexistente au dispărut de asemenea. Traseul complet rămâne disponibil contextual pe celula categoriei.
- Rezumatul din antet folosește două mini-carduri de statistică în paleta rose/auriu, cu numerele evidențiate, etichete lizibile și adaptare pe două coloane egale pe telefon, în locul capsulelor plate inițiale.
- Adăugarea și editarea folosesc un atelier modal ghidat în patru pași, cu fallback complet fără JavaScript. Numele și slugul sunt verificate live și obligatoriu pe server, slugul se generează din nume dar poate fi suprascris, iar titlul și descrierea SEO urmăresc automat numele până la prima personalizare manuală.
- Selectorul de părinte reutilizează acum complet componenta vizuală din editorul de produse: același rezumat, panou amplu, căutare, arbore cu ramuri, antet, închidere și variantă bottom-sheet pe telefon. Diferența este intenționată și strict funcțională: opțiunile sunt radio, astfel încât se poate alege doar „Categorie principală” sau exact un singur părinte. Selectorul nu se mai închide imediat la alegere, afișează selecția în subsol înainte de confirmare și nu expune ID-uri tehnice. Categoria curentă și toți descendenții ei sunt excluși la editare, iar poziția rezultată este comunicată în limbaj natural. Numărul de ordine este disponibil exclusiv pentru categoriile principale și este ignorat server-side pentru subcategorii.
- Categoria acceptă fotografie JPG/PNG/WebP prin pipeline-ul media existent, previzualizare, înlocuire, eliminare și text alternativ. Descrierea scurtă are limită Unicode de 100 de caractere atât în formular, cât și în serviciu. Ștergerea este protejată CSRF și este blocată cât timp există subcategorii sau produse asociate, cu explicație clară în dialog.
- Ordinea vitrinei și a navigării publice citește acum `categories.position`, fără lista de sluguri codificată în repository. Migrarea `023_category_root_order.sql` păstrează ordinea rădăcinilor existente în variante SQLite și MySQL; varianta SQLite a fost aplicată local, iar varianta MySQL rămâne de rulat și verificat pe un server MySQL real înainte de producție.
- Tabelul rămâne compact inclusiv la 1280 px și trece la carduri numai sub 1100 px, păstrând ramurile și ordinea verticală fără scroll orizontal. Antetul tabelului are 36 px și etichete centrate vertical, fără banda goală a variantei inițiale. Textele funcționale care ajunseseră la 7,5–9 px au fost ridicate la un prag lizibil de 10–11 px în tabel și la minimum 10 px în explicațiile formularului. Pe tabletă, fiecare card are acum identitatea sus, o bandă compactă și delimitată pentru slug/ordine/produse/stare și acțiunile pe un singur rând; pe telefon banda devine o grilă 2×2, fără golurile verticale și informațiile împrăștiate din varianta inițială. Butoanele mobile au contur continuu și o stare blocată neutră, fără bordură punctată. Pe desktop, editarea și ștergerea formează un singur control compact, centrat optic în coloană. Verificarea reală în browserul intern a confirmat tabelul la 1280 și 1758 px, cardurile la 1024, 768 și 390 px, tranziția fără overflow la 640/641 px, ramurile pe două niveluri, modalul, selectorul de părinte și absența formulărilor personale din interfață.
- Verificări locale trecute: sintaxă JavaScript, lint PHP, 51 de template-uri Twig compilate, PHPStan fără erori, `git diff --check` și suita PHPUnit completă cu 89 de teste / 948 de aserțiuni. Testul CRM dedicat acoperă acum 89 de verificări, inclusiv structura comună cu selectorul produselor, grupul radio, controlul de căutare/închidere, selecția unică, arborele, imaginile, generarea automată, unicitatea, părintele/poziția, ordinea, redirectul de slug, limita de 100 de caractere, ștergerea sigură și eliminarea ID-urilor, coloanelor ori placeholderelor redundante. Browserul intern a confirmat selectorul la 1280, 768 și 390 px, fără overflow, cu panoul complet vizibil deasupra barei de salvare și bottom-sheet pe telefon.

### 2026-10-31 — Redesign pentru adăugarea și editarea categoriilor
- Ambele moduri folosesc același formular redesenat, cu navigare permanentă între Identitate, Organizare, Prezentare și Google, secțiunea curentă evidențiată la derulare, câmpuri mai lizibile și bară fixă pentru renunțare/salvare. Stilurile dedicate sunt încărcate numai în administrarea categoriilor; pagina alternativă fără modal păstrează câmpurile și legăturile către secțiuni.
- Organizarea în catalog are un selector de părinte pe toată lățimea, o previzualizare compactă a poziției și un control separat pentru ordinea rădăcinilor. Arborele cu selecție radio se deschide într-un panou centrat pe desktop și într-un panou inferior pe telefon; închiderea readuce focusul la selector. Explicațiile pentru stare și ordine se actualizează după selecție.
- Fotografia este afișată integral în previzualizare, inclusiv pentru imagini verticale; încărcarea, înlocuirea, eliminarea, descrierea de maximum 100 de caractere și textul alternativ au spații și controale consecvente. Zona Google grupează câmpurile editabile, previzualizarea live și comutatorul de indexare. Butonul de salvare păstrează textul și iconița pe același rând în ambele moduri.
- Verificare vizuală în browserul intern: desktop la 1280×720 și 1440×1000, tabletă la 768×1024 și telefon la 390×844 și 320×740. Au fost verificate adăugarea, editarea cu fotografie existentă, navigarea între secțiuni, completarea automată din nume, căutarea în arbore, selecția unui singur părinte și dezactivarea ordinii pentru subcategorii. Formularele verificate nu au overflow orizontal; consola nu a raportat erori. Probele din browser nu au salvat modificări în catalog.
- Verificări automate pentru acest redesign: sintaxă JavaScript validă, 51 de template-uri Twig compilate, testul de integrare `CategoryAdminTest` trecut cu 89 de aserțiuni și `git diff --check`. Nu s-au făcut verificări sau modificări în producție.

### 2026-10-31 — Stare implicită publicată pentru categorii noi
- Formularul de adăugare preselectează „Publicată”, iar selectorul rămâne editabil. La editare se păstrează starea salvată a categoriei. Textul explicativ inițial corespunde selecției inclusiv fără JavaScript.
- Verificări: 51 de template-uri Twig compilate și `CategoryAdminTest` trecut cu 89 de aserțiuni. Browserul intern a confirmat selecția implicită `published`, schimbarea manuală în `draft` și păstrarea stării `archived` la editarea unei categorii existente, fără salvarea datelor de probă.
- Verificarea finală înainte de commit și push a trecut lint-ul PHP, PHPStan fără erori și întreaga suită PHPUnit: 89 de teste / 948 de aserțiuni.

### 2026-11-01 — Dialog de ștergere categorii clarificat
- Dialogul diferențiază vizual categoria ocupată de categoria care poate fi ștearsă. Pentru o categorie ocupată, subcategoriile și produsele asociate apar în carduri separate, cu numere și instrucțiuni scurte; acțiunea dezactivată fără utilitate a fost înlocuită cu un singur buton „Am înțeles”.
- Pentru o categorie liberă rămân disponibile confirmarea explicită și renunțarea. Dialogul are acum închidere dedicată, focus inițial sigur, tipografie mai lizibilă și o așezare într-o singură coloană pe telefon.
- Verificări: sintaxă JavaScript validă, 51 de template-uri Twig compilate și `CategoryAdminTest` trecut cu 89 de aserțiuni. Browserul intern a confirmat ambele stări pe desktop și la 390×844 px, fără erori în consolă și fără efectuarea vreunei ștergeri.

### 2026-11-01 — CRM administrativ pentru clienți
- Grupul „Produse și clienți” include acum tabul „Clienți”, după „Categorii”. Registrul are rezumat pentru conturi, abonați, autentificări Google și clienți cu comenzi, tabel responsive, căutare semantică și paginație. Căutarea combină termeni precum `Google`, `La Măriuca`, `abonat`, `neabonat`, starea contului, numele fără diacritice, emailul, telefonul și datele comenzilor.
- Profilul se deschide într-un modal modern, cu fallback complet într-o pagină separată fără JavaScript. Sunt afișate tipul contului, starea, abonarea la noutăți, datele de contact, crearea contului, ultima conectare, prima și ultima comandă, ultima adresă de livrare, numărul comenzilor, valoarea validă totală și coșul mediu.
- Indicatorul Google din tabel folosește sigla oficială locală într-un suport alb discret, cu fundal neutru și umbră fină; vechiul pătrat multicolor generat prin CSS a fost eliminat pentru a păstra aspectul elegant al registrului.
- Istoricul comenzilor este paginat în interiorul aceluiași modal și prezintă numărul, data, produsele, starea comenzii, starea plății, totalul și legătura către detaliul complet. Comenzile anulate sau returnate nu intră în valoarea comercială totală.
- Migrarea `024_user_last_login.sql` există separat pentru SQLite și MySQL. Autentificarea cu parolă și autentificarea Google actualizează `users.last_login_at`; migrarea `025_customer_admin_indexes.sql` adaugă indecșii necesari registrului și istoricului comenzilor. Variantele SQLite au fost aplicate local, iar variantele MySQL rămân de rulat și verificat pe un server MySQL real înainte de producție.
- Verificări locale trecute: sintaxă JavaScript, 56 de template-uri Twig compilate, PHPStan fără erori și întreaga suită PHPUnit cu 90 de teste / 980 de aserțiuni. Testul dedicat acoperă căutarea semantică, normalizarea diacriticelor, paginația clienților și comenzilor, modalul, fallbackul fără JavaScript și memorarea ultimei conectări. Browserul intern a confirmat registrul și profilul modal pe desktop, fără modificarea datelor clientului.
- Profilul clientului a fost modernizat cu o identitate vizuală compactă, indicatori comerciali, carduri clare pentru cont și adresă, stări goale coerente și rearanjare completă pe telefon. Administratorul poate dezactiva contul numai cu un motiv între 3 și 300 de caractere; motivul, data și administratorul sunt auditate, autentificarea este oprită imediat, iar starea apare în registru. Reactivarea șterge blocarea și redă accesul. Migrarea `026_customer_account_blocking.sql` a fost aplicată local în varianta SQLite; varianta MySQL rămâne de verificat pe MySQL real.
- Profilul include acum „Reducerile clientului” și un configurator modal ghidat în trei pași. Avantajul poate viza întreaga comandă, un produs, o categorie sau livrarea; acceptă procent, sumă fixă și, pentru livrare, gratuitate. Produsele au căutare și fotografie, categoriile păstrează traseul din arbore, iar valabilitatea poate fi permanentă sau delimitată de două date. Reducerile active pot fi eliminate individual și au fallback complet fără JavaScript.
- Reducerile personale se aplică automat numai clientului autentificat. Pentru marfă se alege cel mai avantajos beneficiu eligibil și nu se cumulează cu un cupon global; între cupon și reducerea personală câștigă valoarea mai mare. Avantajul de livrare se aplică separat după regula transportului gratuit. Identificatorii reducerilor și economia la livrare sunt salvați pe comandă și reverificați în tranzacția checkout. Migrarea `027_customer_discounts.sql` a fost aplicată local în varianta SQLite; varianta MySQL rămâne de rulat și verificat înainte de producție.
- Verificarea finală a trecut lint-ul PHP, compilarea celor 58 de template-uri Twig, sintaxa JavaScript, `git diff --check`, PHPStan fără erori și întreaga suită PHPUnit cu 91 de teste / 1003 aserțiuni. Browserul intern a confirmat profilul, configuratorul, selecția dintre 42 de produse, livrarea gratuită și perioada pe desktop și la 390×844 px, fără erori în consolă și fără salvarea datelor de probă.
- Bara de căutare inteligentă ascunde controlul nativ redundant al câmpului `search` și păstrează un singur buton „Șterge căutarea”. Acțiunea „Caută” și celelalte acțiuni importante ale profilului păstrează textul și săgeata pe același rând; pe telefon, textul căutării este înlocuit intenționat de iconița unică, centrată într-o țintă de 45 px. Verificarea în browser la lățimea desktop și la 390 px a confirmat un singur `X`, aliniere pe un rând, lipsa overflow-ului și consola fără erori.
- Monograma clientului nu mai folosește aspectul generic cu inițiale într-un pătrat. Folosește acum familia editorială Fraunces, un sigiliu organic cu contur dublu, lumină discretă, accent auriu și o mișcare fină la hover; varianta din profil păstrează aceeași identitate la scară mai mare. Browserul intern a confirmat fontul și proporțiile în registru și profil, pe desktop și la 390×844 px, fără overflow sau erori în consolă.
- Iconița emailului din antetul profilului este limitată explicit la 16×16 px. Selectorul urmărește acum structura reală a componentei, inclusiv pe mobil, astfel încât SVG-ul să nu mai cadă la dimensiunea implicită supradimensionată a browserului.
- Textele auxiliare din profil au fost mărite la o scară lizibilă de aproximativ 10,5–12,5 px, păstrând diferențele dintre etichete și conținut. Acțiunile de dezactivare și reactivare au clase proprii, iconița și textul rămân pe un singur rând, iar testul CRM confirmă acum întregul ciclu dezactivare → autentificare refuzată → reactivare → autentificare permisă.
- Selectorul de produse din configuratorul de reduceri folosește acum carduri aerisite, imagini mai clare, SKU separat, indicator live pentru numărul rezultatelor și stări explicite pentru potrivirea exactă ori lipsa rezultatelor. Căutarea ignoră diacriticele și punctuația, înțelege forme uzuale ale termenilor românești, tolerează greșeli mici și ordonează rezultatele după relevanța numelui, SKU-ului, categoriei, descrierii, brandului și variantelor. Un nume complet sau orice SKU activ introdus exact are prioritate exclusivă și afișează numai produsul corespunzător.
- În profilul modal, identitatea clientului și cei patru indicatori comerciali formează acum un antet fix. Informațiile au propriul container de derulare aflat integral sub rezumat, astfel încât atât traseul, cât și mânerul barei de scroll pornesc exact de sub header; antetul rămâne permanent vizibil pe desktop și pe ecranele mici.
- Verificările acestei iterații au trecut: lint PHP, 58 de template-uri Twig compilate, sintaxă JavaScript, `git diff --check`, PHPStan fără erori și suita integrală PHPUnit cu 91 de teste / 1018 aserțiuni. Browserul intern a confirmat căutarea semantică, numele exact, SKU-ul principal și SKU-ul unei variante, plus separatorul real al scrollului la 1280×720 și 390×844 px; nu au existat erori în consolă și nu au fost salvate date de probă.
- Configuratorul modal pentru reduceri folosește aceeași separare structurală: antetul „Creează o reducere” rămâne fix, iar pașii și bara de activare se află într-un container de derulare propriu. Traseul scrollului începe numai sub antet, fără să traverseze zona de identitate sau butonul de închidere. Browserul intern a confirmat geometria la 1280×720 și 390×844 px, inclusiv după focalizarea elementelor din partea de jos; numai containerul interior își modifică poziția de scroll, fără overflow orizontal sau erori în consolă. Verificările automate au trecut cu 58 de template-uri Twig și 91 de teste / 1019 aserțiuni.
- Ierarhia vizuală a configuratorului a fost refăcută pentru lizibilitate: explicațiile și etichetele funcționale au acum 10,5–12 px, titlurile de pas sunt mai clare, iar opțiunile, valoarea, perioada și bara de activare folosesc suprafețe, contraste și stări selectate consecvente. Cardurile au mai puțin spațiu mort și păstrează ținte tactile generoase pe telefon; selectorul de produs a primit aceeași scară tipografică. Browserul intern a confirmat designul și separarea exactă dintre antet și scroll la 1280×720 și 390×844 px, fără overflow orizontal sau erori în consolă. Lint-ul PHP, compilarea celor 58 de template-uri Twig, PHPStan, `git diff --check` și suita cu 91 de teste / 1019 aserțiuni au trecut.
- Footerul configuratorului de reduceri este acum în afara containerului derulabil: traseul barei se oprește exact deasupra acțiunii, fără să mai treacă pe lângă buton, iar săgețile native au fost eliminate în favoarea unui mâner discret. Geometria a fost confirmată în browser la 1280×720 și 390×844 px (`bodyBottom` egal cu `footerTop`), fără erori în consolă; lint-ul PHP, 58 de template-uri Twig, `git diff --check` și 91 de teste / 1019 aserțiuni au trecut.
- Zona „Reducerile clientului” folosește acum rânduri pe toată lățimea, contor de avantaje, stare activă și acțiuni explicite, fără coloana goală produsă de o singură reducere. Confirmarea eliminării este un dialog modal centrat, cu backdrop, anulare și confirmare separată; nu mai extinde cardul și nu mai este tăiată de secțiune. Browserul intern a confirmat lista și pop-up-ul la 1280×720 și 390×844 px, fără erori în consolă.
- Seederul pentru comenzi demo poate asocia explicit comenzile unui client prin `seed:customer-orders-demo <email-client> [număr]`. În baza locală au fost create 5 comenzi demonstrative pentru contul Alexie, cu stări, plăți, produse și valori diferite; profilul afișează 5 comenzi, totalul de 1.859,10 Lei și coșul mediu de 371,82 Lei. Lint-ul PHP, sintaxa JavaScript, compilarea celor 58 de template-uri Twig, PHPStan, `git diff --check` și suita cu 91 de teste / 1019 aserțiuni au trecut.
- Istoricul clientului afișează acum 4 comenzi pe pagină, astfel încât setul local de 5 comenzi produce două pagini verificabile (4 + 1). Eticheta redundantă „valoare validă” a fost eliminată, iar „Elimină reducerea” este o acțiune orizontală explicită pe desktop și ocupă propriul rând pe telefon. Browserul intern a confirmat navigarea AJAX între „Pagina 1 din 2” și „Pagina 2 din 2”, plus layouturile la 1280×720 și 390×844 px.
- Dezactivarea și reactivarea contului folosesc acum explicit URL-ul formularului în cererea AJAX. Câmpul trimis cu numele `action` nu mai poate masca proprietatea nativă a formularului și redirecționa cererea către o adresă inexistentă; eroarea brută `404 Not Found` nu mai apare. Testul CRM reproduce contractul JSON real pentru ambele stări. Verificările locale trec cu sintaxă JavaScript validă, 58 de template-uri Twig, PHPStan fără erori și 91 de teste / 1022 de aserțiuni.

### 2026-11-02 — Registrul abonaților newsletter
- Grupul „Produse și clienți” include acum tabul „Abonați newsletter”, construit în aceeași familie vizuală cu registrul Clienți. Rezumatul arată dimensiunea registrului, abonații activi, abonările venite din contact și mesajele trimise, iar lista prezintă starea, sursa abonării, data, numărul mesajelor, ultima notificare și sursa dezabonării.
- Căutarea server-side este semantică și fără diacritice: combină numele, emailul, subiectele mesajelor, starea și expresii precum `contact activ`, `formular newsletter` sau `dezabonat`. Registrul are paginație de 15 abonați, iar profilul fiecărui abonat se deschide într-un modal cu fallback complet fără JavaScript.
- Profilul păstrează identitatea și cei patru indicatori într-un antet fix; numai conținutul de sub rezumat se derulează. Sunt afișate datele abonării/dezabonării și parcursul complet al consimțământului. Mesajele rămân rezumate compact prin număr, livrări, deschideri și ultima notificare; lista detaliată și paginația ei au fost eliminate pentru un profil mai aerisit.
- Migrarea `028_newsletter_subscribers.sql` adaugă separat pentru SQLite și MySQL abonații, evenimentele de consimțământ, mesajele și livrările. Formularul din footer persistă abonarea cu sursa `footer_newsletter`, iar acordul opțional din formularul de contact folosește sursa `contact_form`; ambele fluxuri au CSRF, validare și jurnal de evenimente. Varianta SQLite a fost aplicată local, iar varianta MySQL rămâne de rulat și verificat într-un mediu MySQL real înainte de producție.
- Comanda idempotentă `seed:newsletter-demo [număr]` pregătește date locale pentru verificare; baza locală are 18 abonați demonstrativi, surse și stări diferite, plus 6 mesaje pentru profilul principal. Nu au fost create abonări sau trimise mesaje în producție.
- Registrul folosește tabel compact pe desktop și carduri la maximum 900 px. Verificarea în browserul intern la 1440×900, 768×1024 și 390×844 px a confirmat lipsa overflow-ului global, exemplele de căutare așezate fără scroll orizontal, textele auxiliare lizibile, modalul cu scroll pornit exact sub antet și căutarea `contact activ` cu exact cele 6 rezultate așteptate. Consola nu a raportat erori.
- Verificările finale au trecut integral: lint PHP, sintaxă JavaScript, 63 de template-uri Twig compilate, PHPStan fără erori și suita PHPUnit cu 93 de teste / 1060 de aserțiuni.

### 2026-11-03 — Cupoane, promoții automate și bara de sus a magazinului
- Grupul „Produse și clienți” include acum tabul „Cupoane și promoții”, cu registru comun, rezumat, căutare semantică fără diacritice, paginație și cele două fluxuri distincte „Adaugă cupon” și „Adaugă reducere”. Vechiul ecran de cupoane redirecționează către noul registru, astfel încât toate ofertele folosesc aceleași validări.
- Configuratorul modal, disponibil și ca pagină completă fără JavaScript, ghidează alegerea beneficiului pentru comandă, produs, ramură de categorie sau livrare. Sunt disponibile procentul, suma fixă și livrarea gratuită, perioadă permanentă ori interval în fusul Europe/Bucharest, iar selectorul de produse păstrează căutarea semantică și potrivirea exclusivă pentru nume sau SKU exact. Cupoanele au cod normalizat cu majuscule, prag minim și limite totale/per client.
- Fluxul de reducere include acum trei porniri rapide, inclusiv „Livrare gratuită de la 300 Lei”, alegerea explicită între orice comandă și un prag valoric, texte adaptate beneficiului și un rezumat live înainte de activare. Pragul este stocat în bani, validat atât în browser, cât și pe server și reverificat la plasarea comenzii, astfel încât o comandă care nu mai este eligibilă nu poate păstra promoția. Nu mai există un prag global paralel: în lipsa unei campanii eligibile se aplică exact costul standard al metodei de livrare alese.
- Atât cupoanele, cât și reducerile automate pot fi anunțate opțional în bara de sus a storefrontului. Editorul propune un mesaj coerent cu oferta și îl poate aplica dintr-un singur buton; previzualizarea arată starea barei, beneficiul, domeniul și pragul exact. Selectorul de valabilitate folosește acum carduri line-art cu stări selectate clare, iar în magazin oferta activă cu prioritatea cea mai mare înlocuiește mesajul implicit de livrare și rămâne compactă pe desktop și telefon. Toate valorile sunt randate server-side și escapate de Twig.
- Coșul și checkoutul evaluează cupoanele, reducerile personale și promoțiile globale după aceleași reguli de eligibilitate. Pentru produse câștigă un singur beneficiu, cel mai avantajos; livrarea este evaluată separat. Comanda păstrează identificatorii promoției comerciale și ai promoției de livrare, iar cuponul consumă limita numai dacă el este beneficiul ales.
- Ciclul cupoanelor este tranzacțional: rezervare la checkout, confirmare la confirmarea comenzii sau plata Stripe reușită și eliberare la anulare/expirare. Evenimentele Stripe terminale anulează comanda neplătită și eliberează împreună stocul, rezervările și cuponul; erorile retryable nu eliberează prematur avantajul, iar o plată tardivă după anulare intră în `manual_review`. Comanda `reservations:expire` aplică aceeași eliberare completă și înregistrează mișcările de stoc.
- Migrările `029_promotions_and_coupon_campaigns.sql` și `030_promotion_minimum_order_value.sql` există separat pentru SQLite și MySQL. Variantele SQLite au fost aplicate local; variantele MySQL, concurența reală MySQL și webhookurile Stripe live nu au fost verificate și rămân pași de făcut înainte de producție.
- Browserul intern a verificat registrul, modalul, presetul de livrare gratuită, pragul obligatoriu, selectorul de valabilitate în ambele stări, previzualizarea anunțului, scrollul separat de antet și rearanjarea responsive la 1440×900 și 390×844 px, fără overflow orizontal sau erori în consolă. Datele temporare folosite pentru verificarea barei au fost eliminate. Verificările finale au trecut: lint PHP, 66 de template-uri Twig, sintaxă JavaScript, PHPStan, `git diff --check` și întreaga suită PHPUnit cu 101 teste / 1278 de aserțiuni. Nu s-a efectuat nicio modificare în producție.

### 2026-11-03 — Metode de livrare configurabile
- Grupul „Produse și clienți” include tabul „Livrare”. Administratorul poate adăuga și edita mai multe metode de curier, locker, ridicare personală sau opțiuni proprii, fiecare cu nume, cod intern generat automat și editabil, descriere, cost în Lei, estimare, ordine, stare și alegere implicită.
- Registrul prezintă clar metodele active și ascunse, costul standard, intervalul de livrare, ordinea din checkout și numărul comenzilor care au folosit metoda. Dezactivarea păstrează istoricul, nu permite eliminarea ultimei opțiuni active și alege automat o metodă implicită validă; pe MySQL, modificările administrative sunt serializate prin blocarea rândurilor înaintea acestor decizii.
- Formularul modal este ghidat în patru pași și include o previzualizare live a selectorului din checkout. Antetul și bara de salvare rămân fixe, iar scrollul aparține exclusiv corpului formularului. Pe telefon, lista devine card, iar acțiunile „Editează” și „Ascunde/Activează” ocupă un singur rând clar, fără suprapuneri.
- Pragul pentru livrare gratuită nu există în configurarea metodelor. Interfața indică explicit secțiunea „Cupoane și promoții”, iar checkoutul folosește costul standard al metodei selectate până când o promoție eligibilă acordă reducerea de livrare sau gratuitatea.
- Checkoutul acceptă doar identificatorul unei metode active și reîncarcă metoda în aceeași tranzacție. Numele, codul și costul provin exclusiv din baza de date, nu din browser; comanda păstrează ID-ul și snapshoturile numelui/codului, iar detaliul administrativ afișează metoda aleasă. O metodă modificată ulterior nu rescrie istoricul comenzilor.
- Migrarea `031_shipping_methods.sql` există separat pentru SQLite și MySQL. Varianta SQLite a fost aplicată și verificată local; varianta MySQL și comportamentul concurent pe MySQL rămân de verificat într-un mediu staging/production înainte de lansare.
- Browserul intern a verificat registrul și formularul la 1440×900 și 390×844 px: previzualizarea live, codul generat, scrollul izolat, acțiunile mobile și lipsa overflow-ului global funcționează fără erori în consolă și fără salvarea datelor de probă. Verificările finale au trecut: lint PHP, 69 de template-uri Twig, sintaxă JavaScript, PHPStan, `git diff --check` și întreaga suită PHPUnit cu 108 teste / 1394 de aserțiuni. Nu s-a efectuat nicio modificare în producție.
- Controlul „Sugerează această metodă” folosește acum un checkbox invizibil de 1×1 px ancorat în propriul card. Containerul exterior al modalului nu mai poate fi derulat programatic la focalizare, astfel antetul, conținutul și footerul nu mai dispar la bifare sau debifare. Browserul intern a confirmat comportamentul la 820×900 și 390×844 px: `surfaceScrollTop` rămâne `0`, formularul rămâne la `top: 0`, iar consola nu raportează erori.
- Registrul de livrare folosește la maximum 900 px carduri compacte cu datele distribuite pe trei coloane, iar sub 560 px trece la două coloane. Identitatea metodei și acțiunile ocupă rânduri complete, starea rămâne aliniată la început, cele două acțiuni păstrează ținte tactile de minimum 44 px, iar sumarul de sus rămâne într-un singur rând pe telefon. Browserul intern a verificat 390, 360 și 320 px fără overflow orizontal sau erori în consolă; testul de regresie fixează ambele hărți de grid și rândul dedicat acțiunilor. Verificările finale au trecut: lint PHP, 69 de template-uri Twig, sintaxă JavaScript, PHPStan, `git diff --check` și întreaga suită PHPUnit cu 109 teste / 1402 aserțiuni pe PHP 8.4.25.

## 2026-11-03 — Finalizare comandă, adrese și coduri poștale

- Checkout redesenat cu secțiuni numerotate, câmpuri lizibile, selector de livrare/plată și rezumat cu fotografii. Totalul afișat include livrarea și promoțiile eligibile, folosind aceeași cotare server ca la plasarea comenzii. La schimbarea metodei, JavaScript afișează sumele calculate de server; fără JS există recalculare prin formular, fără creare de comandă sau rezervare de stoc.
- Selectoarele județ/localitate caută fără diacritice, permit navigare din tastatură și folosesc panouri adaptate telefonului. Perechile sunt sincronizate atomic la selectarea unei adrese, iar schimbarea județului golește localitatea veche. Câmpurile text rămân disponibile fără JS ori dacă nomenclatorul nu se încarcă.
- Index GeoNames local cu 37.610 coduri poștale, reconciliate cu SIRUTA; sursele/licența și generatorul sunt documentate în `docs/DATA_SOURCES.md`. Un rezultat unic completează județul/localitatea doar când adresa este goală. Codurile ambigue cer selecție, cele necunoscute permit completare manuală, iar adresele existente nu sunt suprascrise. Strada și numărul nu sunt deduse din codul poștal.
- Clienții primesc automat datele profilului/adresei implicite, pot selecta adresele salvate și pot salva rapid o adresă din checkout. Endpointul verifică CSRF și contul activ, validează adresa și folosește doar proprietarul din sesiune. Vizitatorii pot comanda fără cont; legăturile de autentificare/înregistrare revin în checkout printr-un marker fix, fără URL arbitrar. Datele formularului se păstrează după erori.
- Browserul intern a verificat 1440×1000, 1280 px, 768×1024, 390×844 și 320×740, fără overflow orizontal sau erori în consolă. Verificate căutarea tastatură Cluj/Cluj-Napoca, Iași fără diacritice, precompletarea `010101`, alegerea între localitățile codului `510002` și păstrarea adresei la un cod nou. Testele de integrare verifică și HTML-ul pentru client/vizitator, salvarea adresei, CSRF, proprietarul și revenirea după login.
- Verificări finale: lint PHP, 69 template-uri Twig, sintaxă JS, PHPStan și PHPUnit: 126 teste / 1529 aserțiuni. Nu s-au plasat comenzi reale în browser. Nu s-au verificat plăți Stripe, SMTP, Google OAuth live sau MySQL în această intervenție; nu s-a făcut deploy în producție.
- Antetul checkout a fost rafinat separat: suprafață crem/roz discretă, titlu cu accent italic și indicator de progres cu etichete clare, pasul curent evidențiat și întoarcere accesibilă la coș. Layoutul devine vertical pe tabletă/telefon; verificat în browser la 1440, 768, 390 și 320 px fără overflow, tăieri sau erori în consolă. Au trecut compilarea celor 69 template-uri, testele `CheckoutAddressTest` (4 teste / 62 aserțiuni) și `git diff --check`.

### 2026-11-03 — Profil newsletter mai compact și lizibil
- Modalul abonatului are acum înălțime potrivită conținutului, identitate compactă, indicatori clari pentru mesaje/livrări/deschideri și două coloane pentru detalii, istoric și ultima notificare. Textele secundare au fost mărite, iar profilul păstrează paleta caldă a administrației, cu accente discrete pentru stări.
- Antetul rămâne fix, iar derularea aparține exclusiv corpului modalului. Pe telefon și tabletă, secțiunile se așază vertical; emailurile lungi se împart pe rânduri. Pagina separată fără JavaScript rămâne liberă de limitările de înălțime ale modalului. Lista detaliată a mesajelor nu a fost reintrodusă.
- Verificat în browserul intern la 1440×950, 768×1024, 390×844 și 320×740, inclusiv profil activ, profil dezabonat cu două evenimente și profil cu notificări trimise. Nu există overflow orizontal sau erori în consolă; antetul rămâne pe loc la derulare, iar containerul exterior are scroll zero. Au trecut compilarea celor 69 template-uri, `NewsletterAdminTest` (2 teste / 35 aserțiuni) și `git diff --check`. Nu s-au modificat datele abonaților și nu s-au trimis mesaje.

### 2026-11-03 — Bară de ofertă animată în magazin
- Bara promoțională folosește un fundal roz închis cu accente crem, iconiță de curier pentru ofertele de livrare și simbol procent pentru reducerile comerciale. Săgeata din dreapta a fost eliminată; codurile de cupon sunt afișate integral într-un contur discret.
- Mesajul circulă continuu de la stânga la dreapta pe întreaga lățime. O copie vizuală ascunsă cititoarelor de ecran asigură reluarea fără salt la margini; distanța se recalculează la redimensionare și la încărcarea fonturilor. Pe telefon viteza este mai mică. Mișcarea continuă și la hover, iar preferința de mișcare redusă sau lipsa JavaScript păstrează mesajul static și lizibil.
- Verificat în browserul intern la 1440, 768, 390 și 320 px: mișcare spre dreapta, reluare la margini, iconiță de livrare, lipsa săgeții și a overflow-ului orizontal, fără erori în consolă. Randarea Twig a confirmat separat iconița procent/livrare și păstrarea codului cuponului. Au trecut 69 template-uri compilate, sintaxa JavaScript, `PromotionAdminTest` (3 teste / 112 aserțiuni) și `git diff --check`. Campaniile existente nu au fost modificate.
- La cererea utilizatorului, varianta cu mesaje repetate pe întreaga lățime a fost retrasă. Bara revine la mesajul care traversează ecranul spre dreapta, păstrând iconițele, culorile și adaptarea pentru telefon. Compilarea celor 69 template-uri, sintaxa JavaScript și `git diff --check` au trecut după revenire.
- Limitele deplasării sunt acum marginile navbarului, nu ale ferestrei: bara de categorii și zona care decupează animația folosesc aceeași lățime CSS, maximum 1500 px pe desktop. Pe tabletă/telefon sunt păstrate marginile interioare de 16 px ale antetului. JavaScript măsoară zona animației, astfel încât bucla se adaptează la aceste limite. Browserul a confirmat margini identice la 1920 și 1440 px, plus afișarea la 768, 390 și 320 px, fără overflow sau erori. Au trecut compilarea a 69 template-uri, sintaxa JavaScript și `git diff --check`.
- Pauza la hover a fost eliminată la cererea utilizatorului. Verificat în browserul intern la 1280 px: bara este în starea `:hover`, animația rămâne `running`, iar transformarea se modifică între citiri. Au trecut compilarea celor 72 template-uri Twig și `git diff --check`.

### 2026-11-04 — Centru complet pentru administrarea comenzilor
- Detaliul comenzii a fost reconstruit ca spațiu operațional responsive: identitate și stări, indicatori rapizi, produse cu fotografia curentă când există, mărire în lightbox, SKU, variantă, personalizări, prețuri snapshot și legătură către produs într-o filă nouă. Datele clientului, telefonul, emailul, adresa de livrare/facturare, metoda de livrare/plată, totalurile, notele și cronologia administrativă sunt separate în carduri lizibile. La cererea utilizatorului, AWB-ul nu este afișat și nu mai este solicitat în fluxul de expediere.
- Schimbarea statusului folosește un modal ghidat și permite doar tranzițiile valide: nouă → confirmată, confirmată → în pregătire/expediată, în pregătire → expediată, expediată → livrată, cu anulare numai înaintea expedierii. Eliberarea rezervărilor/cupoanelor și consumarea stocului rămân tranzacționale. O notă opțională intră în audit, iar notificarea clientului poate fi pusă în outbox în aceeași tranzacție.
- Retururile totale și parțiale sunt persistate prin migrarea `032_order_returns.sql` pentru SQLite și MySQL. Administratorul alege produsele și cantitățile rămase, motivul, nota, refacerea stocului și evidența unei rambursări manuale parțiale/integrale. Cantitățile nu pot depăși soldul returnabil, stocul și mișcările sunt actualizate tranzacțional, iar returul complet mută comanda în starea `returned`. Marcarea rambursării nu inițiază un transfer Stripe; doar actualizează evidența după o rambursare procesată în afara acestui flux.
- Modalurile de status, email și retur au antet fix, derulare numai sub antet și footer de acțiuni stabil. Fluxurile au fallback complet ca pagini fără JavaScript, iar cererile AJAX păstrează CSRF și autorizarea admin. Emailurile de status sunt adăugate în outbox cu tipul `order_status_update`; procesorul local generează subiect și corp dedicate.
- Browserul intern a verificat pagina și cele trei modale pe desktop, tabletă la 768×1024 și telefon la 390×844, inclusiv scrollul returului, footerul fix, lipsa overflow-ului orizontal, textele funcționale mărite și absența AWB-ului. Nu au fost schimbate statusuri, trimise emailuri sau înregistrate retururi prin browser.
- Varianta SQLite a migrării a fost aplicată local. Varianta MySQL, concurența reală MySQL, expedierea SMTP și rambursările Stripe live nu au fost verificate și rămân pași externi înainte de producție. Verificările locale au trecut: lint PHP, sintaxă JavaScript, 76 template-uri Twig compilate, PHPStan fără erori, `git diff --check` și întreaga suită PHPUnit cu 144 teste / 1821 aserțiuni. Testul dedicat acoperă pagina, modalele, schimbarea statusului, anularea, auditul, outboxul, returul parțial/integral, limita cantității, refacerea stocului și evidența rambursării.

### 2026-11-04 — Rafinare vizuală a paginii de comandă
- Antet mai compact, parcurs vizual al stărilor, indicatori cu numărul real de bucăți, carduri de produs cu fotografie și prețuri aliniate, adresă evidențiată și rezumat financiar simplificat. Tipografia, contrastul, spațierea și acțiunile folosesc aceeași paletă caldă; layoutul se adaptează spațiului disponibil lângă bara laterală. AWB-ul rămâne absent.
- Modalele de status, e-mail și retur au antet și footer fixe, cu un singur container derulabil între ele. Etichetele sunt mai lizibile, textele administrative sunt mai directe, iar butoanele păstrează iconița și textul pe același rând. Returul arată permanent numărul de bucăți selectate; butonul de status preia acțiunea aleasă și evidențiază anularea.
- Corectată trimiterea formularului de status prin citirea atributului action, deoarece câmpul radio cu numele action masca proprietatea formularului. Pagina separată păstrează navigarea Renunță fără JavaScript, iar opțiunile de retur se îmbunătățesc când scriptul este disponibil.
- Verificate în browserul intern pagina și modalele la 1440×1000, 768×1024, 390×844 și 320×740 px: fără overflow orizontal, fotografii încărcate și mărite în lightbox, link de produs cu target _blank, sumar de selecție și margini de scroll identice cu limitele antetului/footerului. Consola nu a raportat erori. Nu au fost schimbate comenzile și nu s-au trimis e-mailuri în verificarea vizuală.
- Au trecut sintaxa JavaScript, compilarea celor 76 template-uri Twig, testele OrderAdminWorkflowTest (3 teste / 49 aserțiuni) și git diff --check. Nu s-au verificat în această intervenție SMTP, Stripe, MySQL sau producția.

### 2026-11-07 — Urmărire publică a comenzii
- Pagina `/urmarire-comanda/`, legată în footer la „Informații”, permite identificarea prin numărul complet și emailul comenzii, fără cont. Interfața include ilustrație animată, formular cu feedback, antet cu referința comenzii, timeline animat, fotografii și informații snapshot despre produse, date de livrare/contact, subtotal, reduceri, livrare, taxe, total și starea plății. Timeline-ul devine vertical pe telefon; cifrele și acțiunile rămân lizibile.
- Datele și orele intrării în status sunt citite din istoricul tranzacțional și afișate în `Europe/Bucharest`. Confirmarea/anularea Stripe și plata tardivă înregistrează acum evenimente de status; reîncercările în aceeași stare nu rescriu momentul intrării. Returul complet și verificarea manuală au de asemenea dată când există în istoric. Pentru comenzile vechi fără evenimente apare „Dată neînregistrată”, iar etapele viitoare sunt „În așteptare”; `updated_at` nu este prezentat fals drept moment al tranziției.
- Accesul verifică ambele date, are CSRF și limitare de încercări, acordă o sesiune de 30 de minute și interzice cache-ul/indexarea. Emailurile de confirmare și actualizare primesc buton HTML și alternativă text cu link privat HMAC, fără email în URL. Linkul deschide direct comanda, apoi redirecționează la adresa curată. Nu se expun notele interne sau identificatorii plății.
- Migrarea nouă `033_order_documents.sql` pregătește facturile private pentru SQLite și MySQL. Descărcarea apare numai pentru PDF-uri reale, existente și nerevocate și verifică apartenența la comanda autorizată. API-ul de înregistrare și integrarea emailurilor sunt documentate în `docs/ORDER_TRACKING.md`; nu a fost inventată sau emisă o factură și nu a fost configurat un furnizor de facturare.
- Browserul intern a verificat formularul, comanda demo, imaginile, timeline-ul și rezumatul la 1440 px, 768×1024, 390×844 și 320×740, fără depășiri orizontale observate sau erori în consolă. Migrarea SQLite este aplicată local. Au trecut lint PHP, sintaxa JS, 77 template-uri Twig compilate, PHPStan, `git diff --check` și întreaga suită PHPUnit: **152 teste / 1911 aserțiuni**. Testele includ tranziții reale în baza izolată, conversia orei, idempotenta webhookului, accesul prin formular/link, expirarea/revocarea accesului, facturile și limitarea încercărilor.
- Nu s-au modificat comenzile reale/demo prin verificarea vizuală, nu s-au trimis emailuri și nu s-a făcut deploy. Livrarea SMTP, integrarea de facturare, MySQL/Apache și Stripe live rămân de verificat în mediul țintă.

### 2026-11-07 — Produse comandate compacte și zoom responsive
- Cardurile produselor comandate folosesc acum fotografia variantei, apoi fotografia produsului și, când acestea lipsesc, aceeași imagine de categorie folosită de catalog. Miniatura are o acțiune de zoom vizibilă și accesibilă; viewerul mărit păstrează fotografia întreagă, buton de închidere clar și restituirea focusului către miniatură.
- Acțiunea „Retur” a fost eliminată din antetul comenzii; fluxul complet rămâne disponibil numai în secțiunea de acțiuni rapide. Rândurile produselor sunt mai compacte, iar cantitatea, identitatea, SKU-ul, legătura către produs și prețul formează o singură compoziție. Prețul rămâne în dreapta inclusiv pe telefon, iar cifrele folosesc o greutate mai moderată și o tipografie sans-serif lizibilă.
- Viewerul foto nu mai folosește rama sau fundalul întunecat. Fotografia plutește peste un backdrop luminos, transparent și blurat, iar titlul și butonul de închidere sunt capsule albe translucide cu efect glass; ambele rămân ancorate de cadrul real al fotografiei pe desktop și pe telefon.
- Deschiderea viewerului este acum secvențială: backdropul capătă blur, fotografia intră printr-un zoom discret, apoi apar capsula titlului și butonul de închidere. Închiderea folosește o tranziție mai scurtă și redă focusul miniaturii numai după terminarea ei; preferința `prefers-reduced-motion` elimină mișcarea.
- Browserul intern a confirmat fotografia, deschiderea/închiderea zoomului și layoutul la lățime desktop și 390×844 px, fără tăierea imaginii sau ieșirea prețului din card. Au trecut lint-ul PHP, sintaxa JavaScript, compilarea celor 76 template-uri Twig, PHPStan, `git diff --check`, testul dedicat cu 3 teste / 54 aserțiuni și întreaga suită PHPUnit cu 144 teste / 1826 aserțiuni. Nu s-au schimbat comenzi și nu s-au verificat SMTP, Stripe, MySQL ori producția.

### 2026-11-07 — Urmărire comandă: compoziție și animații
- Antetul folosește acum suprafețe translucide, reflexii discrete, un colet plutitor cu orbită animată și un card al comenzii care reacționează fin la pointer pe desktop. Fundalul are pete de culoare în mișcare lentă; „Descoperă parcursul” derulează către timeline și transferă focusul accesibil.
- Fiecare etapă are propriul card, iar starea curentă este evidențiată în roz închis, cu marker animat și dată păstrată. Legăturile etapelor finalizate au o animație de progres; pe telefon, traseul și cardurile sunt verticale. Produsele, rezumatul, totalul și cardul de ajutor au spațiere, contrast și umbre rafinate.
- Cardurile aflate inițial sub ecran apar treptat la derulare prin IntersectionObserver. HTML-ul rămâne vizibil și utilizabil fără JavaScript. Efectul de adâncime folosește requestAnimationFrame numai la mișcarea pointerului, iar animațiile decorative se pun pe pauză când fila este ascunsă. Butonul de oprire/pornire păstrează preferința locală; `prefers-reduced-motion` elimină mișcarea.
- Verificat în browserul intern la 1440×1180, 768×1024, 390×844 și 320×740: layout fără overflow orizontal, lizibilitatea timeline-ului, apariția cardurilor, derularea către parcurs, oprirea și repornirea animațiilor. Transformarea orbitei se schimbă între citiri, iar consola nu raportează erori. Au trecut compilarea celor 77 template-uri, sintaxa JavaScript, `git diff --check` și `OrderTrackingTest`: 8 teste / 83 aserțiuni. Nu s-au modificat datele comenzilor, nu s-au trimis emailuri și nu s-a făcut deploy în producție.

### 2026-11-07 — Rezumatul comenzii ca bon animat
- Cardul de referință din antet a fost transformat într-un bon cu logo, textură discretă de hârtie, margine perforată, separator punctat și total evidențiat. Data, ora și numărul de bucăți provin din comandă; semnătura folosește inima standard a magazinului. Modelul de bare este pur decorativ, fără cod de scanare sau funcție fiscală.
- La deschidere, hârtia se desfășoară dintr-o fantă cu o mișcare lină, apoi apare semnătura. Efectele respectă oprirea animațiilor și preferința pentru mișcare redusă. Aspectul rămâne complet vizibil fără JavaScript.
- Verificat în browserul intern la 1440×1100, 768×1024, 390×844 și 320×740: bon și total încadrate, fără depășiri orizontale. Animația de ieșire a fost observată până la poziția finală, iar consola nu a raportat erori. Au trecut compilarea celor 77 template-uri, `OrderTrackingTest` cu 8 teste / 83 aserțiuni și `git diff --check`. Nu s-au schimbat date, nu s-au trimis emailuri și nu s-a făcut deploy.

### 2026-11-07 — Bon compact pe telefon
- Bonul are acum logo și etichetă pe același rând, data și ora grupate și un footer pe două coloane. Spațierile sunt reduse, fără eliminarea datelor comenzii; animația și marginea perforată sunt păstrate. Lățimea maximă este 270 px pe desktop și 260 px pe telefon, iar înălțimea măsurată pe telefon este aproximativ 285 px față de aproximativ 440 px anterior.
- Verificat vizual în browserul intern la 1440×1000, 768×1024, 390×844 și 320×740: informațiile și totalul rămân încadrate, fără overflow orizontal și fără erori în consolă. Au trecut compilarea celor 77 template-uri Twig și `git diff --check`. Schimbarea privește numai prezentarea; nu s-au modificat comenzi sau trimis emailuri.

### 2026-11-07 — Antet mobil compact și status imediat vizibil
- Pe telefon, titlul și spațierile antetului sunt reduse, textul introductiv păstrează numai salutul, iar statusul curent apare într-o capsulă lângă acesta. Textele decorative și legătura către parcurs nu mai ocupă spațiu pe mobil. Bonul animat și informațiile sale rămân vizibile; aspectul pentru tabletă și desktop este păstrat.
- Secțiunea parcursului este vizibilă imediat pe telefon, fără a aștepta pragul animației de apariție. La 320×740 px, titlul statusului din parcurs se încadrează în primul ecran; capsula superioară se vede și pe ecranul mai scund de 375×667 px.
- Verificat în browserul intern la 320×740, 375×667, 390×844, 768×1024 și 1440×1000: fără overflow orizontal sau erori în consolă. Au trecut compilarea celor 77 template-uri Twig și `git diff --check`. Nu s-au schimbat datele comenzilor și nu s-au trimis emailuri.

### 2026-11-07 — Solicitări de anulare din urmărirea comenzii
- Sub cardul de livrare apare discret „Solicită anularea comenzii”, numai pentru comenzile noi, confirmate sau în pregătire, fără cerere anterioară și plasate cu mai puțin de 120 de ore în urmă. Exact la 5 zile termenul este închis; calculul UTC este independent de schimbarea orei. În browser se dezactivează și formularul rămas deschis la expirare, iar serverul reverifică timpul și statusul în tranzacție.
- Modalul solicită un motiv de 5–1.000 de caractere, arată termenul exact și explică faptul că se înregistrează o cerere. Antetul și acțiunile rămân în afara scrollului conținutului pe telefon. Formularul funcționează și ca pagină fără JavaScript; CSRF, sesiunea de urmărire și limitarea încercărilor sunt obligatorii. Reîncercările nu creează cereri duplicate.
- Migrarea nouă `034_order_cancellation_requests.sql` există pentru SQLite și MySQL, cu legătură unică la comandă, motiv, status inițial și dată. Cererea și auditul se scriu atomic. CRM-ul arată notificarea, marcajul din tabel, filtrul dedicat și detaliile solicitării. Nu sunt schimbate automat comanda, plata, stocul sau cupoanele. Integrarea viitoare pentru decizie, rambursare și fiscalitate este documentată în `ORDER_TRACKING.md`.
- Au trecut lint PHP, 79 template-uri Twig compilate, sintaxa JavaScript, PHPStan fără erori, `git diff --check` și întreaga suită PHPUnit: **160 teste / 2027 aserțiuni**. Testele noi verifică limita la secundă, schimbarea orei, stările interzise, formulare expirate, autorizarea, CSRF, motivul, idempotenta, lipsa efectelor financiare/de stoc, CRM-ul și notificările.
- Browserul intern a verificat modalul la 1440×1000, 768×1024, 390×844 și 320×640, inclusiv scrollul numai în corp, motivul obligatoriu, trimiterea, confirmarea, filtrul CRM, motivul în pagina comenzii și notificarea. Un termen apropiat în baza QA a confirmat dezactivarea formularului și dispariția linkului la expirare. Fără erori în consolă. Datele de verificare au fost create exclusiv într-o bază SQLite QA separată; comenzile existente nu au fost modificate și nu s-au trimis emailuri.
- Migrarea SQLite este aplicată local. MySQL și concurența sa reală, Apache, SMTP, rambursările Stripe și integrările fiscale nu au fost verificate sau activate în această intervenție. Nu s-a făcut deploy în producție.

### 2026-11-07 — Eliminarea controlului de animații
- Eliminat butonul „Animații active”, stilurile și logica lui din urmărirea comenzii. Animațiile continuă automat, respectând preferința dispozitivului pentru mișcare redusă și pauza când fila este ascunsă.
- Au trecut compilarea celor 79 template-uri Twig, verificarea sintaxei JavaScript și `git diff --check`. Răspunsul HTTP local nu mai conține controlul de animații.

### 2026-11-07 — Configurare privată și verificare autentificare SMTP
- Cu aprobarea explicită a utilizatorului, configurația locală privată `.env` folosește acum SMTP cu TLS implicit pe portul 465, serverul `mail.la-mariuca.ro` și expeditorul `contact@la-mariuca.ro`, cu numele „La Măriuca Boutique”. Datele de autentificare rămân exclusiv în fișierul ignorat de Git; nu sunt incluse în documentație sau surse publice.
- Verificarea reală prin Symfony Mailer a confirmat conexiunea TLS cu validarea certificatului și a numelui serverului, apoi autentificarea SMTP (răspuns 235). Testul a efectuat numai conectare/autentificare/deconectare, fără trimitere de mesaje. Stările, numărul de încercări și datele trimiterii din outbox au rămas neschimbate.
- `APP_ENV` rămâne `development`, astfel încât procesarea locală obișnuită a outboxului păstrează preview-urile în fișiere. Nu s-a procesat mesajul existent în așteptare și nu s-a făcut deploy. Livrarea efectivă la destinatar, SPF/DKIM/DMARC și execuția cron pe hosting nu au fost verificate.

### 2026-11-07 — Emailuri de comandă responsive și termen estimat
- Confirmarea folosește acum un template HTML cu logo-ul original, fonturi sans-serif, carduri rotunjite separate, produse fotografiate, cantitate/preț unitar/total, reduceri, livrare, telefon, metodă și stare de plată. Salutul folosește prenumele din comandă. CTA „Urmărește comanda” deschide direct comanda prin linkul privat existent; există și alternativă text. Expeditorul folosește numele magazinului, cu Reply-To la contact.
- Designul a fost revizuit după feedback: structură mai modernă, produs cu fotografie/nume sus și prețuri dedesubt pe telefon, logo original, rezumat cu economie evidențiată și card separat pentru total; lățime desktop de 760 px și texte mărite. HTML-ul folosește tabele și stiluri inline, media queries și CTA VML pentru Outlook; fotografiile WebP sunt transformate în miniaturi JPEG fără a modifica sursele.
- Rambursul generează mesajul de comandă primită; cardul generează confirmarea numai după webhookul de plată valid, cu eticheta „Card online — Plătit”. Repetările aceluiași eveniment și evenimentele distincte de succes nu dublează confirmarea. Cardul neplătit și comenzile anulate/returnate nu primesc o confirmare falsă. Plata tardivă intrată în verificare folosește un mesaj separat, fără a promite confirmarea comenzii.
- Migrarea 035 salvează estimarea metodei de livrare în comandă și a fost aplicată pe SQLite local. `DeliveryEstimateProvider` permite ulterior calcul dinamic fără modificarea template-ului; implementarea curentă afișează zilele lucrătoare configurate de la predarea coletului, fără date inventate. Pentru metode fără date cunoscute estimarea nu este afișată.
- Au trecut lint PHP, 81 template-uri Twig, PHPStan, `git diff --check` și suita integrală PHPUnit: **168 teste / 2114 aserțiuni**. După ajustările de design au trecut din nou testele dedicate: **8 teste / 82 aserțiuni**. Acoperirea include totaluri/reduceri, destinatar, escaping, imagini, link privat, texte ramburs/card, plată neconfirmată/tardivă și păstrarea/înlocuirea estimării de livrare.
- Preview-urile au fost verificate în browserul intern la dimensiuni de telefon, tabletă și desktop; CTA a deschis direct comanda fictivă corectă. Verificările folosesc o bază QA izolată și nu trimit emailuri. Randarea în inboxuri reale Gmail/Outlook, dark mode, livrarea SMTP, cronul de hosting, MySQL și Stripe live nu au fost verificate. Nu s-a făcut deploy; configurația locală continuă să folosească preview-uri în fișiere.

### 2026-11-07 — Footer profesional pentru emailurile de comandă
- Footerul este aliniat la stânga, cu trei coloane pe desktop și secțiuni separate pe mobil: contact, informații utile și partener tehnic CAB IT. Include mențiunea de dezvoltare/suport/întreținere solicitată, linkul CAB IT, sigla existentă ANPC–SAL și un sigiliu propriu „Grijă pentru clienți”, fără a inventa o certificare ori evaluări de la clienți.
- Legăturile interne și imaginile se construiesc din URL-ul aplicației, iar varianta text conține aceleași resurse utile. Originalele de branding sunt păstrate. Preview-urile ramburs/card au fost regenerate fără expediere.
- Testele dedicate au trecut din nou: **8 teste / 82 aserțiuni**. Verificat vizual în browser la 320 și 390 px și pe desktop: fără overflow, sigiliul și sigla ANPC încărcate. Verificarea în inboxuri reale Gmail/Outlook rămâne externă acestui preview.

### 2026-11-07 — Sigla CAB IT și footer mobil centrat
- Emailul folosește marca CAB IT exact ca în footerul site-ului, exportată din aceeași regiune a assetului existent într-un PNG separat; sursa nu este modificată. Pe telefon, contactul, informațiile, creditul și ANPC sunt centrate; linkurile utile folosesc capsule compacte, primele două pe același rând inclusiv la 320 px. Desktopul păstrează cele trei coloane aliniate la stânga.
- În footerul magazinului, sigiliul „Grijă pentru clienți” înlocuiește cercul decorativ ©. Simbolul © este acum text înaintea anului dinamic. Sigiliul are 64 px pe desktop și 56 px pe telefon.
- Au fost regenerate ambele emailuri fără expediere, compilate 81 de template-uri Twig și verificat `git diff --check`. Browserul a confirmat sigla încărcată și lipsa overflowului în email la 320/390 px și desktop, precum și sigiliul și textul copyright din magazin la 390 px și desktop. Randarea în inboxuri reale nu a fost testată.

### 2026-11-07 — Note informative și copyright în email
- Originea emailului și caracterul privat al linkului sunt prezentate într-un bloc informativ discret, cu titluri, marcaje și separator. Destinatarul este afișat pe rând propriu, fără `word-break:break-all`, evitând fragmentarea inutilă a adresei pe telefon.
- Copyrightul este separat de note, cu inima standard a magazinului, anul dinamic și denumirea La Măriuca Boutique evidențiată. Notele au text de 14 px pe mobil și 15 px pe desktop.
- Preview-urile ramburs/card au fost regenerate fără trimitere. Verificat în browser la 320 px și desktop 1440 px: fără overflow, adresa demonstrativă pe un rând și imaginea inimii încărcată. Au trecut cele **7 teste / 77 aserțiuni** pentru emailurile de confirmare și `git diff --check`. Inboxurile reale Gmail/Outlook rămân neverificate.

### 2026-11-07 — Expediere reală a confirmărilor de comandă
- După autorizare explicită, configurația locală folosește SMTP pentru confirmările noi de comandă. Pragul de activare exclude mesajele istorice; filtrul permite numai `order_confirmation`. Rambursul se trimite după plasare, iar cardul numai după confirmarea validă a procesatorului. Configurația și acreditările rămân private.
- `OutboxProcessor` separă expedierea SMTP de preview, revendică atomic mesajele și aplică reîncercări limitate. Nu deschide SMTP într-o tranzacție DB. Erorile nu expun DSN/acreditări; dacă SMTP acceptă mesajul dar actualizarea DB eșuează, acesta rămâne pentru verificare manuală, fără retrimitere automată. Imaginile locale sunt atașate inline prin CID.
- Procesorul local rulează în fundal, verificând coada la 5 secunde; `bin/start-mail-worker.ps1` permite repornirea lui. Nu este instalat ca serviciu/autostart Windows. Mediul `testing` rămâne întotdeauna în preview.
- Emailul de test solicitat a fost acceptat efectiv de SMTP (1 trimis, 0 erori), cu marcaj TEST și date fictive din baza QA izolată. Nu a fost verificată sosirea în Inbox/Spam Yahoo ori randarea în Gmail/Outlook; linkul de urmărire din test folosește serverul local QA. Nu s-a făcut deploy sau test Stripe live.
- Suita completă anterioară a trecut: **197 teste / 2226 aserțiuni**. Regresia suplimentară pentru eșec DB după acceptarea SMTP a trecut: **6 teste / 85 aserțiuni** pentru procesor. PHPStan, lint, 81 template-uri Twig și diff check au trecut. MySQL/concurența de hosting nu au fost executate.

### 2026-11-07 — Card și ramburs configurabile pe categorii
- Migrarea 036 (SQLite și MySQL) adaugă metodele permise pe categorie, ambele activate implicit. Pasul nou „Plată” din adăugare/editare are două carduri switch responsive, stare explicită și explicații despre moștenire. Ultima metodă nu poate fi oprită accidental; serverul respinge și cererile cu ambele dezactivate. Formularele legacy păstrează valorile existente.
- Politica include categoria principală, toate asocierile produsului și toți ascendenții. Cardul are prioritate în conflicte; cardul global indisponibil permite fallback la ramburs dacă acesta este activ global. Checkoutul arată metodele indisponibile, motivul și lista produselor afectate. Nicio metodă disponibilă înseamnă plasare blocată.
- La trimitere, eligibilitatea se recalculează în tranzacție înaintea comenzilor, stocului, cupoanelor și emailurilor. Testele verifică cereri falsificate și modificarea regulilor după deschiderea checkoutului. Documentația completă și exemplele sunt în `CATEGORY_PAYMENTS.md`.
- Verificarea vizuală a descoperit și corectat o problemă de salvare existentă: verificarea asincronă pornită la ieșirea din câmpul nume nu mai blochează submitul; validarea finală rămâne pe server.
- Migrarea a fost aplicată pe SQLite local. Au trecut **231 teste / 2516 aserțiuni**, lint PHP, PHPStan, compilarea a 81 template-uri Twig, sintaxa JavaScript și `git diff --check`. Browserul intern a verificat adăugare/editare, salvare, protecția ultimului switch și checkout mixt/fallback la 320, 390, 768 și 1440 px, fără overflow sau erori JS. Testele au folosit baze izolate, fără expediere SMTP sau plăți Stripe; nu s-a făcut deploy și MySQL/concurența live rămân neverificate.

### 2026-11-08 — Fallback simetric și descriere obligatorie
- După clarificare, excepția globală funcționează în ambele sensuri: dacă rambursul este dezactivat, cardul disponibil și configurat poate fi folosit inclusiv pentru produse numai ramburs. Prioritatea cardului rămâne valabilă când ambele metode sunt active global; ambele oprite sau datele invalide blochează în continuare checkoutul. Mesajele din checkout și CRM au fost actualizate.
- Descrierea scurtă a categoriei este obligatorie în creare/editare, în HTML și pe server, cu maximum 100 de caractere. Sunt respinse și valorile doar din spații Unicode; fotografia rămâne opțională. Testele verifică limita 100/101, cereri goale și păstrarea datelor după respingere.
- În browserul intern, cu bază QA separată, s-au verificat fallbackul COD→card (card selectat, ramburs dezactivat) și câmpul obligatoriu pe telefon. Suita completă a trecut: **240 teste / 2667 aserțiuni**, plus PHPStan, lint și 81 template-uri Twig. Fără plăți Stripe sau emailuri reale pentru aceste verificări.

### 2026-11-08 — Pictograme de email și economii animate
- Emailurile păstrează cercul de status, cu pictograme PNG distincte și mai lizibile: bifă pentru card confirmat, sacoșă cu bifă pentru comandă primită și ceas pentru plata în verificare. Asseturile au densitate triplă și un generator PHP reproductibil.
- Înaintea mesajului „Ai economisit” apare o iconiță confetti animată, cu 12 cadre și două redări de câte două secunde. Prima și ultima imagine rămân complete și lizibile dacă aplicația de email afișează numai un cadru. Iconița și textul apar numai când reducerile persistate de produse/livrare au o sumă pozitivă; suma este dinamică, inclusiv când există numai reducere la livrare.
- Testele pentru email și procesor au trecut: **15 teste / 186 aserțiuni**, suplimentar suitei complete de **240 teste / 2667 aserțiuni** de mai sus. Un test nou verifică păstrarea exactă a GIF-ului în atașamentul MIME inline și referința CID, fără pierderea animației. Lint PHP, PHPStan, 81 template-uri Twig și `git diff --check` au trecut.
- Preview-urile au fost verificate în browserul intern la 320, 390 și 1440 px, fără overflow orizontal; pictogramele de status și mesajul economiei sunt vizibile. Nu s-au trimis emailuri reale pentru aceste verificări; randarea în inboxuri Gmail/Outlook rămâne neverificată. Nu s-a făcut deploy.

### 2026-11-08 — Destinatari pentru notificările interne de comandă
- Migrarea 037 SQLite/MySQL introduce setări comune magazinului, inițial fără destinatari, și deduplicare per comandă/adresă. Serviciul validează maximum 10 adrese, normalizează și elimină duplicatele, salvează atomic și auditează numărul destinatarilor. Lista goală oprește numai emailurile interne noi; modificările nu retrimit istoricul.
- La plasarea rambursului și la prima confirmare Stripe validată se adaugă câte un mesaj intern separat per adresă, în aceeași tranzacție cu evenimentul comenzii. Mesajele clientului și notificările push existente se păstrează. Destinatarii deja puși în coadă nu sunt redirecționați la schimbarea setărilor.
- Au trecut **15 teste noi / 67 aserțiuni**, **25 teste de regresie / 281 aserțiuni** și PHPStan. Acoperirea include rollback atomic, autorizare, limite, deduplicare, card neplătit, semnături/sume invalide, evenimente Stripe repetate și plata tardivă. Testele sunt izolate, fără SMTP/Stripe/push reale. Concurența MySQL reală nu a fost executată.

### 2026-11-08 — Email intern cu designul confirmării clientului
- Emailul intern folosește designul comun al confirmării: logo, titlu cu valoarea comenzii, CTA „Deschide comanda”, număr și dată/oră, produse fotografiate, rezumat, apoi plată și livrare. Rămâne doar copyrightul după livrare. CTA este un URL administrativ autentificat, fără tokenul privat al clientului. Datele de prezentare și secțiunile comune sunt reutilizate; emailul clientului își păstrează conținutul și footerul.
- Procesorul acceptă tipul intern, cu câte un destinatar per mesaj și imagini inline CID. Un eșec SMTP simulat la o adresă nu blochează ceilalți destinatari ori clientul; reîncercarea nu retrimite mesajele reușite.
- Au trecut **23 teste / 299 aserțiuni** pentru emailuri și procesor, PHPStan, lint și compilarea a 88 template-uri Twig. Preview-ul intern a fost verificat în browser la 320, 390, 768 px și desktop: fără overflow, imagini încărcate, ordine corectă a secțiunilor și fără footerul comercial. Nu s-au trimis emailuri reale pentru QA și nu s-au verificat inboxurile Gmail/Outlook.

### 2026-11-08 — Modal pentru administrarea adreselor interne
- Rotița de setări stă în stânga contorului de comenzi pe desktop și în dreptul titlului „Toate comenzile” pe mobil. Modalul are header/footer fixe, scroll doar în conținut, adrese editabile, adăugare/eliminare, contor și validare. „Renunță”, Escape și închiderea păstrează lista salvată. Salvarea reușită închide modalul, apoi afișează confirmarea animată „Setări salvate”; erorile păstrează datele introduse. Fără JavaScript există formular complet, cu CSRF.
- Testele HTTP verifică autentificarea, CSRF, adăugarea/editarea/ștergerea, respingerea adreselor invalide fără mutație parțială și formularul fără JS: **3 teste / 30 aserțiuni**. Browserul intern a verificat 320/390/768/1440 px, poziția rotiței, lista reîncărcată după renunțare, confirmarea de salvare și lipsa erorilor JS. QA folosește o bază separată cu adrese fictive; baza principală nu a primit destinatari de test.
- Verificarea integrată a trecut: **279 teste / 2930 aserțiuni**, PHPStan, lint PHP, sintaxa JS, 88 template-uri Twig și diff check.

### 2026-11-08 — Deschiderea comenzii după login și activare locală
- Linkul din email păstrează comanda vizată când administratorul trebuie să se autentifice, inclusiv prin TOTP. Se memorează numai ID-ul numeric și calea de bază, timp de 15 minute; destinația este folosită o singură dată, iar logoutul o șterge. Nu sunt acceptate redirecturi externe. Au trecut **12 teste / 38 aserțiuni**, plus regresiile TOTP și AdminPanel, incluse și în suita completă de mai sus.
- Migrarea 037 este aplicată pe SQLite local. În configurația privată, filtrul procesorului SMTP permite acum `order_confirmation` și `internal_order_notification`, păstrând pragul anterior și acreditările. Procesorul local a fost repornit, pornește în mod SMTP și are log de eroare gol. Lista reală rămâne goală până la configurarea din modal; nu se trimit notificări istorice.
- Push-ul existent este configurat și are două abonamente active; codul de trimitere este păstrat, dar livrarea efectivă către dispozitive nu a fost retestată. Nu s-au trimis emailuri reale pentru această verificare, nu s-a făcut deploy și nu s-au verificat MySQL/Apache ori Stripe live.

### 2026-11-08 — Copiere rapidă a adresei de livrare
- Pagina administrativă a comenzii afișează separat județul, localitatea, strada și numărul, respectiv codul poștal. Fiecare valoare are un buton cu pictogramă și nume accesibil; câmpurile lipsă rămân vizibile, cu buton dezactivat. Fără JavaScript adresa rămâne selectabilă.
- Copierea folosește Clipboard API cu fallback pentru browserele compatibile. Confirmarea apare în partea de sus, începe retragerea după 1700 ms și respectă preferința pentru mișcare redusă. Clicurile repetate înlocuiesc mesajul anterior; un eșec de copiere nu afișează succes.
- Verificat în browserul intern pe desktop și la 390 px: cele patru câmpuri, confirmările, butoane mobile de 44 px și lipsa overflowului. Au trecut testele OrderAdminWorkflow (**3 teste / 54 aserțiuni**), compilarea a 88 template-uri Twig și sintaxa JavaScript. Nu s-au modificat datele comenzilor.

### 2026-11-08 — Setări globale pentru metodele de plată
- Tabul **Metode de plată**, sub Livrare, administrează Ramburs și Card online. Migrarea 038 SQLite/MySQL păstrează inițial valorile existente din configurație; prima salvare mută controlul în CRM. Salvarea este autorizată, protejată CSRF, tranzacțională și auditată. Cheile Stripe rămân private; opțiunea card nu devine disponibilă fără configurarea procesatorului.
- Checkoutul ascunde complet metodele indisponibile global. Metodele active global, dar restricționate de produse, păstrează motivul și lista produselor afectate. Sunt păstrate regulile pe categorii și ascendenți, prioritatea cardului și fallbackul simetric. Setările globale nu rescriu switchurile categoriilor. Disponibilitatea se recitește în tranzacția de plasare, inclusiv dacă setările s-au schimbat după deschiderea paginii.
- Inițierea Stripe folosește setările CRM, iar o verificare veche bazată exclusiv pe ENV a fost eliminată. Webhookurile semnate continuă confirmarea plăților deja inițiate după dezactivarea cardului. Nu se modifică metoda comenzilor istorice sau condițiile emailurilor clientului/interne.
- Migrarea este aplicată local pe SQLite. Au trecut **305 teste / 3152 aserțiuni**, PHPStan, lint PHP, 89 template-uri Twig și diff check. Sunt acoperite autorizarea/CSRF, persistarea, cele 17 combinații de reguli, schimbarea globală între GET/POST, rollback fără stoc/email modificat și plata Stripe confirmată după oprire. MySQL concurent și Stripe live nu au fost executate.

### 2026-11-08 — Switchuri cu salvare automată și header adaptat
- Pagina are două carduri explicative, pictograme și switchuri animate, cu aspect adaptat la telefon, tabletă și desktop. Headerul folosește un panou rotunjit, fundal discret și contor integrat, în stilul celorlalte pagini administrative. Preferința pentru mișcare redusă și utilizarea din tastatură sunt păstrate.
- Conform ultimei cerințe, toggle-ul salvează silențios, fără refresh, bară de salvare sau mesaj de succes. Cererile sunt serializate și păstrează ultima intenție în cazul comutărilor rapide. Un eșec restaurează ultima stare confirmată și afișează numai eroarea, deasupra opțiunilor. Formularul fără JavaScript are submit în `noscript`.
- Browserul intern a verificat salvarea/reîncărcarea, comutări rapide și dispariția alternativă a cardului/rambursului din checkout, pe o bază QA izolată. Designul a fost inspectat la 320/390/768 px și desktop 1280/1440 px, fără overflow orizontal; pagina principală locală a fost verificată fără schimbarea setărilor reale. Nu s-au plasat comenzi și nu s-au efectuat plăți ori expedieri email reale în QA.
- După ajustarea salvării automate au trecut din nou **34 teste / 278 aserțiuni** relevante și 89 template-uri Twig. Verificarea Node a acoperit cererile serializate, ultima alegere, revenirea unui toggle, rollback la eroare și retry. Capturile de verificare sunt păstrate privat în `storage/qa/`.

### 2026-11-08 — Regresie explicită pentru același coș mixt
- Un test nou păstrează același coș cu un produs numai card și unul numai ramburs, schimbând setarea persistentă a magazinului. Cu ambele metode active, cardul rămâne prioritar; după dezactivarea globală a cardului, rambursul este selectat și cardul dispare. Regulile categoriilor și produsele din coș nu sunt rescrise.
- Cererile falsificate pentru metoda nepermisă sunt respinse înaintea modificării stocului, comenzilor, cupoanelor și emailurilor. Plasarea rambursului este verificată numai în testul izolat. Testul nou a trecut cu **28 aserțiuni**, iar regresiile relevante cu **62 teste / 547 aserțiuni**.
- Browserul intern a verificat același scenariu folosind switchul real din CRM pe o bază QA separată, inclusiv regula de card moștenită din categoria părinte. Setările bazei principale au rămas neschimbate; nu s-au făcut plăți Stripe sau trimiteri SMTP reale.

### 2026-11-08 — Copierea contactului și inima standard în checkout
- Numele clientului, emailul și telefonul au butoane de copiere în pagina administrativă a comenzii. Folosesc aceeași confirmare discretă de 1700 ms ca adresa; valorile lipsă au buton dezactivat, iar legăturile email/telefon sunt păstrate. Butoanele sunt ascunse fără JavaScript și au ținte de 44 px pe telefon.
- Rezumatul checkoutului folosește imaginea existentă cu inimile standard ale magazinului, dimensionată la 36 × 29 px, fără modificarea assetului sursă.
- Verificate vizual pe desktop și la 390 px: toate cele trei confirmări de copiere, alinierea butoanelor, încărcarea inimii și lipsa overflowului orizontal. Testele OrderAdminWorkflow (**3 teste / 54 aserțiuni**), sintaxa JavaScript și compilarea a **89 template-uri Twig** au trecut. Datele comenzilor nu au fost modificate.

### 2026-11-08 — Utilizări valide și totalul reducerilor pe ofertă
- Registrul ofertelor afișează „Total reduceri”, însumat din valorile efectiv salvate pe comenzi. Utilizările includ comenzile ramburs și card plătit, cu reducere pozitivă. Cardurile în așteptare apar separat; comenzile anulate, retururile integrale și plățile eșuate/rambursate sunt excluse. Returul parțial păstrează reducerea acordată inițial; indicatorul nu reprezintă valoarea contabilă netă după rambursări.
- Migrarea 039 SQLite/MySQL adaugă snapshoturi separate pentru reducerile de produse/livrare ale cuponului. Recuperarea istoricului exclude componentele atribuite promoțiilor automate sau reducerilor personale. Editarea ulterioară a unei oferte nu schimbă statisticile istorice. Aceeași ofertă aplicată produselor și livrării este numărată o dată pe comandă, cu ambele sume adunate. Limitele cupoanelor păstrează calculul existent cu rezervări și confirmări.
- Migrarea este aplicată pe SQLite local. Auditul numai în citire a bazei principale a găsit 31 comenzi, niciuna asociată unei promoții și niciuna cu reducere de livrare; oferta existentă „Livrare gratuita” are justificat 0 utilizări și 0,00 Lei. Datele de comandă principale nu au fost completate prin presupuneri.
- Browserul intern a verificat tabelul desktop și cardurile la 390 px, fără overflow orizontal. Fixture-urile exclusiv QA afișează corect 3 utilizări / 57,00 Lei, 2 / 127,50 Lei și 2 / 50,00 Lei, fiecare cu o plată în așteptare separat. Eticheta accesibilă a căutării rămâne disponibilă și când titlul vizual este ascuns pe mobil.
- Au trecut **312 teste / 3224 aserțiuni**, PHPStan, lint PHP, **89 template-uri Twig** și diff check. Cele 6 teste noi acoperă snapshot/backfill, atribuiri mixte, plăți semnate și repetate fără dublare, expirare, anulare și retur. Nu s-au făcut plăți sau expedieri externe pentru QA; MySQL și concurența pe hosting nu au fost executate. Nu s-a făcut deploy.

### 2026-11-08 — Cereri de anulare, decizii și notificări
- Migrarea 040 SQLite/MySQL adaugă stările pending/accepted/rejected, motivul deciziei, administratorul și momentul răspunsului. Cererile istorice rămân în așteptare, fără notificări retroactive. Regula inițială rămâne strict sub cinci zile și cel mult în pregătire; administratorul poate procesa ulterior o cerere trimisă la timp.
- Prima solicitare creează atomic un email de primire pentru client, câte un email separat pentru fiecare adresă internă salvată și notificarea push pentru dispozitivele eligibile. Retrimiterea aceleiași solicitări nu dublează mesajele. Destinatarii sunt fixați la momentul cererii; lista goală nu blochează confirmarea clientului.
- Acceptarea anulează comanda și eliberează rezervările de stoc/cupon în aceeași tranzacție cu decizia și emailul clientului. Plata rămâne neschimbată; nu sunt inițiate rambursări Stripe sau operațiuni fiscale. Respingerea cere motiv de 5–1.000 de caractere și păstrează comanda. Deciziile sunt definitive, reîncercările identice nu dublează efectele, iar o decizie opusă este respinsă. Erorile de stoc sau de coadă anulează tranzacția complet.
- În admin apar modale distincte Acceptă/Respinge, cu header/footer fixe, scroll doar în conținut, motiv și avertizare explicită pentru cardul plătit. Formularele funcționează și fără JS, cu autorizare și CSRF. Cererile rezolvate ies din filtrul cererilor în așteptare; istoricul și pagina privată de urmărire afișează răspunsul și data/ora, fără identitatea administratorului către client.
- Cele trei tipuri de email reutilizează designul comun: produse, imagini disponibile, SKU, cantități, sume, reduceri, plată și livrare. Clientul păstrează footerul complet; emailul intern are numai copyright și CTA „Vezi solicitarea de anulare”. Decizia arată motivul și starea reală a plății, fără promisiuni de rambursare efectuată sau confetti de economisire.
- Au trecut **333 teste / 3458 aserțiuni**, PHPStan, lint PHP, sintaxa JS, **93 template-uri Twig** și verificarea diff. Testele acoperă și izolarea destinatarilor, transportul MIME cu imagini inline, retry fără retrimiterea mesajelor reușite, rollback, autorizare/CSRF și preferințele push. Browserul intern a verificat solicitarea, acceptarea ramburs/card, respingerea și linkul privat din email, pe o bază QA separată; design verificat la 320/390/768 px și desktop. Capturile rămân private în `storage/qa/`.
- Migrarea 040 este aplicată pe SQLite local. Filtrul SMTP privat permite acum și cele trei tipuri de anulare, păstrând pragul anterior și acreditările; procesorul local rulează la cinci secunde, fără erori. Lista reală de destinatari interni este încă goală și trebuie completată din setările comenzilor. Două dispozitive push sunt eligibile. Nu s-au trimis emailuri/push reale pentru QA, nu s-au verificat inboxurile Gmail/Outlook ori concurența MySQL și nu s-a făcut deploy.

### 2026-11-08 — Previzualizări de anulare și ajustare pentru ecrane mici
- Emailurile de primire, acceptare, respingere și notificare internă au fost deschise în file separate ale browserului intern, cu date QA. Serverul local de previzualizare rămâne disponibil pentru inspectare; nu se expediază mesaje din această bază.
- La 320 px, statusul din contextul modalului comprima numele și suma. Layoutul mobil folosește acum două coloane, cu statusul pe rând separat sub datele clientului; headerul și acțiunile rămân fixe. Verificat vizual la 320 și 768 px, plus emailurile pe mobil și desktop, inclusiv rândurile produselor, totalul și eticheta lungă a plății. Nu s-a schimbat logica de anulare sau expediere.

### 2026-11-08 — Iconițe lizibile în rezumat, plată și livrare
- Simbolurile vechi, prea mici și fine, au fost înlocuite cu PNG-uri de 168 px, desenate reproductibil în `bin/generate-email-detail-icons.php`: vișiniu închis, trasee pline și rotunjite, suprafață vizibilă mai mare. Bonul din rezumat este distinct de bancnotă, card și camion; rezumatul afișează iconița la 38 px. URL-urile comune se generează din `OrderEmailViewData`, pentru confirmări, mesaje interne și anulări.
- Fișierele noi sunt versionate în nume, păstrând imaginile vechi și compatibilitatea atașamentelor CID. Au trecut **32 teste email/outbox / 437 aserțiuni**, PHPStan, sintaxa generatorului și **93 template-uri Twig**. Verificat vizual pe desktop, la 390 și 320 px; previzualizările deschise au fost actualizate, fără emailuri reale de test. Randarea în inboxuri Gmail/Outlook nu a fost retestată.

### 2026-11-08 — CTA-uri publice și notificări push interne
- Toate cele opt variante de email au fost verificate cu domeniul `https://la-mariuca.ro`, atât la rădăcină, cât și într-un subdirector. CTA-urile, variantele Outlook VML, footerul și imaginile folosesc configurația publică, ignorând hostul cererii. Linkul privat deschide comanda corectă fără login de client; linkul intern cere autentificare administrativă. Verificările sunt HTTP în procesul de test, fără accesarea domeniului live.
- În producție, politica SMTP refuză `APP_URL` local, fără HTTPS sau malformat înainte de preluarea mesajelor. După corectarea configurației, mesajele rămân disponibile, fără încercări consumate. Dezvoltarea și preview-urile locale rămân funcționale. Instrucțiunile de hosting documentează domeniul, calea de bază, repornirea procesorului și păstrarea cheii pentru linkurile private.
- Push-ul pentru comenzi și solicitări de anulare folosește căi relative pe originea unde dispozitivul s-a abonat. La expediere se reverifică acum și contul activ, rolul intern și preferința actuală, inclusiv pentru mesaje deja puse în coadă. Cele **8 teste Node** execută handler-ele service workerului real și verifică domeniul public, subdirectorul, ancora cererii și deschiderea/reutilizarea unei ferestre.
- Auditul local numai în citire confirmă push configurat și **două dispozitive interne eligibile**. Abonamentele trebuie activate pe domeniul HTTPS live după mutare; permisiunile localhost nu se transferă. Nu s-au trimis emailuri sau push-uri reale pentru aceste verificări și nu s-a făcut deploy.
- Verificarea completă a trecut: **353 teste PHP / 3707 aserțiuni**, **8 teste Node**, PHPStan, lint PHP, **93 template-uri Twig** și diff check. Testul SMTP folosește exclusiv un transport injectat fără rețea și confirmă reluarea mesajului după corectarea domeniului.
- După extinderea protecției pentru adrese locale numerice și IPv4 mapat în IPv6, au trecut din nou cele **55 teste țintite / 411 aserțiuni** și PHPStan. Checklistul de domeniu/email/push este referit și în `AGENTS.md`, pentru următoarea publicare.

### 2026-11-08 — Imprimantă pentru bonul de urmărire
- Bara bonului are acum o carcasă roz metalizat, fantă adâncită, buză frontală, marcaj gravat, fante fine și LED discret. Umbra de contact și masca de sub carcasă fac hârtia să pară că iese din imprimantă, păstrând conținutul bonului și dimensiunea compactă.
- Animația durează 2,15 secunde, cu două pauze scurte de alimentare și reflexie/LED sincronizate. Efectele noi se încheie după imprimare; regula existentă `prefers-reduced-motion` arată bonul complet fără animație.
- Verificat în browserul intern la 320, 390, 768 și 1280 px, inclusiv începutul, deplasarea și finalul hârtiei; fără overflow orizontal sau erori JS. Pagina de previzualizare deschisă a fost actualizată. Au trecut **26 teste de urmărire/anulare / 277 aserțiuni**, compilarea celor **93 template-uri Twig** și diff check. Capturile și datele de test rămân în `storage/qa/`, fără publicare sau notificări externe.

### 2026-11-08 — Produse accesibile din urmărire și emailurile clientului
- Pagina de urmărire afișează „Deschide produsul”, cu deschidere într-o filă nouă, iconiță și focus vizibil. Butonul rămâne pe un singur rând la 320 px, iar prețul rămâne în dreapta produsului.
- Imaginea și numele sunt linkuri în confirmarea ramburs/card și în cele trei emailuri de anulare ale clientului. Varianta text conține URL-ul produsului; prezentarea și comportamentul emailurilor interne sunt păstrate.
- URL-ul este construit din `APP_URL` + `APP_BASE_PATH` și slugul curent al produsului identificat prin ID. Numele și prețurile cumpărate rămân cele din comandă. Produsele lipsă, draft sau arhivate păstrează detaliile istorice fără link indisponibil.
- Au trecut **16 teste de urmărire / 196 aserțiuni** și **38 teste email/outbox / 743 aserțiuni**, PHPStan, lint PHP și compilarea celor **93 template-uri Twig**. Sunt verificate domeniul public/subdirectorul, destinația HTTP 200, slugul schimbat și produsele indisponibile. Browserul intern a verificat butoanele la 320/390/768 px și desktop, plus click real către produs din urmărire și din fotografia emailului. Preview-urile locale au fost regenerate; nu s-au expediat emailuri reale și nu s-a făcut deploy.

### 2026-11-08 — Linkuri de produs și în emailurile interne
- Fotografia și numele produsului deschid pagina publică într-o filă nouă și în notificările interne de comandă nouă și solicitare de anulare. URL-ul apare și în varianta text; produsele lipsă sau nepublicate păstrează detaliile fără link.
- Cele **5 teste / 344 aserțiuni** verifică toate cele opt variante de email, domeniul public/subdirectorul, ambele linkuri cu `target="_blank"` și `rel="noopener noreferrer"`, plus fallbackurile interne. Cele **93 template-uri Twig** se compilează. În browserul intern, clickul pe numele produsului din notificarea internă a deschis produsul corect într-o filă nouă. Preview-urile locale au fost regenerate, fără trimitere de emailuri reale.

### 2026-11-08 — Timeline animat în istoricul administrativ al comenzii
- Istoricul folosește carduri legate vizual, iconițe și accente distincte pentru plasare, plată, pregătire, livrare, anulare, retur și email. Ultima actualizare este evidențiată, iar motivul apare separat, lizibil și cu escapare HTML. Fiecare eveniment păstrează data/ora Europe/Bucharest și autorul; cererea clientului nu mai este etichetată „Sistem”. Plasarea nu se dublează dacă există și un audit de creare.
- Cardurile, iconițele și firul cronologic apar progresiv la intrarea în ecran; pulsul ultimei etape este limitat. Conținutul rămâne disponibil fără JavaScript, iar preferința pentru mișcare redusă dezactivează animațiile.
- Au trecut **32 teste de administrare/urmărire/anulare / 372 aserțiuni**, PHPStan, sintaxa JavaScript, compilarea celor **94 template-uri Twig** și diff check. Browserul intern a verificat 320/390/768 px și desktop, inclusiv motive și titluri lungi, cerere în așteptare și anulare acceptată, fără overflow orizontal sau erori JS. Datele și capturile sunt exclusiv QA; nu au fost trimise notificări reale.

### 2026-11-08 — Solicitări de anulare mai vizibile
- Cardul în așteptare are accent amber/rose, marcaj „Necesită atenția ta”, iconiță de atenționare cu puls limitat și motiv pe suprafață distinctă. Acceptarea și respingerea au acțiuni clare cu iconițe; toate cele trei stări folosesc un layout adaptiv fără comprimarea titlului pe mobil. Preferința de mișcare redusă este respectată.
- Verificat vizual la 320/390/768 și 1440 px; ambele butoane deschid modalele existente. Cele **32 teste / 372 aserțiuni** au trecut din nou, iar cele **95 template-uri Twig** compilează. Nu s-au modificat regulile de anulare și nu s-au trimis notificări reale.
- Serverul principal `127.0.0.1:8080` a fost repornit. Verificarea numai în citire confirmă administratorul Paula în baza principală; Maria Admin aparține exclusiv bazei QA separate de pe 8084. Conturile și comenzile principale nu au fost modificate.

### 2026-11-08 — Apel și WhatsApp din contactul comenzii
- Telefonul păstrează copierea și legătura existentă, cu două acțiuni suplimentare: „Apelează” și „WhatsApp”. WhatsApp deschide o filă nouă cu numărul internațional, fără parametru de mesaj precompletat. Numerele românești complete se normalizează cu +40, iar prefixele internaționale explicite sunt păstrate; numerele ambigue/incomplete nu generează link WhatsApp.
- Cele **5 teste administrative / 226 aserțiuni** verifică inclusiv 20 de formate/valori de telefon, păstrarea copiei și absența mesajelor predefinite. PHPStan și compilarea celor **95 template-uri Twig** au trecut. Browserul intern a verificat acțiunile la 320/390 și 1440 px, ținte de minimum 44 px pe mobil și lipsa overflowului. Nu au fost inițiate apeluri sau mesaje externe. Captura mobilă folosește date QA.

### 2026-11-08 — Detaliile firmei și gestiuni
- Tab nou în „Stoc și achiziții”, imediat după „Furnizori”. Firmele au denumire legală/comercială, CUI, Registrul Comerțului, sediu social, localitate, județ, cod poștal, țară, contact, website, adresă fizică, zonă deservită, bancă, IBAN, capital social și ștampilă. Fiecare firmă are propriile gestiuni, cu denumire și alegere implicită.
- Adăugarea, editarea și confirmarea ștergerii folosesc modale cu antet și acțiuni fixe; numai conținutul interior se derulează. Salvarea actualizează fișa fără refresh și afișează o confirmare animată. Formularele funcționează și fără JavaScript. Prima firmă și prima gestiune devin implicite; schimbarea și promovarea după ștergere sunt tranzacționale, cu unicitate la nivelul bazei.
- Capitalul se păstrează în bani; IBAN-ul se normalizează și se verifică prin checksum. Ștampilele PNG/JPEG/WebP, maximum 2 MB, se verifică și se reencodează PNG într-un director privat; numai administratorii pot accesa imaginea. Previzualizarea locală folosește data URL compatibil cu politica CSP existentă, fără relaxarea acesteia. Fișierele sursă ale utilizatorului nu sunt modificate.
- Migrarea 041 există pentru SQLite și MySQL. Aplicată pe SQLite local principal după backup privat, fără schimbarea conturilor, comenzilor sau adăugarea unor firme demonstrative; Paula a rămas administratorul existent. Datele și operațiile demonstrative din browser sunt numai în baza QA separată. Schema nouă folosește ID-uri persistente și relația firmă → gestiuni pentru viitoarele NIR-uri, facturi de achiziție și rapoarte. La introducerea acelor documente trebuie adăugată protecția ștergerii gestiunilor referite (restricție FK sau arhivare); documentele și stocurile nu sunt implementate în această etapă.
- Verificări: **370 teste PHP / 4333 aserțiuni**, inclusiv **6 teste noi / 281 aserțiuni** pentru CRUD, implicite, izolare între firme, validare, autorizare, CSRF și ciclul ștampilelor; lint PHP, PHPStan, **99 template-uri Twig**, sintaxa JavaScript și diff check. `composer check` a trecut lint/templates/analyse, dar subprocessul PHPUnit nu moștenește mbstring din wrapperul local; întreaga suită a trecut prin `bin/php-local.ps1 vendor/bin/phpunit`.
- Verificat vizual în browser la 320, 390, 768 și 1280 px și la dimensiunea normală desktop; fără overflow orizontal. Verificate salvarea AJAX, gestiunea implicită, eroarea IBAN fără pierderea datelor, încărcarea/previzualizarea ștampilei și confirmarea ștergerii. Pagina și formularul de adăugare au fost deschise pe 8080 în sesiunea Paulei. MySQL, concurența pe hosting și Apache nu au fost executate; fără deploy, SMTP sau push extern.

### 2026-11-08 — O singură firmă și configurarea TVA
- Conform clarificării utilizatorului, magazinul are o singură fișă de firmă. Crearea unei a doua firme este respinsă în tranzacția serializată, inclusiv din formulare vechi. Legăturile vechi de creare deschid editarea când firma există; ID-urile și datele existente sunt păstrate. Interfața nu mai afișează selector de firme, numărul firmelor, alegere implicită de firmă sau buton de ștergere a firmei.
- Fluxul este „Completează datele firmei” → „Editează datele”. Secțiunea Gestiuni și butonul „Adaugă gestiune” sunt vizibile de la început; înainte de prima salvare butonul este dezactivat cu explicație. După salvare, rămân disponibile adăugarea, editarea, ștergerea și gestiunea implicită.
- Migrarea 042 adaugă plătitoare/neplătitoare TVA și cota presetată la 21%, editabilă între 0 și 100%, cu maximum două zecimale, stocată în sutimi de procent. Cota se păstrează și pentru neplătitoare; nu este modificat calculul prețurilor sau comenzilor. Aplicată pe SQLite local după backup privat și pe QA, cu numerele de conturi/comenzi/firme/gestiuni neschimbate. Migrarea MySQL este pregătită, nu executată.
- Flash-ul de hover al butonului principal provenea din trecerea de la gradient la fundal simplu transparent în timpul tranziției; acum gradientul rămâne stabil peste o culoare opacă și se animă doar culoarea, umbra și poziția.
- Verificări: **8 teste / 326 aserțiuni** pentru fișă unică, formular vechi, gestiuni, TVA, validări, acces/CSRF și ștampilă, plus lint/PHPStan pentru modul și **99 template-uri Twig** compilate. Browserul a confirmat salvarea TVA la 19,50% pe date QA, presetarea de 21% în formularul principal, gestiunile vizibile și afișarea mobilă la 390 px fără overflow. Formularul actualizat este deschis pe 8080 în contul Paulei; fără date demonstrative noi în baza principală și fără mesaje externe.

### 2026-11-08 — Cotă TVA afișată numai pentru plătitoare
- Câmpul și explicația cotei apar numai când switch-ul „Plătitoare de TVA” este activ; fișa firmei ascunde și ea cota pentru neplătitoare. La dezactivare, formularul îmbunătățit dezactivează inputul, iar serverul păstrează cota salvată și ignoră valori ascunse, inclusiv invalide. Afișarea condițională funcționează prin CSS și fără JavaScript; validarea procentului rămâne pe server.
- Au trecut **8 teste / 366 aserțiuni**, lintul fișierelor PHP modificate, sintaxa JS și compilarea celor **99 template-uri Twig**. Verificat în browser: cotă ascunsă la pornire, afișată/editabilă la activare și ascunsă la dezactivare. Pagina completă, cu date demonstrative exclusiv QA, a fost inspectată pe 390/768/1280 px; formularul și acțiunile au fost verificate și la 320 px. Fișa, contactul, banca, ștampila și gestiunile păstrează marginile ecranului, iar headerul/footerul modalului rămân fixe. Sesiunea principală Paula rămâne deschisă pentru completarea datelor reale.

### 2026-11-08 — Ordinea următoarelor module
Priorități cerute de utilizator pentru reluarea lucrului, actualizate cu clarificările despre NIR-uri, reverificarea fiscală, SEO și cataloage. Lista nu pornește implementarea automat; următoarea etapă este cea a facturilor de achiziție și recepției. Un punct se bifează numai după confirmarea explicită a utilizatorului că este gata, nu doar pentru că implementarea sau verificările au fost încheiate.

1. [ ] **Facturi de achiziție + NIR-uri (note de intrare-recepție) + întregul sistem de intrare în stoc**, într-o singură etapă, asociată gestiunilor.
2. [ ] **Facturi de ieșire.**
3. [ ] **Stoc și fișa produsului.**
4. [ ] **Exporturi pentru toate modulele de mai sus.** Se pregătesc în fiecare etapă și se verifică împreună înainte de a continua.
5. [ ] **Retururi, anulări și regulile fiscale aferente**, după finalizarea documentelor și a evidenței stocului.
6. [x] **Implementarea plății cu cardul — Stripe TEST**, realizată mai devreme la cererea explicită a utilizatorului. Bifare autorizată de utilizator la 2026-11-08 după încheierea verificărilor automate, a plății sandbox și a verificării vizuale. Activarea live rămâne la punctul 11.
7. [ ] **Reverificarea dedicată a regulilor fiscale**, după implementarea plății cu cardul și înaintea etapelor SEO și cataloage.
8. [ ] **SEO pentru produse și căutarea inteligentă din site**, după reverificarea fiscală.
9. [ ] **Google Merchant și sincronizarea automată a produselor prin Merchant API.**
10. [ ] **Catalogul de produse din Stripe**, după Google Merchant, cu sincronizarea adăugărilor și editărilor.
11. [ ] **Verificarea finală înainte de upload și activarea producției**, ca ultim pas: parcurgem checklisturile de deployment, folosim domeniul public pentru poze și CTA-uri, verificăm sincronizarea catalogului Stripe live la adăugare/editare/ștergere, cheile și webhookul live, datele precompletate, reducerile/costurile și egalitatea totalurilor; verificăm vizual pozele în Checkout pe telefon și desktop. Activarea live și publicarea necesită acord explicit.

Mementourile zilnice sunt cerute la **10:40** și **19:30**, în fusul **Europe/Bucharest**. La fiecare memento se consultă această listă actualizată și se reamintesc numai punctele rămase nebifate, în ordinea de mai sus. Mementourile nu pornesc implementarea automat și nu bifează singure etapele.

Criterii de verificat la etapele produselor, fără a le considera deja implementate sau validate:
- Se păstrează metatitlul și metadescrierea stabilite manual ori generate automat la adăugare/editare.
- Paginile produselor au date structurate de tip **Product**, inclusiv oferta, prețul și disponibilitatea, și sunt verificate ca produse în instrumentele Google; nu sunt prezentate doar ca articole.
- Produsele publicate intră automat în sitemap, iar modificările, retragerea de la publicare și URL-urile sunt reflectate corect.
- Se verifică accesibilitatea pentru indexare, canonicalurile și rapoartele relevante din Google Search Console. Indexarea și afișarea efectivă în rezultate de produs sunt decizii Google, nu rezultate garantate de implementare.
- Adăugările și editările produselor se reflectă în căutarea inteligentă internă, păstrând relevanța și prioritatea potrivirilor exacte de nume/SKU.
- Merchant API transmite automat produsele eligibile și actualizările relevante, inclusiv prețul și disponibilitatea; se verifică erorile de sincronizare și produsele retrase.
- Catalogul Stripe reflectă adăugările și modificările relevante ale produselor, după integrarea Merchant. Configurarea serviciilor live rămâne o etapă explicită, nu este pornită de acest memento.

Componentele existente de anulare/plată rămân în proiect; lista stabilește ordinea dezvoltărilor viitoare, fără a le șterge sau considera fiscal validate. La reluare, trebuie reamintită această ordine înaintea unei funcționalități noi. Plata cu cardul nu mai este ultimul punct al întregului plan: este urmată de reverificarea fiscală, SEO, Merchant și catalogul Stripe.

Actualizare explicită: utilizatorul a cerut integrarea Stripe TEST și sincronizarea catalogului acum, înaintea ordinii inițiale, apoi a autorizat bifarea punctului 6 după verificare. Celelalte puncte rămân nebifate până la confirmarea lui; publicarea și activarea live rămân ultima etapă.

### 2026-11-08 — Stripe Checkout TEST, catalog și costuri operaționale
- Checkoutul găzduit de Stripe folosește brandingul contului, limba română, produsele/variantele/cantitățile comenzii și fotografii accesibile public. Emailul, numele, telefonul și adresele sunt preluate din comandă. Stripe poate solicita separat numele titularului, țara sau autentificarea băncii. Nu se salvează automat carduri, iar Stripe Tax și codurile promoționale suplimentare rămân oprite.
- Totalurile sunt calculate pe server în bani și păstrate în snapshoturi. Reducerea produselor se aplică numai produselor, transportul se transmite la valoarea netă cu economia descrisă, iar costurile operaționale sunt linii distincte fără reducere accidentală. Inițierea/reîncercarea plății este idempotentă; webhookul verifică semnătura, modul, sesiunea, comanda, moneda și suma. Revenirea browserului nu confirmă plata. Evenimentele duplicate sau tardive nu dublează confirmările; plata târzie a unei comenzi anulate rămâne pentru verificare manuală.
- În Metode de plată sunt disponibile adăugarea, editarea și ștergerea costurilor operaționale, în modale responsive cu header și acțiuni fixe. Costurile au nume și sumă fixă în Lei și sunt vizibile în checkout, Stripe, comenzile istorice și rezumatele emailurilor. Switchurile globale păstrează salvarea silențioasă; regulile categoriilor și fallbackul între metode rămân active.
- Catalogul are coadă persistentă, retry și mapping separat pe cont și test/live. Salvarea produsului pune actualizarea în coadă; ștergerea arhivează produsul și prețurile în Stripe. Workerul rulează separat de tranzacțiile magazinului. Checkoutul poate folosi produse snapshot suplimentare pentru a păstra comenzile și delimitarea reducerilor.
- Migrările 043–045 sunt aplicate local pe SQLite. Cardul este activat local în modul TEST, cu listener și worker catalog în fundal. Sincronizarea inițială a reușit pentru **42 de produse**, inclusiv imaginile celor **31 de produse cu galerie**. Pentru testarea locală se folosesc numai imaginile deja publice din repository; imaginile locale nou încărcate necesită o sursă publică pentru afișare în Stripe. Suprascrierea este ignorată în producție/live, unde se utilizează domeniul magazinului.
- Verificare reală sandbox: o plată de **177,00 Lei** cu cardul de test a revenit în pagina de succes, iar webhookul a răspuns HTTP 200 și a persistat comanda confirmată/plătită. O sesiune separată cu fotografii a afișat **1.017,00 − 101,70 + 0,00 transport + 5,50 cost operațional = 920,80 Lei**, identic cu magazinul. Această a doua sesiune a rămas neplătită pentru previzualizare. Nu s-au transferat bani reali.
- Browserul intern a verificat fotografiile și totalul Stripe pe desktop și la 390 px, plus costurile operaționale la 390/768/1280 px. Adăugarea, editarea și ștergerea unui cost au fost executate numai în baza QA izolată; baza principală nu a primit costuri demonstrative. QA a păstrat push oprit, fără notificări externe. Configurarea emailului din aceste teste a fost corectată ulterior la modul acceptat `preview` (vezi verificarea de mai jos); termenul anterior „spool” nu desemna un mod valid al aplicației.
- Au trecut suita integrală **440 teste / 4.859 aserțiuni**, apoi **22 teste / 204 aserțiuni** pentru ultimele ajustări ale imaginilor și izolării producției. Lint PHP, PHPStan, sintaxa JS și compilarea celor **102 template-uri Twig** au trecut. Verificarea finală independentă nu a identificat blocaje de securitate. MySQL/concurența pe hosting, 3DS real, Stripe live și expedierea emailurilor/push pe domeniul public nu au fost verificate în acest pas; nu s-a făcut deploy.
- Checklistul obligatoriu pentru producție este în `docs/DEPLOYMENT.md`, secțiunea Stripe, și este și **ultimul punct nebifat al TODO-ului** de mai sus. Nu se activează live și nu se publică automat.

### 2026-11-08 — Comenzi create numai după plasare/plată, reluare și coș păstrat
- Cerința nouă înlocuiește precrearea comenzilor Stripe: inițierea cardului persistă o încercare separată, snapshotul totalului și rezervări temporare. Refuzul, anularea, timeoutul și expirarea nu introduc o comandă în `orders`. Rambursul creează `new/pending` și emailul de comandă primită; numai confirmarea Stripe semnată și verificată creează `confirmed/paid`, cu emailul de comandă confirmată și eticheta „Plătit”. Compatibilitatea sesiunilor vechi rămâne; nu au fost șterse comenzile istorice.
- Coșul rămâne activ până la succes. „Reia plata” refolosește aceeași sesiune; „Renunță la plată” închide întâi sesiunea la procesator și întoarce checkoutul cu produsele și datele clientului păstrate. Un coș cu plată deschisă nu poate crea accidental încă o comandă prin ramburs sau o a doua sesiune. Butonul „Verifică starea plății” a fost eliminat la cererea utilizatorului; confirmarea în așteptare se verifică automat.
- Validările includ semnătura, modul, ID-ul și cheia încercării, asocierea sesiunii, moneda și totalul. Rezervările, cupoanele, comanda și outboxul sunt tranzacționale; apelurile Stripe sunt în afara tranzacțiilor. Evenimentele duplicate nu dublează comenzile sau notificările. Succesele tardive cu stoc insuficient ori al doilea debit real sunt păstrate ca plătite pentru verificare manuală.
- Migrarea 046 a fost aplicată pe QA și pe SQLite principal după backup consistent; baza principală păstrează aceleași **31 de comenzi**, `integrity_check=ok`. Reconcilierea încercărilor abandonate are CLI separat, verificare la procesator și protecție la schimbarea cheii; configurarea cronului pe hosting este în checklist.
- Verificări Stripe TEST reale în QA: card refuzat (`card_declined`) cu **0 comenzi**, coș activ și 3 bucăți păstrate; reluare cu **3D Secure 2** și plată de **920,80 Lei**, urmată de webhook HTTP 200 și exact o comandă confirmată/plătită; expirare reală cu webhook procesat, fără comandă și fără pierderea produselor. Retrimiterea locală, semnată, a aceluiași eveniment real de două ori a răspuns 200/200 fără duplicate.
- Verificare în browser de la adăugarea produsului până la Stripe: total **76,60 Lei**, revenire anulată cu „Reia plata” funcțional, apoi renunțare și date precompletate; produsul rămâne în coș. Pagina anulată și coșul au fost inspectate la **390 px**. A fost reparată și politica CSP care bloca redirecționarea browserului după POST către Stripe. Un test HTTP separat cu cookie și CSRF a confirmat păstrarea celor două produse și a adresei după expirare.
- Emailurile au fost procesate efectiv prin outbox în **preview**, fără SMTP: pentru fiecare comandă COD/plătită, 1 client + 2 destinatari interni QA, cu subiectele și etichetele corespunzătoare. În configurația principală s-au constatat **0 adrese interne setate**; pentru notificări reale interne trebuie adăugate în setările comenzilor. Nu s-au trimis emailuri/push externe și nu s-a verificat livrarea în inbox în acest pas.
- Au trecut **505 teste / 5.552 aserțiuni**, lint PHP, analiza PHPStan și compilarea celor **102 template-uri Twig**. MySQL/concurența pe hosting, cardul live, SMTP/push în producție și cronul pe server rămân neverificate. Nu s-a făcut deploy și nu s-au schimbat bifările celorlalte module din roadmap.

### 2026-11-12 — NIR-uri și recepții, adaptate pentru La Măriuca
- Adăugat tabul **NIR-uri** în **Stoc și achiziții**, cu registru căutabil/paginat, filtre, indicatori, fișă detaliată și formulare ghidate în modale. Antetul și acțiunile rămân fixe, iar conținutul se derulează separat. Pagini complete păstrează fluxurile principale fără JavaScript; erorile și avertismentele păstrează valorile introduse.
- Recepțiile folosesc firma și gestiunile existente, furnizori și locații create inclusiv din formular, data/ora recepției, monedă/curs manual sau BNR, până la 1.000 de poziții, cantități distincte, conversii UM, discount, TVA și costuri alocate. Calculele persistente sunt exacte: cantitate 4 zecimale, preț/cost 6, curs 8, totaluri în bani. TVA în cost este o opțiune explicită.
- Draftul nu modifică stocul. Confirmarea alocă numărul `NIR-AAAA-000001` și persistă atomic recepția, mișcările, stocul pe gestiune, FIFO, costurile, auditul și evenimentele. Versiunile vechi primesc conflict; reluarea aceleiași operațiuni, inclusiv cu atașamente multipart, nu dublează documentul, stocul sau fișierele.
- Disponibile corecția controlată a loturilor neconsumate, returul parțial/integral către furnizor cu factură corectivă și motiv, recepția fizică a retururilor client la costul original, soldul inițial documentat, consumurile în așteptare și reconcilierea FIFO. Schimbările de stare comercială și plata nu produc recepție contabilă. Facturile fiscale de ieșire/corective rămân etapa separată din roadmap; aici se păstrează referințele documentelor reale și punctele de integrare.
- Referințele furnizor–produs urmează potriviri exacte sigure, cu sugestii care cer selecție manuală; codul furnizorului nu este confundat cu SKU-ul intern. Reasocierea cere motiv și audit. Prețurile anterioare și variațiile importante sunt vizibile/semnalate.
- Atașamentele PDF/JPG/PNG/WebP/XLSX/XML sunt private, maximum 15 MB/fișier, cu MIME real, SHA-256 și deduplicare. Importurile XLSX/UBL propun liniile pentru verificare; XML-ul blochează entități externe, iar formulele Excel importate nu sunt executate. Exporturile PDF, Excel individual cu cinci foi/141 coloane, registrul cu 38 coloane și ZIP cu originale respectă permisiunile. Coloanele fără corespondent în La Măriuca sunt explicit documentate ca nestocate, fără date inventate.
- Retururile fără recepție sunt semnalate în FIFO și în clopoțelul administrării, inclusiv pe mobil/PWA. Amânarea este pe zi și utilizator; mesajul reapare a doua zi și dispare după NIR. Nu s-au expediat emailuri ori notificări OS push reale.
- Migrarea **047** SQLite/MySQL este versionată. Aplicată pe SQLite principal după backup consistent; baza principală nu a primit recepții demonstrative. Soldul contabil istoric existent a rămas neatins; catalogul/dashboardul folosesc noua evidență pentru scope-urile înregistrate, cu fallback pentru istoricul nemigrat. Stocul comercial din magazin nu este sincronizat implicit cu NIR.
- Limitele PHP pentru formularele mari și atașamente sunt configurate în wrapperul local și `.user.ini`; verificarea aplicării lor pe hosting este adăugată în deployment. Serverul principal rulează pe **8080**. Testele vizuale cu date demonstrative au folosit exclusiv o bază QA separată; serverul QA a fost oprit la final. Browserul principal solicită autentificare; nu s-au schimbat utilizatorii sau parolele reale.
- Verificări: **550 teste / 5.807 aserțiuni** în suita integrală, PHPStan fără erori, lint PHP, sintaxă JavaScript și **113 template-uri Twig** compilate. După actualizarea de securitate `symfony/mime` la 8.1.10 au trecut suplimentar **168 teste / 1.352 aserțiuni** pentru NIR, email și outbox; auditul Composer nu mai raportează vulnerabilități cunoscute.
- Design inspectat în browserul intern pe telefon **390 px**, tabletă **768 px** și desktop, inclusiv adăugare, selector de produse, salvare, confirmare cu avertismente și modalul de retur. PDF-ul a fost randat și inspectat în variante cu una și mai multe pagini. Capturile rămân private în `storage/qa/`.
- MySQL/InnoDB și concurența pe hosting, Apache și preluarea BNR prin rețeaua mediului țintă rămân de verificat. Nu s-a făcut deploy, nu s-au configurat servicii live și nu s-a bifat automat etapa din roadmap. Ghidul și limitele adaptării sunt în **docs/NIR.md**.

### 2026-11-12 — Audit complet al fluxurilor NIR și FIFO exclusiv intern
- Verificate recepția de la draft la confirmare, corecția controlată, retururile furnizor/client, soldul inițial, referințele, reconcilierea, importurile, atașamentele, exporturile și drepturile. Matricea detaliată, scenariile și limitele sunt în **docs/NIR_QA.md**.
- Corectate rotunjirile preview-ului JS prin calcule zecimale exacte, procentele XLSX, validarea UBL, motivul obligatoriu al corecției, erorile de precizie la retur, anul numerotării după ora României, locațiile inactive și reconcilierea consumurilor în așteptare după retur client. Reasocierea unei referințe nu preia istoricul de preț al altui produs.
- Formularele păstrează fișierele selectate succesiv și valorile introduse la erori. Importul blochează salvarea concurentă și verifică limitele înainte de adăugarea liniilor. Răspunsurile întârziate pentru curs, asociere sau locație nu suprascriu alegerile ulterioare. Avertismentele nu ascund erorile; drafturile incomplete pot fi salvate.
- FIFO rămâne regula internă pentru ordinea consumurilor din stoc. Interfața și exporturile nu mai afișează denumirea algoritmului; pagina se numește **Evidența stocului**, cu URL `/admin/nir-uri/stoc/`, iar URL-ul vechi redirecționează. Numele produselor și furnizorilor, etichetele auditului și cantitățile sunt afișate lizibil.
- BNR verificat prin apel real local la noua gazdă oficială `curs.bnr.ro`: pentru EUR/12.11.2026, ultima cotație disponibilă la verificare a fost **5,34600000 din 11.11.2026**. Cache-ul, multiplicatorii, trecerea dintre ani și indisponibilitatea sunt acoperite de teste. Hostingul rămâne de verificat.
- Browser intern: recepție de **12,14 RON** cu două atașamente, confirmare cu avertismente, retur parțial de **−4,05 RON** cu două din trei bucăți rămase, import XLSX de **205,68 RON**, salvare draft incomplet și validare la confirmare; descărcare efectivă a registrului Excel. Inspecție la **390/768/1280 px**, fără overflow orizontal, cu antet/acțiuni fixe și scroll sub antet. PDF cu patru pagini inspectat vizual.
- Rezultate: suita integrală **651 teste / 6.403 aserțiuni**; după ultima corecție de prezentare și testul suplimentar, suita NIR **148 teste / 871 aserțiuni**. **21 teste Node**, PHPStan, lint PHP, sintaxă JS, diff check și **113 template-uri Twig** compilate au trecut.
- Doar baze SQLite temporare și QA izolat; nicio recepție demonstrativă în baza principală, fără email/push/Stripe. Serverul QA 8081 a fost oprit; serverul principal **8080 răspunde HTTP 200**. Capturile sunt private în `storage/qa/`.
- Ieșirea contabilă automată din vânzări rămâne de conectat la emiterea facturii: `AccountingStockService::recordInvoice()` este puntea existentă către consumul intern, dar checkout-ul și plata nu o declanșează. MySQL/concurența reală și Apache nu sunt validate. Nicio bifare nouă în roadmap și nicio publicare.

### 2026-11-12 — Design și previzualizare pentru exporturile NIR
- Refăcut PDF-ul A4 cu logo-ul original, carduri pentru firmă/furnizor/gestiune, fotografii, cantități și valori lizibile, total evidențiat, semnături, ștampilă și subsol repetat. Datele firmei, contactele și informațiile bancare provin din instantaneul documentului; istoricul nu este înlocuit de setări ulterioare.
- Excel-ul are o primă foaie de prezentare cu date complete, miniaturi, produse și totaluri numerice din bani. Cele cinci foi, cele 141 de coloane detaliate, formulele de diferențe, filtrele și drepturile de acces sunt păstrate. Logo-ul este integrat pe alb, iar imaginile produselor respectă lățimea coloanei.
- Exemple private generate cu datele firmei citite din aplicație și recepție demonstrativă, marcate **Previzualizare**. Nicio recepție și nicio modificare de stoc în baza principală. Fișierele sunt în `storage/qa/nir-export-design/` și nu intră în Git.
- Inspecție vizuală: PDF scurt cu trei produse pe **o pagină** și PDF cu 30 de poziții pe **trei pagini**, inclusiv antete repetate și ultima pagină; Excel importat și randat cu artifact-tool, fără erori de formule identificate.
- Verificări: **151 teste NIR / 1.005 aserțiuni** trecute în rulare serială; **13 teste pentru documente / 188 aserțiuni** repetate după ultima ajustare; PHPStan fără erori, lint și **113 template-uri Twig** compilate. Regresii pentru datele firmei, totaluri exacte, text sigur, mascarea costurilor și fundalul logo-ului. Nicio bifare nouă în roadmap.
- Ajustare cerută ulterior: eliminat blocul **Destinația recepției** numai din PDF; emitentul și furnizorul folosesc câte jumătate din lățime. Referința NIR-ului original pentru storno rămâne în banda documentului. Previzualizarea regenerată și inspectată vizual pe o pagină; Excel-ul și datele persistente nu au fost schimbate.

### 2026-11-12 — Exporturi NIR aliniate la modelele furnizate și două ciorne de test
- La cererea explicită ulterioară, exportul individual Excel urmează modelul trimis: **o singură foaie NIR, 13 coloane**, antet cu firmă și factură, fotografii, diferențe cantitative, totaluri, constatări și ștampilă. Acest format înlocuiește cele cinci foi descrise mai sus; registrul Excel de 38 de coloane rămâne disponibil separat. Culorile și logo-ul sunt La Măriuca; fișierele sursă ale utilizatorului nu au fost modificate.
- PDF-ul urmează structura modelului A4 landscape: identitate, bandă număr/data, două panouri cu metadate, tabel de 13 coloane, constatări și ștampilă. Gestiunea și locul recepției apar în metadate, fără un card separat „Destinația recepției”. Ciornele sunt marcate distinct, fără intrare în stoc.
- Datele provin din instantaneele documentelor. Factura inițială la storno este hidratată din NIR-ul asociat; nu se confundă cu factura de retur. Sumele pe linii rămân cele autoritare în bani, cu formule de total rotunjite, cote TVA mixte și moneda documentului. Nu se preiau erorile de rotunjire din model. Textele sunt celule explicite, iar exporturile fără drept de costuri nu expun valorile financiare.
- Verificare vizuală: PDF scurt de trei produse pe o pagină, document de 30 de produse pe patru pagini cu antete repetate, plus PDF și XLSX din ciorna reală de test. Excel importat/randat cu artifact-tool, fără erori de formule. Pentru documente lungi, Excel încadrează lățimea paginii fără micșorarea tuturor rândurilor pe o singură pagină.
- Verificări: **155 teste NIR / 1.013 aserțiuni**, apoi **17 teste documente / 194 aserțiuni** după ultima ajustare a constatărilor; PHPStan fără erori, lint PHP, diff check și **113 template-uri Twig** compilate. Sunt acoperite inclusiv totalurile exacte, textele sigure, miniaturile, redacția costurilor și factura inițială după reîncărcare.
- Serverul principal **8080** este pornit. La cererea explicită a utilizatorului, în administrarea Paulei au fost create două **ciorne TEST**: ID 1 fără documente (2 produse, 217,80 RON) și ID 2 cu exact un Excel, un PDF și o fotografie (2 produse, 538,45 RON). Atașamentele PDF/Excel folosesc formatul final și au hash-urile verificate. Cele 12 tabele verificate de stoc, mișcări, numerotare și outbox au rămas identice; nu s-au confirmat recepții și nu s-au trimis notificări.
- Mostrele și rapoartele rămân private în `storage/qa/`. Nicio bifare nouă în roadmap și nicio publicare/configurare live; Apache și MySQL nu au fost verificate în această etapă.
- Ajustare ulterioară cerută: logo-ul din antetul PDF este mărit de la 100 la 172 px și centrat orizontal/vertical în zona lui. Previzualizarea regenerată și inspectată vizual pe o pagină, fără suprapuneri; 113 template-uri Twig compilate. Excel-ul și datele NIR-urilor nu sunt modificate.

### 2026-11-12 — Locații de recepție și interfață NIR
- Locațiile de recepție se administrează din **Detaliile firmei**: adăugare, editare, ștergere și locație implicită, prin modale. Fiecare locație aparține unei gestiuni; denumirile duplicate sunt respinse în aceeași gestiune. Prima locație devine implicită, iar schimbarea implicitului debifează automat celelalte. Eliminarea păstrează referințele și instantaneele NIR-urilor existente.
- În adăugarea/editarea NIR, **Adaugă o locație de recepție** deschide un modal separat, cu denumire și switch pentru implicit. Salvarea selectează locația nouă fără reîncărcare și fără pierderea datelor NIR; erorile păstrează ambele formulare. Răspunsurile întârziate respectă gestiunea pentru care s-a făcut cererea.
- Conform ultimei cereri, fiecare NIR rămâne pe **pagină separată**. Afișarea istoricului a fost eliminată, iar auditul operațiunilor documentului rămâne în backend. La **Locație recepție** apare numai denumirea. Fișa, produsele, atașamentele și acțiunile au spațiere și aranjare adaptate ecranelor mici.
- Confirmările de ștergere a unui atașament sau a unei ciorne au un singur simbol principal, identitatea fișierului/documentului, explicație și acțiuni aliniate. Dialogul nu mai are marginile interioare goale din varianta anterioară.
- Verificări: **165 teste PHP / 1.435 aserțiuni**, **24 teste Node**, PHPStan fără erori, lint PHP și **117 template-uri Twig** compilate. Regresiile acoperă autorizarea/CSRF, apartenența la firmă și gestiune, implicitul, duplicatele, referințele istorice, salvarea fără pierderea formularului și auditul documentelor după eliminarea istoricului vizibil.
- Browser intern, administrarea **Paulei pe 8080**: pagină NIR separată, locații în detaliile firmei, modalul secundar și validarea duplicatelor; verificări la **320/390/768 px și desktop**. Confirmarea de ștergere a fost doar previzualizată, fără ștergerea atașamentelor principale. Testele de mutație au folosit SQLite izolat. Captura finală rămâne în `storage/qa/nir-locations-ui/`, exclusă din Git. MySQL/concurența pe hosting și Apache nu sunt verificate; nicio bifare nouă în roadmap, publicare sau configurare live.
- Confirmarea NIR folosește acum același dialog compact, cu furnizor/factură, total lizibil, accent verde și butonul **Confirmă NIR**. Antetul și acțiunile rămân fixe; numai conținutul poate derula când apar avertismente. Verificată la 320/390/768 px și desktop, inclusiv închiderea/redeschiderea, fără confirmarea ciornelor principale. Au trecut **27 teste HTTP / 326 aserțiuni**, **12 teste JavaScript** și compilarea celor 117 template-uri Twig.

### 2026-11-12 — NIR: pagină modernă și completare ghidată
- Fișa NIR rămâne o pagină separată, cu fonturi fără serife, etape vizuale, explicația acțiunii următoare, navigare directă la factură/produse/documente și detalii cantitative extensibile. Totalurile au o greutate tipografică mai discretă. Butonul **Înapoi la registru** are contur, săgeată într-un cerc și stare de hover; ținta tactilă are minimum 44 px.
- Adăugarea și editarea folosesc patru pași: **Factura → Produse → Recepția → Verificare**. Moneda/TVA, documentele și codurile furnizorului se deschid la nevoie. Rezumatul permite revenirea directă la fiecare pas. Salvarea în lucru rămâne disponibilă, iar corecțiile au explicație distinctă despre actualizarea stocului.
- Trecerea între pași păstrează câmpurile și fișierele. Validarea indică în română câmpurile lipsă, solicită asocierea produselor urmărite în stoc și deschide pasul și secțiunea cu eroarea serverului. Liniile documentare rămân acceptate. Fluxul HTML fără JavaScript păstrează toate secțiunile și controalele originale.
- Browser intern, **Paula / 8080**: adăugare fără salvare, editarea ciornei existente fără persistare, navigare înainte/înapoi, alegerea produsului, modal secundar pentru locație, rezumatul cu trei atașamente și total 538,45 RON, validare în română, detalii extensibile. Verificate **320/390/768 px și desktop 1366 px**, fără depășire orizontală; antetul și butoanele formularului rămân în afara zonei de derulare. Corectată îngustarea explicației lângă CTA pe telefon. Nicio ciornă principală confirmată sau salvată în această verificare.
- Au trecut **40 teste HTTP / 417 aserțiuni**, **30 teste JavaScript** (inclusiv șase regresii pentru pașii ghidați) și compilarea celor **117 template-uri Twig**. Verificarea de context a confirmării folosește factura și URL-ul documentului persistat, împreună cu versiunea curentă. Nicio bifare nouă în roadmap, publicare sau configurare live. Capturile rămân private în `storage/qa/nir-locations-ui/`.

### 2026-11-12 — Stilul NIR aprobat și zecimale compacte
- Refăcută fișa recepției cu antet comun, traseu vizual conectat, acțiuni grupate, exporturi diferențiate, total pe fundal prună, factură/furnizor evidențiate și rânduri compacte de produse. Observațiile și detaliile cantitative se extind la cerere. Utilizatorul a aprobat explicit acest stil pentru viitoarele lucrări; direcția este păstrată în `AGENTS.md`.
- În adăugare/editare/corecție, valorile inițiale nu mai expun zerourile preciziei de stocare: `85.000000` devine `85`, iar `85.125600` devine `85.1256`. Cursurile, conversiile și valorile importate sunt compactate fără conversie la float. Câmpurile tastate de utilizator și formularele returnate după erori nu sunt reformate; zecimalele semnificative și calculele autoritare sunt păstrate.
- Verificare în browser intern, **Paula / 8080**: ciornele existente cu/fără atașamente, progres, acțiuni, detalii extensibile, formular de editare și confirmare deschisă/închisă fără executare. Layout inspectat la **320/390/768/1440 px**, fără depășire orizontală; valoarea manuală `45,1200` a rămas neschimbată după blur. Nicio salvare, confirmare sau ștergere în baza principală.
- Au trecut **41 teste HTTP / 434 aserțiuni**, **30 teste JavaScript**, PHPStan fără erori și compilarea celor **117 template-uri Twig**. Testul nou verifică afișarea valorilor persistente și păstrarea preciziei introduse manual la rerandarea formularului. Nicio bifare nouă în roadmap și nicio modificare a serviciilor live.

### 2026-11-12 — Catalog NIR, confirmare directă și numerotări independente
- Selectorul de produse folosește stilul prună/roz aprobat, cu dialog compact, fotografie, variantă, SKU și număr de rezultate. Potrivirile exacte după nume, SKU, cod de bare sau codul furnizorului ales exclud rezultatele aproximative; variantele produsului exact rămân disponibile. Căutarea semantică reutilizează sinonimele și toleranța existentă pentru nume, fără corectarea aproximativă a codurilor numerice. Răspunsurile întârziate nu înlocuiesc o căutare sau un furnizor ales ulterior.
- Produsele din formular și detaliile din fișă au deschidere/închidere animată în ambele sensuri. Secțiunea **Unitate de măsură, conversie și diferențe** este evidențiată, valorile numerice folosesc font fără serife și cifre aliniate. Fotografiile din fișa NIR se măresc într-un dialog translucid; **Vezi produsul** deschide pagina publică într-o filă nouă numai pentru produsele publicate.
- În ultimul pas, **Salvează și confirmă** apare în verde înaintea butonului **Salvează draft**. Salvarea și confirmarea sunt o singură tranzacție, cu validarea completă, drepturi și cheie de idempotență: erorile/avertismentele neacceptate anulează operațiunea, iar retrimiterea nu dublează documentul sau stocul. Drafturile incomplete sunt marcate roșu în registru și au **Reia completarea**.
- NIR-urile noi confirmate folosesc **LM-NIR-2026-1**, fără zerouri înaintea numărului. Drafturile au contor persistent separat: **Draft #1**, **Draft #2** etc. Confirmarea directă nu consumă numere de draft; editarea nu renumerotează, iar ștergerea nu reutilizează numărul. Căutarea în registru recunoaște și numărul afișat al draftului. Numerele istorice deja confirmate sunt păstrate.
- Migrarea **048** este versionată pentru SQLite/MySQL și aplicată pe SQLite principal: cele două ciorne existente apar ca Draft #1 și Draft #2. Fără recepții confirmate sau schimbări de stoc în baza principală. Testul de secvențe acoperă explicit NIR 100 → draft 1 → NIR 101 → draft 2, păstrarea draftului 1 și continuarea după ștergere.
- Verificări: **165 teste PHP NIR/căutare / 1.134 aserțiuni**, **32 teste JavaScript**, PHPStan fără erori, **117 template-uri Twig** compilate și diff check. Regresiile HTTP acoperă confirmarea directă/reîncercarea, lipsa persistării la eroare, permisiunile, drafturile incomplete și linkurile publice.
- Browser intern, **Paula / 8080**: selector exact, tastă Enter fără trimiterea formularului, expandare/închidere fără pierderea cantităților, butoane finale pe un rând la 320 px, imagine mărită pe mobil/tabletă, Escape cu revenirea focusului și deschidere efectivă a produsului într-o filă nouă. Verificate **320/390/768/1440 px**, fără depășire orizontală. Capturile rămân private în `storage/qa/nir-locations-ui/`. MySQL/concurența pe hosting și Apache nu au fost rulate; fără deploy, servicii live sau bifare automată în roadmap.


### 2026-11-12 — Registrul NIR, arhivele și atașamentele mari
- Registrul XLSX păstrează cele **38 de coloane** din referința furnizată. Are logo La Măriuca într-o casetă rotunjită, antet prună/roz, indicatori cu formule care respectă filtrele, rânduri alternate, stări colorate și autorii afișați prin nume. Datele sunt celule Excel native în ora Bucureștiului; sumele sunt calculate din bani. Cantitățile și cursul folosesc formatul General: numerele întregi nu au punct final sau zerouri inutile, iar fracțiile rămân păstrate. Costurile rămân ascunse fără dreptul de acces.
- Exportul complet conține registrul XLSX la rădăcină și câte un dosar pe NIR, cu XLSX, PDF și `documente/`. Dosarul individual conține direct XLSX, PDF și `documente/`, inclusiv un director gol când nu există atașamente. Originalele își păstrează conținutul; numele drafturilor sunt lizibile.
- Căutarea din registru are un singur buton de ștergere rotund, centrat, cu focus vizibil și țintă tactilă de 44 px pe telefon. Escape golește câmpul. Eliminat linkul **Evidența stocului** din antetul registrului, conform cererii.
- Eliminat plafonul de **15 MB** din selectarea fișierelor, import și salvarea atașamentelor NIR. Copierea originalelor și calculul SHA-256 se fac pe fragmente, fără încărcarea întregului document în memoria PHP. MIME, extensiile, accesul privat, încărcările incomplete, XML fără entități externe și conținutul ZIP nepermis rămân verificate. Limitele de decomprimare pentru importul automat de celule nu blochează păstrarea unui Excel mare ca atașament. Limita de 30 de fișiere într-o trimitere rămâne distinctă.
- Configurațiile locală și PHP-FPM/CGI folosesc `upload_max_filesize=0` și `post_max_size=0`. Serverul principal **8080** a fost repornit cu aceste valori. Încărcare multipart reală verificată pe un server PHP izolat, cu același wrapper: Excel de **33.560.697 bytes**, salvat cu SHA-256 identic. Fără modificări ale documentelor sau stocului din baza principală.
- Verificări: **95 teste PHP / 772 aserțiuni**, **14 teste JavaScript**, PHPStan fără erori, lint PHP și **117 template-uri Twig** compilate. Regresiile verifică XLSX de 17 MB prin atașare/descărcare/import HTTP, original de 32 MB, ștergerea copiilor incomplete, protecțiile XML/ZIP, formulele, filtrele și structura arhivelor.
- Browser intern, **Paula / 8080**: căutare la 320/390 px și desktop fără depășire orizontală, export XLSX descărcat, link eliminat și Excel de 32 MB acceptat în dialog fără mesajul vechi. Fișierul a fost doar selectat în browser, fără salvare pe NIR-ul principal. Registrul randat și inspectat vizual; artefactele exemplu sunt în `outputs/nir-registry-20261112/`, iar probele rămân în `storage/qa/`, neincluse în Git. Apache/MySQL/hosting nu au fost verificate. Nicio bifare nouă în roadmap și nicio publicare/configurare live.
