# Notificările interne pentru comenzile noi

## Destinatari și declanșare

Setările sunt comune magazinului și se deschid din butonul de setări al registrului de comenzi. Formularul acceptă până la zece adrese distincte, normalizate și validate pe server. Salvarea este atomică; o adresă invalidă nu înlocuiește parțial lista existentă. Lista goală oprește emailurile interne noi, fără a opri confirmarea clientului sau notificările push.

Rutele `/admin/comenzi/notificari/` și `/admin/comenzi/notificari/modal/` sunt protejate prin autentificarea administratorului; salvarea folosește POST și CSRF. Formularul are și o pagină completă pentru funcționarea fără JavaScript.

La o comandă ramburs, notificarea internă se înregistrează împreună cu comanda. La card se așteaptă confirmarea validă a plății de la procesator, cu verificările existente de sumă și monedă. O plată tardivă pentru o comandă în verificare este afișată explicit ca necesitând verificare, fără promisiunea pregătirii. Comenzile anulate/returnate și cardul încă neplătit nu generează un email care ar anunța fals o comandă nouă.

`InternalOrderNotificationService` înregistrează câte un mesaj `internal_order_notification` în outbox pentru fiecare destinatar. Fiecare adresă primește propriul email, fără lista celorlalți destinatari în To/CC/BCC. Adresa din mesajul persistent este snapshotul autorizat pentru expediere; modificarea ulterioară a setărilor nu redirecționează un email deja în așteptare.

## Emailul intern

`InternalOrderEmail` produce subiectul „Ai primit o nouă comandă! [număr]”, HTML-ul și alternativa text. Template-ul intern moștenește designul emailului clientului, iar `OrderEmailViewData` pregătește datele comune: produse fotografiate, SKU, cantitate, preț unitar, totaluri, reduceri și estimarea livrării. Valorile provin din comanda persistentă, nu din prețurile curente ale catalogului. Data UTC este afișată în Europe/Bucharest, inclusiv pentru valorile MySQL fără offset explicit.

Conținutul este în ordinea:

1. Logo, anunțul comenzii și valoarea sa, butonul „Deschide comanda”, numărul și data/ora.
2. Produsele și rezumatul financiar, cu „Reduceri aplicate” numai când există o reducere pozitivă.
3. Metoda și starea plății.
4. Datele de livrare, numele, telefonul și emailul clientului, metoda și estimarea configurată.
5. Copyrightul magazinului.

Footerul comercial, linkurile pentru consumatori și siglele ANPC/CAB IT ale emailului clientului nu apar în notificarea internă. Emailul către client își păstrează conținutul, footerul și linkul privat de urmărire.

CTA-ul intern folosește `APP_URL` + `APP_BASE_PATH` + `/admin/comenzi/{id}/`. Este un URL administrativ obișnuit, care necesită autentificare și autorizare; nu include tokenul public de urmărire al clientului. Destinatarul configurat nu primește implicit un cont sau drepturi administrative.

HTML-ul folosește layoutul fluid de 760 px al emailului existent, tabele de prezentare, stiluri inline, media queries și CTA VML pentru Outlook clasic. Fotografiile folosesc miniaturile JPEG existente. Imaginile locale validate sunt atașate inline prin CID la expediere. Nu sunt necesare JavaScript, fonturi externe sau descărcarea de imagini de pe servicii terțe.

## Expediere și verificare

Procesorul `OutboxProcessor` acceptă tipul `internal_order_notification`. Pentru o configurație care filtrează tipurile, `MAIL_MESSAGE_TYPES` trebuie să includă atât `order_confirmation`, cât și `internal_order_notification`. Se păstrează pragul existent `MAIL_SEND_AFTER_ID`; acesta nu se recalculează la restart.

Un eșec de expediere la o adresă nu împiedică procesarea celorlalți destinatari sau emailul clientului. Reîncercarea se aplică numai mesajului eșuat, cu politica existentă de maximum cinci încercări și pauză de cinci minute. SMTP nu se apelează în tranzacția bazei de date. După acceptarea SMTP urmată de o eroare de persistare, mesajul necesită verificare manuală și nu este retrimis automat.

Testele dedicate verifică datele și ordinea secțiunilor, linkul administrativ fără token de client, escapingul, cardul plătit/neplătit, comanda în verificare, datele UTC, reducerile, lipsa footerului comercial și izolarea eșecurilor între destinatari folosind un transport fără rețea. Previzualizările vizuale nu certifică randarea în inboxuri reale Gmail/Outlook. Configurarea SMTP, verificarea inboxului și verificarea push efectivă se raportează separat, numai dacă au fost executate.
