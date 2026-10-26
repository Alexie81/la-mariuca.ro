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
