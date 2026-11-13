# Facturare de ieșire — adaptare La Măriuca

Referință: `OUTGOING_INVOICES_REFERENCE.md`, primită la 2026-11-13. Checklist tehnic, nu confirmare de finalizare a roadmapului.

## Decizii de adaptare
- Modelul PDF aprobat rămâne baza; firma și clientul se îngheață la emitere. Nota de garanție rămâne.
- Stocul contabil este separat de disponibilitatea online. Factura consumă intrările NIR, poate produce deficit și se reconciliază cu achizițiile ulterioare. Nu scade încă o dată stocul online al comenzii.
- Returul valoric, restituirea banilor și primirea fizică sunt operațiuni separate. Costurile originale se păstrează, fără costuri inventate.
- O serie în folosire pentru documentele noi; cele vechi păstrează seria, numărul și datele salvate. Numerotare atomică pe server.
- Fără integrare fiscală externă în această etapă; contract și blocare la prima tentativă pentru extensia viitoare.
- PHP pe Apache/shared hosting; PDF/XLSX/ZIP cu bibliotecile existente. Evenimente persistente și CLI PHP/cron, fără serviciu Node obligatoriu.

## Verificări de implementare
- [x] Model persistent, numerotare atomică, snapshoturi și audit.
- [x] Emitere manuală/automată, calcul exact, reduceri, transport, costuri operaționale.
- [x] Traseu NIR–factură, deficit, reconciliere, inversarea mișcărilor.
- [x] Retur integral/parțial, corecții, editare controlată, ștergerea ultimului document eligibil.
- [x] Încasare, rambursare înregistrată distinct, primire fizică și memento intern.
- [x] Legături cu comenzile, anularea și cererile de retur.
- [x] Email cu PDF, coadă/reîncercări și automatizările existente conectate.
- [x] PDF aprobat adaptat, Excel, registru și ZIP; link public revocabil.
- [x] Registru căutabil și paginat, fișă și modale responsive în stilul NIR.
- [x] Teste locale de integrare, acces, idempotentă, bani/stoc și verificare vizuală.

## Verificat la 2026-11-13
- Suita integrală PHPUnit: 729 teste, 7214 aserțiuni. Modulul dedicat: 21 teste / 105 aserțiuni. Lint PHP, PHPStan și compilarea a 140 template-uri Twig fără erori.
- Bază SQLite izolată pentru toate emiterile de test. Browser: emitere din comandă, editarea notei, retur parțial, primire fizică și întoarcerea unei bucăți în stoc; modificări nesalvate, switch/cantitate, zoom, copiere, descărcări XLSX/ZIP.
- Interfață inspectată la 320, 390, 768 px și desktop: fără overflow orizontal; modalul derulează numai conținutul, păstrând antetul și acțiunile. Antetul tabelului este ascuns integral când rândurile devin carduri.
- PDF aprobat inspectat la o pagină și la trei pagini: antet de tabel repetat, totaluri și footer de garanție păstrate. Structura XLSX și arhivele ZIP verificate programatic; fără verificare vizuală într-o instalare Excel.
- Migrațiile 052 și 053 aplicate local. În baza principală nu au fost create facturi demonstrative și nu au fost modificate regulile existente de automatizare.

## Adaptări operaționale
- Stocul online este actualizat de fluxul comenzii; factura operează evidența contabilă. Disponibilitatea din magazin nu este blocată de soldul contabil negativ.
- Legătura achiziție–vânzare folosește alocări interne persistente și deficit explicit. Reconcilierea completează costul real din NIR; nu inventează un cost pentru stocul neacoperit.
- Produsele nevandabile sunt înregistrate distinct în verificarea returului și nu reintră în stocul vandabil. Aceasta nu este o gestiune separată completă de carantină.
- Încasarea/rambursarea manuală înregistrează operațiunea efectuată; nu inițiază transferuri bancare sau rambursări Stripe. Rambursarea nu poate depăși încasările disponibile.
- Ștergerea unui document negativ păstrează încasările reale și evenimentele ulterioare ale comenzii, în locul restaurării oarbe a unui status istoric.
- `FiscalGateway` este contractul de extensie cerut. Coada/trimiterea/starea/blocarea au fost testate cu un gateway fals. Integrarea reală necesită adaptorul autorității, recuperarea proceselor întrerupte și probe live autorizate.

## Limite
Concurența reală MySQL, Apache/shared hosting, SMTP, push și fiscalizarea externă nu au fost verificate în această etapă. Nu s-au trimis mesaje reale și nu s-a publicat aplicația. Checklistul tehnic nu bifează etapa din roadmap: aceasta așteaptă confirmarea explicită a utilizatorului.
