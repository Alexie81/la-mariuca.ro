# Emailul de primire / confirmare a comenzii

## Conținut și declanșare

`OrderConfirmationEmail` construiește subiectul, varianta HTML și alternativa text folosind datele persistate ale comenzii. Sumele sunt formatate din bani întregi, nu recalculate din prețurile curente ale catalogului.

| Situație | Subiect | Mesaj | Metoda / starea plății |
| --- | --- | --- | --- |
| Ramburs | Comanda [număr] a fost primită cu succes | Comanda a fost plasată cu succes. | Ramburs — De achitat la livrare |
| Card plătit | Comanda [număr] a fost confirmată cu succes | Comanda a fost confirmată cu succes. | Card online — Plătit |
| Plată tardivă, comandă în verificare | Comanda [număr] — plata a fost primită | Plata a fost primită. Verificăm comanda. | Card online — Plătit |

La ramburs, checkoutul înregistrează `order_confirmation` în aceeași tranzacție cu comanda. Pentru card, mesajul este înregistrat numai după webhookul Stripe valid și plătit, cu suma și moneda corecte. Repetările evenimentului și evenimentele distincte pentru aceeași plată nu adaugă încă o confirmare. Rendererul refuză confirmarea unui card neplătit sau a unei comenzi anulate/returnate.

Procesorul existent `outbox:process` folosește noul renderer pentru confirmări; emailurile administrative de actualizare a statusului păstrează rendererul lor. Expeditorul are numele `MAIL_FROM_NAME`, iar răspunsurile merg la `CONTACT_EMAIL`. Procesorul verifică și faptul că a revendicat mesajul înainte de a-l procesa.

## Design și imagini

- Template-uri: `resources/views/emails/order-confirmation.twig` și `order-confirmation-text.twig`.
- Compoziție fluidă de maximum 760 px, fonturi sans-serif, carduri rotunjite separate, prețuri lizibile și CTA „Urmărește comanda”. Pe telefon, identitatea produsului ocupă tot spațiul de lângă fotografie; cantitatea/prețul unitar și totalul sunt într-un rând separat.
- Logo-ul este originalul `logo-la-mariuca-transparent.png`, folosit și în magazin. Fișierele sursă din `logo-uri/` nu sunt modificate.
- Footerul are trei coloane pe desktop (contact, informații, partener tehnic CAB IT) și secțiuni centrate pe telefon, cu linkuri în capsule compacte. Include sigla ANPC–SAL existentă cu linkul `https://anpc.ro/sal/`, creditul de dezvoltare/suport/întreținere CAB IT și un sigiliu propriu „Grijă pentru clienți”. Marca CAB IT este aceeași zonă 34×34 folosită în site, exportată în `cab-it-mark.png` pentru a evita decuparea CSS în clienții de email; originalul este păstrat. Sigiliul este un element de identitate, nu o certificare, rating sau garanție acordată de un terț. URL-urile paginilor și imaginilor locale respectă calea de bază a aplicației; alternativa text include și resursele footerului.
- `OrderEmailImages` generează miniaturi JPEG în `public/media/email/` din fotografii locale validate, pentru a nu depinde de suportul WebP în clientul de email. Fișierele sursă rămân intacte. Dacă imaginea lipsește, template-ul afișează un placeholder text, fără imagine cu URL gol.
- Structura folosește tabele de prezentare, stiluri inline, dimensiuni explicite ale imaginilor, media queries și CTA VML pentru Outlook clasic. Nu depinde de JavaScript, flex/grid, fonturi externe sau imagini de fundal. Preferința light este declarată; clientul de email poate totuși transforma culorile în dark mode.
- Compatibilitatea a fost proiectată pe baza [proprietăților CSS documentate pentru Gmail](https://developers.google.com/workspace/gmail/design/css) și [limitărilor de randare documentate de Microsoft](https://learn.microsoft.com/en-us/dynamics365/customer-insights/journeys/email-troubleshoot-rendering). Outlook clasic poate afișa colțurile tabelelor fără rotunjire. Capturile din browser nu certifică randarea în inboxuri reale.

Toate URL-urile sunt construite din `APP_URL` + `APP_BASE_PATH`. În producție acestea trebuie să indice domeniul HTTPS public, inclusiv pentru logo și miniaturi. CTA folosește linkul privat HMAC existent și validează destinatarul înainte de a reda informații despre comandă; consultați `ORDER_TRACKING.md`.

## Termenul estimat și extensia dinamică

Migrarea `035_order_delivery_estimate.sql` adaugă `orders.shipping_estimate_json`. Checkoutul păstrează intervalul configurat al metodei de livrare, tipul metodei, baza de calcul și versiunea snapshotului. Modificarea ulterioară a metodei în CRM nu schimbă estimarea comunicată pentru o comandă nouă deja plasată.

`StoredDeliveryEstimate` afișează intervalul în zile lucrătoare de la predarea coletului către curier, respectiv de la confirmarea pregătirii pentru ridicare. Nu inventează date calendaristice. Pentru comenzile istorice fără snapshot se folosesc setările metodei dacă aceasta mai poate fi identificată; dacă informația lipsește, estimarea nu apare.

Pentru calcul dinamic se injectează o implementare `DeliveryEstimateProvider` în `OrderConfirmationEmail`. Metoda `forOrder(array $order)` primește inclusiv adresa, data și snapshotul și întoarce `label` și `detail`, sau `null`. Astfel se pot integra ulterior localitatea, sărbătorile, orele-limită ori un răspuns al curierului fără schimbarea HTML-ului. Datele dinamice trebuie să aibă fallbackul snapshotului; apelurile externe nu trebuie făcute în tranzacția checkoutului. Nu există încă o integrare cu API-ul unui curier sau un calendar de sărbători.

## Verificare și activare

Au fost verificate local variantele ramburs/card, intervalele de livrare, calculele reducerilor, imaginile, escapingul, legătura privată și lipsa confirmărilor premature/duplicate. Preview-urile vizuale au folosit clienți și comenzi fictive într-o bază SQLite separată, fără a procesa outboxul real.

În `development`, procesorul continuă să salveze emailurile în `storage/private/mail`; nu expediază prin SMTP. Pentru producție sunt necesare migrarea 035, fișierele publice, URL-ul HTTPS corect, SMTP și cronul autorizate. Trimiterea reală și verificările în Gmail/Outlook (desktop, mobil, dark mode și imagini blocate) rămân de efectuat separat. Nu s-a făcut deploy și nu s-au testat MySQL/Apache sau Stripe live prin această implementare.
