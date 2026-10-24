UPDATE product_variants
SET status = 'inactive', is_default = 0, updated_at = UTC_TIMESTAMP()
WHERE product_id = (SELECT id FROM products WHERE slug = 'cuburi-moi-poveste' LIMIT 1);

UPDATE product_variants
SET option_summary = 'Mărime: 0-6 luni · Culoare: Roșu',
    options_json = JSON_OBJECT('Mărime', '0-6 luni', 'Culoare', 'Roșu'),
    options_key = LPAD('3011', 64, '0'),
    price_bani = 14900,
    compare_price_bani = 17900,
    status = 'active',
    is_default = 1,
    updated_at = UTC_TIMESTAMP()
WHERE product_id = (SELECT id FROM products WHERE slug = 'cuburi-moi-poveste' LIMIT 1)
  AND sku = 'LM-26-030';

INSERT INTO product_variants (
    product_id, sku, option_summary, options_json, options_key, price_bani,
    compare_price_bani, status, is_default, created_at, updated_at
)
SELECT
    product.id,
    CASE
        WHEN sizes.position = 1 AND colors.position = 1 THEN 'LM-26-030'
        ELSE CONCAT('LM-26-030-', sizes.code, '-', colors.code)
    END,
    CONCAT('Mărime: ', sizes.label, ' · Culoare: ', colors.label),
    JSON_OBJECT('Mărime', sizes.label, 'Culoare', colors.label),
    LPAD(3000 + sizes.position * 10 + colors.position, 64, '0'),
    sizes.base_price + colors.surcharge,
    16900 + colors.surcharge,
    'active',
    CASE WHEN sizes.position = 1 AND colors.position = 1 THEN 1 ELSE 0 END,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
FROM products product
CROSS JOIN (
    SELECT 1 AS position, 'S01' AS code, '0-6 luni' AS label, 13900 AS base_price
    UNION ALL SELECT 2, 'S02', '0-7 luni', 13600
    UNION ALL SELECT 3, 'S03', '0-8 luni', 13500
    UNION ALL SELECT 4, 'S04', '0-9 luni', 13000
    UNION ALL SELECT 5, 'S05', '1 an–1,5 ani', 13900
    UNION ALL SELECT 6, 'S06', '0-3 luni', 13900
    UNION ALL SELECT 7, 'S07', '0-2 luni', 14900
) sizes
CROSS JOIN (
    SELECT 1 AS position, 'C01' AS code, 'Roșu' AS label, 1000 AS surcharge
    UNION ALL SELECT 2, 'C02', 'Negru', 0
    UNION ALL SELECT 3, 'C03', 'Verde', 500
    UNION ALL SELECT 4, 'C04', 'Galben', 1500
    UNION ALL SELECT 5, 'C05', 'Bej', 1200
    UNION ALL SELECT 6, 'C06', 'Alb', 0
) colors
LEFT JOIN product_variants existing
  ON existing.product_id = product.id
 AND existing.sku = CASE
      WHEN sizes.position = 1 AND colors.position = 1 THEN 'LM-26-030'
      ELSE CONCAT('LM-26-030-', sizes.code, '-', colors.code)
 END
WHERE product.slug = 'cuburi-moi-poveste'
  AND existing.id IS NULL
ORDER BY sizes.position, colors.position;

INSERT INTO inventory (variant_id, physical_quantity, reserved_quantity, updated_at)
SELECT variant.id, 10, 0, UTC_TIMESTAMP()
FROM product_variants variant
JOIN products product ON product.id = variant.product_id
LEFT JOIN inventory existing ON existing.variant_id = variant.id
WHERE product.slug = 'cuburi-moi-poveste'
  AND variant.status = 'active'
  AND existing.variant_id IS NULL;

INSERT INTO inventory_movements (
    variant_id, type, physical_delta, reserved_delta, reason, created_at
)
SELECT
    variant.id,
    'catalog_variant_seed',
    inventory.physical_quantity,
    0,
    'Variante Mărime × Culoare pentru Cuburi moi „Poveste”',
    UTC_TIMESTAMP()
FROM product_variants variant
JOIN products product ON product.id = variant.product_id
JOIN inventory ON inventory.variant_id = variant.id
LEFT JOIN inventory_movements movement ON movement.variant_id = variant.id
WHERE product.slug = 'cuburi-moi-poveste'
  AND variant.status = 'active'
  AND movement.id IS NULL;
