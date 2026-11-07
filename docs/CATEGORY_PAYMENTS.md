# Metode de plată pe categorii

## Configurare în administrare

Adăugarea și editarea categoriei includ pasul **Plată**, cu switch-uri Card online și Ramburs. Migrarea 036 adaugă `allow_card` și `allow_cod`, ambele implicit activate, inclusiv pentru categoriile existente. Disponibilitatea globală nu schimbă valorile salvate pe categorie. Minimum o metodă trebuie permisă; serverul validează această regulă și fără JavaScript.

Regulile se aplică produselor categoriei și tuturor descendenților. Se verifică toate categoriile asociate produsului, categoria principală și fiecare ascendent. Selecțiile locale ale copilului nu sunt rescrise când părintele se modifică.

## Prioritate și disponibilitate

În conflicte între categorii, ascendenți sau produse, restricția „numai card” are prioritate față de „numai ramburs”, conform cerinței comerciale. Dacă nu există o restricție numai card, o restricție numai ramburs exclude cardul. Fără restricții rămân ambele metode.

| Coș | Card global disponibil | Metode finale, cu ramburs global activ |
| --- | --- | --- |
| Toate produsele permit ambele | Da | Card și ramburs |
| Numai card + ambele | Da | Card |
| Numai card + numai ramburs | Da | Card |
| Numai ramburs + ambele | Da | Ramburs |
| Orice combinație validă | Nu | Ramburs |

Cardul global este disponibil când `STRIPE_ENABLED=true` și cheia este configurată. `COD_ENABLED` este implicit `true`. Când cardul nu este disponibil global, rambursul reprezintă excepția permisă chiar și pentru produse numai card. Dacă rambursul este dezactivat global, nu se reactivează automat și nu există fallback invers inventat către card. Checkoutul blochează plasarea dacă nu mai rămâne nicio metodă.

Produsele fără categorii permit ambele metode. Înregistrările invalide (ambele metode oprite, ascendenți inexistenți/ciclici, produs dispărut) blochează plata și cer contactarea magazinului.

## Checkout și integritate

`CategoryPaymentPolicy` furnizează aceeași decizie pentru afișare și plasare. Metodele indisponibile rămân vizibile, cu motiv și lista deduplicată a produselor care le limitează. Cardul are prioritate la selecția inițială; o selecție anterioară încă validă este păstrată. Fallbackul global la ramburs are explicație separată.

La POST se recitesc regulile în tranzacție înainte de orice comandă, rezervare de stoc, cupon sau mesaj email. O metodă falsificată ori devenită indisponibilă este respinsă; serverul nu schimbă în tăcere plata aleasă. Pentru MySQL sunt folosite locking reads pe produsele, asocierile și categoriile relevante. Verificarea concurenței pe MySQL rămâne de efectuat pe infrastructura de găzduire.

Regulile noi se referă la checkout; nu rescriu metoda de plată a comenzilor istorice. Nu schimbă procesarea webhookurilor Stripe sau condițiile de expediere a confirmărilor.

## Verificări

- Teste de politică: ascendenți, categorii multiple, prioritatea cardului, toate combinațiile globale, produse deduplicate, date invalide și tranzacții.
- Teste de administrare: valori implicite, salvare/editare, formulare legacy, ambele oprite respinse și independența setărilor globale.
- Teste HTTP/serviciu checkout: cerere falsificată, modificarea regulilor între GET și POST, rollback fără efecte secundare, fallback, selecție validă și markup indisponibil.
- Browser intern, bază QA izolată: adăugare/editare și salvare, ultimul switch protejat, checkboxuri accesibile ca switch-uri, checkout mixt și fallback, la 320/390/768/1440 px fără overflow orizontal. Fără Stripe sau SMTP real în aceste verificări.
