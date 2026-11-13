# Automatizări facturare

## Ce este disponibil acum

`/admin/automatizari/`, în grupul **Facturare**, după Configurator factură.

- Patru reguli independente pe metodă: emitere și email pentru card, respectiv ramburs.
- Configurarea începe cu toate regulile oprite. Emailul necesită emitere; oprirea emiterii oprește și emailul. Reactivarea emiterii nu reactivează automat emailul.
- Salvare imediată prin POST cu CSRF, numai pentru administratori/operatori autorizați; fără reîncărcare sau mesaj de succes. Fără JavaScript există buton de salvare.
- Cererile din aceeași pagină sunt serializate și păstrează ultima alegere. Revizia împiedică suprascrierea unei modificări din altă filă. La eroare se recitește starea serverului; dacă nu poate fi verificată, controalele sunt blocate până la reîncărcarea setărilor.
- Modificările se auditează. Repetarea aceleiași salvări nu creează o nouă revizie/un nou audit.

Migrarea `051_invoice_automations.sql` este disponibilă pentru SQLite și MySQL. Pagina și salvarea folosesc PHP obișnuit, fără proces Node sau worker suplimentar.

## Legătura pregătită pentru facturile de ieșire

**Emiterea și expedierea nu sunt încă pornite.** Nu există apel către `prepareForOrder()` din checkout/webhook și nu se înregistrează automat intenții pentru comenzile actuale. Pagina afișează explicit „În pregătire”.

`InvoiceAutomationService::prepareForOrder($orderId)` este punctul de integrare pentru etapa următoare. Citește comanda din baza de date și acceptă:

| Metodă | Stare comandă | Stare plată |
| --- | --- | --- |
| `stripe` | `confirmed` | `paid` |
| `cod` | `new` | `pending` sau `paid` |

O regulă oprită nu creează intenție. O regulă activă rezervă o singură intenție per comandă, cu opțiunea de email și revizia setărilor înghețate. Cheile distincte `order:{id}:invoice:issue` și `order:{id}:invoice:email` pregătesc reluarea independentă. Repetarea apelului întoarce aceeași intenție. Rezervarea nu emite factură, nu consumă număr/serie, nu modifică stocul și nu trimite email.

## De conectat și verificat odată cu facturarea

1. Apelarea rezervării la tranziția validată de comandă nouă ramburs, respectiv card plătit și confirmat de procesator. Fără declanșare din URL-ul revenirii în browser și fără facturare retroactivă la activarea switch-ului.
2. În tranzacția de emitere: protecție unică pe comandă/cheia de emitere, numerotare, snapshot configurator/firmă/client/produse și mișcări contabile. Respectarea aceleiași ordini de blocare în toate fluxurile; test separat pentru concurență MySQL. Revalidarea comenzilor anulate/returnate înainte de emitere.
3. Emailul facturii se programează separat, numai după emitere și după emailul de confirmare a comenzii, cu cheia de deduplicare dedicată. Eșecul SMTP nu anulează emiterea și retry-ul emailului nu emite încă o factură. Niciun apel SMTP în tranzacție.
4. Stabilirea mecanismului server-side compatibil cu găzduirea Apache/PHP (outbox și cron PHP, dacă este necesar); niciun proces dependent de pagina admin deschisă. Abia după integrare, înlocuirea mesajului „În pregătire” cu starea reală.
5. Probe cap-coadă pentru card reușit/eșuat/anulat, ramburs, webhook duplicat, concurență, retry, schimbarea regulilor între evenimente și erori independente de emitere/expediere.

## Verificări efectuate la 2026-11-13

- PHPUnit: `InvoiceAutomationTest` — 7 teste, 67 aserțiuni; `InvoiceConfigurationTest` — 8 teste, 81 aserțiuni.
- PHPStan pe modulul nou și AppFactory: fără erori. Template-uri Twig și sintaxa JS verificate.
- Browser intern, contul Paula, server 8080: toate switch-urile, persistență după refresh, dezactivarea perechii, tastatura Space, conflict real între două file. Setările readuse la cele patru opțiuni oprite.
- Design inspectat la 1440, 768, 390 și 320 px; fără overflow orizontal sau erori JS observate.
- Nu au fost emise facturi și nu s-au trimis emailuri. MySQL concurent, Apache producție și serviciile live nu au fost testate. Etapa facturilor de ieșire rămâne nebifată.
