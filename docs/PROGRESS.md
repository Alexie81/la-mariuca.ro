# Progres

Actualizat: 2026-10-21

## VERIFIED

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
- Zona de detalii a devenit un grup de panouri semantice pentru descriere, conținutul pachetului, materiale, îngrijire și dimensiuni. Pagina rămâne funcțională fără JavaScript, iar tranzițiile respectă `prefers-reduced-motion`.
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
- Layout-ul are compoziții distincte pentru telefon, tabletă și desktop. Pe telefon, panoul editorial este eliminat complet și formularul începe direct cu acțiunile contului; pe tabletă și desktop compoziția în două zone rămâne. Verificarea vizuală locală a fost făcută la 390 px, 820 px și 1200 px; au fost confirmate rearanjarea responsive, lipsa overflow-ului și schimbarea accesibilă „Arată parola” / „Ascunde parola”.
- Verificări locale trecute: lint PHP/JavaScript, compilarea tuturor celor 37 de template-uri Twig, PHPStan fără erori și PHPUnit cu 63 de teste / 470 de aserțiuni.
