UPDATE product_variants
SET option_summary = option_summary || ' · Dimensiune: 1 cm',
    options_json = json_set(options_json, '$.Dimensiune', '1 cm'),
    options_key = printf('%064d', 400000 + id),
    price_bani = price_bani + 1000,
    compare_price_bani = CASE
        WHEN compare_price_bani IS NULL THEN NULL
        ELSE compare_price_bani + 1000
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE product_id = (SELECT id FROM products WHERE slug = 'cuburi-moi-poveste' LIMIT 1)
  AND status = 'active'
  AND json_extract(options_json, '$.Dimensiune') IS NULL;
