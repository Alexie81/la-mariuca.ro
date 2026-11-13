# Configurator factură

Pagina: `/admin/configurator-factura/`, în grupul **Facturare**, între **Activitate zilnică** și **Produse și clienți**.

## Reguli confirmate de utilizator

- Există exact o serie în folosire. Alegerea alteia afectează **următoarea factură emisă**, nu documentele deja emise. Seria istorică se fixează la emitere; drafturile nu rezervă această identitate.
- Inițial există seria editabilă `LM`, scadență 0 zile și note goale. Nu se creează facturi demonstrative.
- Se pot adăuga, edita, selecta sau elimina serii. O serie nefolosită rămâne disponibilă pentru selectare ulterioară.
- Pentru nefolosirea/ștergerea seriei active trebuie aleasă o înlocuitoare în aceeași operație. Ultima serie nu se poate șterge.
- Notele sunt text simplu, maximum 3000 de caractere. Scadența este între 0 și 3650 de zile calendaristice; 0 înseamnă ziua emiterii.
- Seria, numărul și setările unei facturi emise sunt istorice. Schimbarea configuratorului nu le rescrie.

## Persistență și integrarea ulterioară cu facturile de ieșire

Migrarea `050_invoice_configuration.sql` există pentru SQLite și MySQL. `invoice_configuration.active_series_id` este alegerea unică; seriile eliminate sunt arhivate intern. Mutările și înghețarea datelor unei facturi folosesc același lock tranzacțional. Revizia formularului împiedică suprascrierile provenite din ferestre vechi. Operațiile administrative sunt jurnalizate.

Modulul de emitere a facturilor de ieșire este încă restant în roadmap. Configuratorul **nu emite facturi**, nu consumă stoc și nu trimite emailuri. Contractul pregătit pentru emitere este:

1. În tranzacția care emite efectiv factura, apelează `InvoiceConfigurationService::freezeForInvoice($documentKey, $number, $issuedAt)`. Cheia trebuie să fie stabilă și unică pentru document, nu pentru descărcarea PDF. Modulul de emitere alocă numărul în mod atomic pe seria curentă, în aceeași tranzacție și cu aceeași ordine a lockurilor; nu trebuie să calculeze numărul în afara tranzacției, înainte de blocarea configurației.
2. Se păstrează în `invoice_configuration_snapshots` seria textuală, denumirea, numărul facturii, zilele de scadență, notele implicite, momentul UTC al emiterii, scadența calendaristică din Europe/Bucharest și revizia configurației.
3. La reîncercarea aceleiași emiteri, cheia întoarce exact datele deja salvate, fără alocare nouă. La eșecul tranzacției exterioare, și snapshotul se anulează.
4. La vizualizare, PDF, Excel, email sau regenerare, se citește **exclusiv** `snapshotForInvoice($documentKey)` pentru identitate și setările istorice. Nu se reconstruiește seria din `invoice_series` sau din configurația curentă. Dacă lipsește snapshotul unui document pretins emis, eroarea trebuie investigată, fără completare automată din setările curente.
5. Datele specifice facturii (client, linii, prețuri, eventuale note adaptate la emitere) se păstrează pe factura însăși. Nu se actualizează din configurator la regenerare. O corecție fiscală/renumerotare este un flux separat, care nu este autorizat de schimbarea configuratorului.
6. Codurile șterse pot fi adăugate din nou, însă perechea `(series_code, invoice_number)` rămâne unică în istoricul emiterilor. Alocatorul viitor trebuie să continue după ultimul număr istoric al codului, inclusiv dacă înregistrarea seriei are un ID nou. Modificarea etichetei sau codului nu elimină această protecție.

## Verificări

`tests/Integration/InvoiceConfigurationTest.php` acoperă seriile și înlocuirea obligatorie, protecția ultimei serii, coduri duplicate, input invalid, date istorice după editare/ștergere, idempotenta, numere nereutilizabile, rollback în tranzacția facturii, scadența la trecerea datei UTC/București, revizii vechi, autorizare, CSRF, escaparea HTML, HTTP/AJAX și formulare fără JavaScript.

Verificarea funcțională a emiterii și regenerării unei **facturi finale reale** va avea loc la implementarea modulului de facturi de ieșire. Testele curente verifică mecanismul de păstrare a datelor, nu un flux de facturare care încă nu există. MySQL/Apache și concurența MySQL vor fi verificate pe infrastructura țintă; nu sunt declarate validate prin testele locale SQLite.
