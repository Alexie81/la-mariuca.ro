# Surse de date publice

## Nomenclatorul localităților din România

- Sursă: Institutul Național de Statistică, prin data.gov.ro
- Set: SIRUTA S1 2026
- Resursă: `siruta_s1_2026.csv`
- URL: https://data.gov.ro/dataset/721c9059-5f87-4c79-9854-a1d5c18f58d5/resource/dac903f0-32b5-489a-89e7-2c80d96cf68d/download/siruta_s1_2026.csv
- Licență declarată de portal: Creative Commons Attribution 4.0
- Utilizare: generarea selectorului local de județe, localități și sectoare București pentru adrese și checkout.

Fișierul public optimizat este `public/assets/data/romania-localities.json`. Se regenerează explicit cu:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bin/php-local.ps1 bin/build-romania-locations.php
```

Generatorul păstrează în JSON sursa, versiunea registrului, licența și numărul de înregistrări. Actualizarea nomenclatorului trebuie verificată și comisă; aplicația nu descarcă date de la INS în timpul unei cereri publice.

## Căutarea după cod poștal

- Sursă: GeoNames, exportul de coduri poștale pentru România, descărcat la 3 noiembrie 2026.
- Resursă: https://download.geonames.org/export/zip/RO.zip
- Documentație și licență: https://download.geonames.org/export/zip/readme.txt — Creative Commons Attribution 4.0, https://creativecommons.org/licenses/by/4.0/.
- Credit: date poștale © GeoNames, https://www.geonames.org/; denumiri canonice de localități din INS SIRUTA S1 2026. Nu se implică aprobarea magazinului de către furnizorii datelor.
- Transformări: se păstrează codurile de șase cifre și numai perechile județ/localitate recunoscute exact de nomenclatorul SIRUTA; diacriticele sunt normalizate, fără fuzzy matching sau aproximare pe prefix. Sectoarele București provin din câmpul administrativ explicit al sursei. Codurile cu o destinație nereconciliată sunt omise integral, iar codurile cu mai multe destinații păstrează toate variantele.
- Precizie: județ și localitate/sector. Exportul nu conține stradă, număr, bloc sau apartament; aceste câmpuri rămân de completat de client. Datele sunt furnizate de GeoNames fără garanția actualității sau exhaustivității; rezultatul este o sugestie editabilă, nu validarea adresei de livrare.

Indexul `resources/data/romania-postal-codes.json` se livrează cu aplicația și este interogat local. Nici codul poștal, nici adresa clientului nu sunt trimise unui serviciu extern. Atribuirea GeoNames apare lângă câmpul din checkout. Regenerare explicită, urmată de verificare și commit:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bin/php-local.ps1 bin/build-romania-postal-codes.php
```

Generatorul necesită extensia `zip` numai la descărcare sau acceptă drept argument un `RO.txt` deja extras. Păstrează sursa, data, SHA-256 și numărul de coduri în metadatele JSON. Poșta Română oferă verificarea manuală actuală la https://www.posta-romana.ro/ccp.html. Setul oficial https://data.gov.ro/dataset/coduri-postale-romania este licențiat OGL-ROU-1.0, dar resursele publicate identificate sunt din 2013/2016; nu este folosit pentru precompletarea străzilor.
