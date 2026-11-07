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

În modul preview, rezultatele sunt scrise privat în `storage/private/mail/<id>.txt` și `.html`; în modul SMTP, transportul configurat primește ambele variante. `MAIL_DELIVERY_MODE=smtp` permite expedierea explicită și local, fără schimbarea `APP_ENV`. Confirmările folosesc `OrderConfirmationEmail`, cu produse, livrare, plăți și estimare; activarea, filtrele cozii și workerul sunt documentate în `ORDER_EMAILS.md`. Pentru actualizări de status sau alt șablon se poate folosi `OrderTrackingEmail::withTracking($body, $orderId, $recipient)`. Un test autorizat a fost acceptat de SMTP; poziționarea în inbox și randarea în client nu au fost verificate.

## Datele statusurilor

- Intrarea în `new` folosește `orders.created_at`.
- Confirmarea, pregătirea, expedierea, livrarea și anularea administrativă folosesc evenimentele `order.confirm`, `order.prepare`, `order.ship`, `order.deliver`, `order.cancel` din `audit_log`, scrise în aceeași tranzacție cu schimbarea statusului.
- Returul complet folosește `order.return_full`. Returul parțial nu este o nouă stare a comenzii.
- Confirmarea/anularea automată Stripe și plata tardivă care intră în verificare manuală scriu acum evenimente de status în aceeași tranzacție. Repetarea unui webhook nu mută data intrării în status.
- Eșecul inițializării plății folosește `stripe.session_failed` numai când statusul chiar s-a schimbat. Reîncercările în aceeași stare nu suprascriu data inițială.
- `shipped_at` și `cancelled_at` sunt fallbackuri reale pentru istoricul vechi, inclusiv anularea prin expirarea rezervării. `updated_at` nu este folosit ca dată de intrare în status: poate proveni din modificarea altor date.
- Persistența rămâne UTC; toate datele și orele se afișează cu fusul `Europe/Bucharest`, inclusiv schimbarea orei de vară/iarnă.
- Pentru etapele viitoare se afișează „În așteptare”. Pentru stările istorice fără dată păstrată apare „Dată neînregistrată”; datele nu se reconstruiesc prin presupunere. Unele comenzi demo vechi au fost create direct într-un status și nu au evenimente de tranziție.

## Cereri de anulare

Legătura discretă de sub „Livrare și contact” deschide un formular modal cu motiv obligatoriu. Fără JavaScript, aceeași adresă `GET /urmarire-comanda/solicitare-anulare/` oferă formularul ca pagină normală. Trimiterea folosește POST, CSRF și autorizarea comenzii din sesiunea de urmărire; un `order_id` trimis de browser nu selectează altă comandă.

- Sunt eligibile numai `new`, `confirmed` și `preparing`. Limita este strict `acum < created_at + 432000 secunde`, adică exact 120 de ore; la împlinirea celor 5 zile nu mai este permisă o cerere nouă. Calculul folosește timpul UTC, independent de trecerea la ora de vară/iarnă. Interfața afișează termenul în Europe/Bucharest.
- Eligibilitatea este verificată din nou în tranzacție, după blocarea rândului comenzii în MySQL. Formularul deschis nu prelungește termenul. În browser, la expirare se ascunde legătura și se dezactivează trimiterea; serverul rămâne autoritatea inclusiv pentru pagini vechi sau JavaScript dezactivat.
- Motivul are 5–1.000 de caractere, este păstrat ca text și escapat la afișare. Se admit cel mult 10 încercări pentru aceeași comandă în 15 minute. Constrângerea unică pe `order_id` și răspunsul idempotent împiedică dublarea cererii la reîncercare. Motivul inițial nu este suprascris.
- Migrarea `034_order_cancellation_requests.sql` păstrează cererea, starea comenzii la trimitere și momentul UTC. Evenimentul `order.cancellation_requested` este scris în același commit și include `request_id` și sursa `customer_tracking`.
- În CRM apar notificare la „Comenzi”, marcaj în tabel, filtrul „Cu cerere de anulare”, motivul complet în pagina comenzii și eveniment în istoric. Notificarea folosește mecanismul intern existent; nu se trimite automat email sau push extern.

Cererea este numai înregistrarea intenției clientului. Nu schimbă statusul comenzii, plățile, rezervările de stoc, cupoanele și nu emite documente. Pentru integrarea viitoare, `request_id` este referința stabilă către motiv și moment; aprobarea/refuzul, auditul deciziei, rambursarea Stripe și documentele fiscale trebuie implementate explicit, separat de `requestCancellation()`. Un apel către Stripe/SMTP nu trebuie ținut într-o tranzacție SQL. Nu există încă o acțiune care să aprobe cererea și să ramburseze automat banii.

## Facturi private

Migrarea nouă `033_order_documents.sql` există pentru SQLite și MySQL. PDF-urile sunt păstrate exclusiv în `storage/private/invoices/`, în afara webrootului.

Sistemul de facturare trebuie să salveze mai întâi PDF-ul privat și apoi să apeleze:

```php
$tracking->registerInvoice($orderId, 'LM-123', '2026/factura-123.pdf');
```

Calea este relativă la directorul de facturi. Serviciul verifică existența fișierului, izolarea în directorul privat și semnătura PDF. Linkul „Descarcă factura” apare numai pentru documente existente, valide și nerevocate. Descărcarea verifică sesiunea și apartenența facturii la comandă; un ID ghicit nu acordă acces. Pentru revocare, integrarea setează `order_documents.revoked_at` în UTC.

Nu se emite o factură fictivă din datele comenzii. Conectarea unui furnizor real de facturare ori încărcarea administrativă a facturilor rămâne o integrare separată.

## Verificări înainte de producție

Aplicarea migrării MySQL, comportamentul pe Apache/shared hosting și livrarea emailurilor prin transportul real trebuie verificate în mediul țintă. Nu s-a făcut deploy și nu s-au inițiat plăți sau emailuri reale pentru această funcționalitate.
