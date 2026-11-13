# Automatizări facturare

## Configurare
`/admin/automatizari/`, în grupul **Facturare**, după Configurator factură.

- Patru reguli: emitere și email pentru card, respectiv ramburs. Configurarea inițială le păstrează oprite.
- Emailul necesită emitere; oprirea emiterii oprește și emailul. Reactivarea emiterii nu reactivează automat emailul.
- Salvare imediată cu CSRF și autorizare, fără reîncărcare sau mesaj de succes. Fallback cu buton de salvare fără JavaScript.
- Cererile aceleiași pagini sunt serializate, iar revizia previne suprascrierea unei modificări din altă filă. Modificările sunt auditate; erorile nu pierd starea serverului.
- Schimbarea regulilor se aplică numai evenimentelor viitoare, fără emitere retroactivă.

## Fluxul conectat la comenzi
Migrațiile 051, 052 și 053 sunt necesare. Checkoutul ramburs și confirmarea Stripe semnată rezervă intenția de facturare în tranzacția comenzii. Procesarea facturii începe după commit.

| Metodă | Momentul rezervării intenției |
| --- | --- |
| `stripe` | Comandă `confirmed`, plată `paid`, confirmate de procesator |
| `cod` | Comandă `new`, plată `pending` sau `paid` |

`InvoiceAutomationService::prepareForOrder()` păstrează o singură intenție per comandă, cu setările de emitere/email înghețate. Nu consumă număr și nu transmite email. `InvoiceModule::process()` revalidează comanda și emite tranzacțional, protejat prin chei unice și blocare. Redirectul Stripe din browser nu emite factura; plățile eșuate/anulate nu generează comenzi noi facturabile.

Factura păstrează seria, numărul, firma, clientul, pozițiile și valorile istorice. La reluarea aceleiași intenții se refolosește factura emisă. Eroarea de facturare nu anulează comanda sau plata deja confirmată.

## Emailul este separat
Emailul facturii se pune în outbox cu PDF și cheie proprie de deduplicare. Procesorul încearcă mai întâi emailul de confirmare a comenzii; eșecul definitiv al acestuia nu suprimă factura. SMTP rulează în afara tranzacțiilor contabile. Un email reîncercat nu emite altă factură.

Amprenta documentului împiedică expedierea unei versiuni schimbate între programare și trimitere. După editare se pregătește un email nou pentru varianta actuală. CTA-urile și imaginile folosesc configurația publică, conform checklistului de deployment.

## Apache/shared hosting
Comenzile PHP se pot executa din cron, fără serviciu Node sau aplicație desktop deschisă:

```sh
php /cale/proiect/bin/console.php invoices:process 20
php /cale/proiect/bin/console.php outbox:process
```

Prima comandă reia intențiile în așteptare/eșuate (cel mult cinci tentative), a doua procesează emailurile. Configurarea și verificarea cronului pe hosting sunt pași separați înainte de producție. Dacă `MAIL_MESSAGE_TYPES` filtrează tipurile, trebuie incluse `invoice`, `invoice_return_notice` și `order_confirmation`.

## Verificări
- Setările: 7 teste / 67 aserțiuni; browserul principal 8080 cu Paula a verificat salvarea, dependențele, tastatura și conflictele între file.
- Facturare: 21 teste / 105 aserțiuni; emitere, replay, refuzul cardului neplătit, erori/retry independente, snapshoturi, bani, stoc, corecții, retururi și acces. Suita completă locală: 729 teste / 7214 aserțiuni.
- Probe vizuale și emiteri numai într-o bază QA separată. Nu au fost emise facturi în baza principală și nu s-au trimis emailuri reale.
- MySQL concurent, Apache producție, livrarea SMTP/push și fiscalizarea externă rămân de verificat efectiv. Etapa din roadmap se bifează numai la confirmarea utilizatorului.
