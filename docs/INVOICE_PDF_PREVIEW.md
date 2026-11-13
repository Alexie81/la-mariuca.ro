# Model PDF factură La Măriuca

Model de design A4, fără valoare fiscală, bazat pe așezarea referinței G-Trots: antet, stare/date, furnizor/client, produse cu fotografii, instrucțiuni de plată, mențiuni, sumar TVA, total și subsolul despre documentul justificativ pentru garanție. Culori prună/roz, logo original, font Nunito Sans cu diacritice încorporat în PDF.

## Generare locală

```powershell
.\bin\php-local.ps1 bin/preview-invoice.php
```

Pe PHP configurat normal: `php bin/preview-invoice.php`. Rezultatul privat de lucru este `output/pdf/Factura-La-Mariuca-model.pdf`. Nu publica acest director; documentul conține datele reale ale firmei. Nu există rută web publică pentru generarea modelului.

`InvoicePdfPreview` citește prin repository datele curente din `companies` și configuratorul facturii la fiecare generare: denumiri, CUI/RC, sediu, contact, bancă/IBAN, capital, statut TVA/cotă, serie, zile de scadență și note implicite. Destinatarul, produsele, cantitățile și numărul 1 sunt demonstrative. Data este afișată în Europe/Bucharest. Nu se rezervă numere, nu se emit facturi și nu se modifică baza de date, comenzile sau stocul; nu se trimit notificări.

## Font și compatibilitate

- Fișierele `nunito-sans-pdf-regular.ttf` / `nunito-sans-pdf-bold.ttf` sunt instanțe statice 400/700 ale sursei existente `nunito-sans-variable.ttf`, cu axele wdth=100, opsz=12, YTLC=500; numele interne identifică variantele Regular/Bold. Licența rămâne SIL OFL, în `public/assets/fonts/OFL-Nunito-Sans.txt`.
- Generarea PDF folosește Dompdf/PHP și fișiere locale. Nu necesită browser, serviciu Node sau Python pe găzduire. Python/fontTools a fost folosit numai la pregătirea fonturilor statice.
- Cache-ul fonturilor se află în `storage/cache/invoice-pdf`, care trebuie să fie scriibil. Resursele externe și executarea PHP/JavaScript în PDF sunt dezactivate.

## Limitele acestei etape

Acesta este un model vizual, nu modulul de emitere. Viitoarea factură emisă trebuie să salveze snapshoturi pentru emitent, destinatar, produse, valori, serie, scadență și note; regenerarea se face din snapshot, nu din datele curente ale firmei. Contractul configuratorului este în `INVOICE_CONFIGURATION.md`.

Textul legal de subsol este păstrat din referința solicitată, cu adresa de contact a firmei. Modelul nu certifică transmiterea în RO e-Factura, validarea fiscală sau existența unui document emis. Acestea rămân în etapa de emitere și verificare fiscală.
