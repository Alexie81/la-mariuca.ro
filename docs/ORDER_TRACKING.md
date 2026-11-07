# Urmărirea publică a comenzilor

Pagina `/urmarire-comanda/` poate fi accesată din „Informații” în footer. Nu cere un cont. Clientul introduce numărul complet al comenzii și emailul folosit la checkout. Ambele trebuie să corespundă aceleiași comenzi.

## Acces și confidențialitate

- Formularul folosește POST, CSRF și o limită de 12 încercări pe IP în 15 minute. Mesajul de eroare este identic pentru număr necunoscut și email nepotrivit.
- După verificare, autorizarea unei singure comenzi este păstrată în sesiune 30 de minute. URL-ul paginii rămâne curat, fără email sau date de contact. „Caută altă comandă” închide acest acces.
- `OrderTrackingService::emailUrl($orderId, $recipient)` generează o adresă privată semnată HMAC-SHA256, legată de comandă, emailul ei și `public_token_hash`. `APP_KEY` este obligatorie. Linkul acordă aceeași sesiune temporară și redirecționează la pagina curată.
- Linkul din email nu expiră automat; poate fi revocat prin rotirea tokenului public al comenzii sau a cheii aplicației. Rotirea `APP_KEY` invalidează toate linkurile existente. O sesiune deja acordată expiră după cele 30 de minute.
- Pagina, redirecționările și PDF-urile au `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` și `X-Robots-Tag: noindex, nofollow`. Linkurile private nu trebuie publicate sau trimise în analytics; logurile infrastructurii trebuie tratate ca date private.

## Emailuri

Procesorul `outbox:process` adaugă automat butonul „Urmărește comanda” în emailurile `order_confirmation` și `order_status_update`, plus alternativa text. Destinatarul trebuie să fie emailul comenzii. Textul și URL-ul sunt escapate în HTML.

În modul local, rezultatele sunt scrise privat în `storage/private/mail/<id>.txt` și `.html`; în producție, transportul configurat primește ambele variante. Această modificare nu configurează și nu verifică SMTP live. Pentru alt șablon de email se poate folosi `OrderTrackingEmail::withTracking($body, $orderId, $recipient)`.

## Datele statusurilor

- Intrarea în `new` folosește `orders.created_at`.
- Confirmarea, pregătirea, expedierea, livrarea și anularea administrativă folosesc evenimentele `order.confirm`, `order.prepare`, `order.ship`, `order.deliver`, `order.cancel` din `audit_log`, scrise în aceeași tranzacție cu schimbarea statusului.
- Returul complet folosește `order.return_full`. Returul parțial nu este o nouă stare a comenzii.
- Confirmarea/anularea automată Stripe și plata tardivă care intră în verificare manuală scriu acum evenimente de status în aceeași tranzacție. Repetarea unui webhook nu mută data intrării în status.
- Eșecul inițializării plății folosește `stripe.session_failed` numai când statusul chiar s-a schimbat. Reîncercările în aceeași stare nu suprascriu data inițială.
- `shipped_at` și `cancelled_at` sunt fallbackuri reale pentru istoricul vechi, inclusiv anularea prin expirarea rezervării. `updated_at` nu este folosit ca dată de intrare în status: poate proveni din modificarea altor date.
- Persistența rămâne UTC; toate datele și orele se afișează cu fusul `Europe/Bucharest`, inclusiv schimbarea orei de vară/iarnă.
- Pentru etapele viitoare se afișează „În așteptare”. Pentru stările istorice fără dată păstrată apare „Dată neînregistrată”; datele nu se reconstruiesc prin presupunere. Unele comenzi demo vechi au fost create direct într-un status și nu au evenimente de tranziție.

## Facturi

Migrarea nouă `033_order_documents.sql` există pentru SQLite și MySQL. PDF-urile sunt păstrate exclusiv în `storage/private/invoices/`, în afara webrootului.

Sistemul de facturare trebuie să salveze mai întâi PDF-ul privat și apoi să apeleze:

```php
$tracking->registerInvoice($orderId, 'LM-123', '2026/factura-123.pdf');
```

Calea este relativă la directorul de facturi. Serviciul verifică existența fișierului, izolarea în directorul privat și semnătura PDF. Linkul „Descarcă factura” apare numai pentru documente existente, valide și nerevocate. Descărcarea verifică sesiunea și apartenența facturii la comandă; un ID ghicit nu acordă acces. Pentru revocare, integrarea setează `order_documents.revoked_at` în UTC.

Nu se emite o factură fictivă din datele comenzii. Conectarea unui furnizor real de facturare ori încărcarea administrativă a facturilor rămâne o integrare separată.

## Verificări înainte de producție

Aplicarea migrării MySQL, comportamentul pe Apache/shared hosting și livrarea emailurilor prin transportul real trebuie verificate în mediul țintă. Nu s-a făcut deploy și nu s-au inițiat plăți sau emailuri reale pentru această funcționalitate.
