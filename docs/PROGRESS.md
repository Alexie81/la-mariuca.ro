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
