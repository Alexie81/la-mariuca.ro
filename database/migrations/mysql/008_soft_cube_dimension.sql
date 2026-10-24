UPDATE product_variants variant
JOIN products product ON product.id = variant.product_id
SET variant.option_summary = CONCAT(variant.option_summary, ' · Dimensiune: 1 cm'),
    variant.options_json = JSON_SET(variant.options_json, '$.Dimensiune', '1 cm'),
    variant.options_key = SHA2(CAST(JSON_SET(variant.options_json, '$.Dimensiune', '1 cm') AS CHAR), 256),
    variant.price_bani = variant.price_bani + 1000,
    variant.compare_price_bani = CASE
        WHEN variant.compare_price_bani IS NULL THEN NULL
        ELSE variant.compare_price_bani + 1000
    END,
    variant.updated_at = UTC_TIMESTAMP()
WHERE product.slug = 'cuburi-moi-poveste'
  AND variant.status = 'active'
  AND JSON_EXTRACT(variant.options_json, '$.Dimensiune') IS NULL;
