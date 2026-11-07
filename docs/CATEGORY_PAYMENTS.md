# Metode de plată pe categorii

## Configurare în administrare

Adăugarea și editarea categoriei includ pasul **Plată**, cu switch-uri Card online și Ramburs. Migrarea 036 adaugă `allow_card` și `allow_cod`, ambele implicit activate, inclusiv pentru categoriile existente. Disponibilitatea globală nu schimbă valorile salvate pe categorie. Minimum o metodă trebuie permisă; serverul validează această regulă și fără JavaScript.

Descrierea scurtă este obligatorie la adăugare și la fiecare editare și permite maximum 100 de caractere. Valorile lipsă, goale sau compuse exclusiv din spații (inclusiv spații Unicode) sunt respinse pe server. Fotografia categoriei rămâne opțională.

Regulile se aplică produselor categoriei și tuturor descendenților. Se verifică toate categoriile asociate produsului, categoria principală și fiecare ascendent. Selecțiile locale ale copilului nu sunt rescrise când părintele se modifică.

## Prioritate și disponibilitate

Atunci când ambele metode sunt disponibile global, în conflicte între categorii, ascendenți sau produse, restricția „numai card” are prioritate față de „numai ramburs”, conform cerinței comerciale. Dacă nu există o restricție numai card, o restricție numai ramburs exclude cardul. Fără restricții rămân ambele metode.

| Coș | Card global disponibil | Ramburs global activ | Metode finale |
| --- | --- | --- | --- |
| Toate produsele permit ambele | Da | Da | Card și ramburs |
| Numai card + ambele | Da | Da | Card |
| Numai card + numai ramburs | Da | Da | Card |
| Numai ramburs + ambele | Da | Da | Ramburs |
| Orice combinație validă, inclusiv numai card | Nu | Da | Ramburs |
| Orice combinație validă, inclusiv numai ramburs | Da | Nu | Card |
| Orice combinație validă | Nu | Nu | Nicio metodă; plasare blocată |

Setările globale se administrează din **Produse și clienți → Metode de plată**, imediat sub Livrare. Migrarea 038 introduce o singură înregistrare de setări: până la prima salvare se păstrează configurația existentă (`COD_ENABLED`, implicit `true`, respectiv `STRIPE_ENABLED`). După salvare, switchurile din CRM devin sursa disponibilității globale. Cardul necesită în continuare configurarea privată Stripe; cheia nu este afișată în CRM și nu poate fi modificată de aici.

Cu JavaScript, fiecare toggle salvează automat, fără refresh ori mesaj de succes. Cererile sunt serializate și păstrează ultima alegere dacă switchurile sunt schimbate rapid. La eroare revine ultima stare confirmată, cu mesaj vizibil deasupra opțiunilor. Fără JavaScript rămâne un submit explicit în `noscript`.

Fallbackul este simetric: când cardul nu este disponibil global, rambursul activ poate fi folosit chiar și pentru produse numai card; când rambursul este oprit global, cardul disponibil și configurat poate fi folosit chiar și pentru produse numai ramburs. Valorile salvate pe categorii se păstrează. Checkoutul blochează plasarea dacă ambele metode sunt indisponibile global; pagina setărilor explică această consecință înainte de salvare.

Produsele fără categorii permit ambele metode. Înregistrările invalide (ambele metode oprite, ascendenți inexistenți/ciclici, produs dispărut) blochează plata și cer contactarea magazinului.

## Checkout și integritate

`CategoryPaymentPolicy` furnizează aceeași decizie pentru afișare și plasare. Metodele indisponibile global sunt ascunse complet, inclusiv inputul aferent. Numai metodele active global, dar restricționate de categorii, rămân vizibile cu motiv și lista deduplicată a produselor care le limitează. `globally_available` separă cele două cazuri. Cardul are prioritate la selecția inițială; o selecție anterioară încă validă este păstrată. Fallbackul global, la ramburs sau la card, are explicație separată. Câmpurile `fallback_to_cod` și `fallback_to_stripe` indică înlocuirea metodei cerute de regulile catalogului cu alternativa disponibilă global.

La POST se recitesc regulile în tranzacție înainte de orice comandă, rezervare de stoc, cupon sau mesaj email. O metodă falsificată ori devenită indisponibilă este respinsă; serverul nu schimbă în tăcere plata aleasă. Pentru MySQL sunt folosite locking reads pe produsele, asocierile, categoriile relevante și setările globale. Modificările globale sunt autorizate server-side, protejate CSRF, salvate atomic și înregistrate în audit. Verificarea concurenței pe MySQL rămâne de efectuat pe infrastructura de găzduire.

Regulile noi se referă la checkout; nu rescriu metoda de plată a comenzilor istorice. Oprirea globală blochează inițierea unor sesiuni Stripe noi, dar webhookurile semnate continuă să proceseze confirmările plăților deja începute. Verificările semnăturii, sumei și idempotentei, precum și condițiile de expediere a confirmărilor sunt păstrate.

## Verificări

- Teste de politică: ascendenți, categorii multiple, prioritatea cardului, toate combinațiile globale, produse deduplicate, date invalide și tranzacții.
- Teste de administrare: valori implicite, salvare/editare, formulare legacy, ambele oprite respinse și independența setărilor globale.
- Teste HTTP/serviciu checkout: cerere falsificată, modificarea regulilor între GET și POST, rollback fără efecte secundare, fallback în ambele sensuri, selecție validă și markup indisponibil.
- Browser intern, bază QA izolată: adăugare/editare și salvare, ultimul switch protejat, checkboxuri accesibile ca switch-uri, checkout mixt și fallback, la 320/390/768/1440 px fără overflow orizontal. Fără Stripe sau SMTP real în aceste verificări.
