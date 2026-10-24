UPDATE product_variants
SET status = 'inactive', is_default = 0, updated_at = CURRENT_TIMESTAMP
WHERE product_id = (SELECT id FROM products WHERE slug = 'cuburi-moi-poveste' LIMIT 1);

UPDATE product_variants
SET option_summary = 'Mărime: 0-6 luni · Culoare: Roșu',
    options_json = '{"Mărime":"0-6 luni","Culoare":"Roșu"}',
    options_key = '0000000000000000000000000000000000000000000000000000000000003011',
    price_bani = 14900,
    compare_price_bani = 17900,
    status = 'active',
    is_default = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE product_id = (SELECT id FROM products WHERE slug = 'cuburi-moi-poveste' LIMIT 1)
  AND sku = 'LM-26-030';

WITH
sizes(position, code, label, base_price) AS (
    VALUES
        (1, 'S01', '0-6 luni', 13900),
        (2, 'S02', '0-7 luni', 13600),
        (3, 'S03', '0-8 luni', 13500),
        (4, 'S04', '0-9 luni', 13000),
        (5, 'S05', '1 an–1,5 ani', 13900),
        (6, 'S06', '0-3 luni', 13900),
        (7, 'S07', '0-2 luni', 14900)
),
colors(position, code, label, surcharge) AS (
    VALUES
        (1, 'C01', 'Roșu', 1000),
        (2, 'C02', 'Negru', 0),
        (3, 'C03', 'Verde', 500),
        (4, 'C04', 'Galben', 1500),
        (5, 'C05', 'Bej', 1200),
        (6, 'C06', 'Alb', 0)
)
INSERT INTO product_variants (
    product_id, sku, option_summary, options_json, options_key, price_bani,
    compare_price_bani, status, is_default, created_at, updated_at
)
SELECT
    product.id,
    CASE
        WHEN sizes.position = 1 AND colors.position = 1 THEN 'LM-26-030'
        ELSE 'LM-26-030-' || sizes.code || '-' || colors.code
    END,
    'Mărime: ' || sizes.label || ' · Culoare: ' || colors.label,
    '{"Mărime":"' || sizes.label || '","Culoare":"' || colors.label || '"}',
    printf('%064d', 3000 + sizes.position * 10 + colors.position),
    sizes.base_price + colors.surcharge,
    16900 + colors.surcharge,
    'active',
    CASE WHEN sizes.position = 1 AND colors.position = 1 THEN 1 ELSE 0 END,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM products product
CROSS JOIN sizes
CROSS JOIN colors
WHERE product.slug = 'cuburi-moi-poveste'
  AND NOT EXISTS (
      SELECT 1
      FROM product_variants existing
      WHERE existing.product_id = product.id
        AND existing.sku = CASE
            WHEN sizes.position = 1 AND colors.position = 1 THEN 'LM-26-030'
            ELSE 'LM-26-030-' || sizes.code || '-' || colors.code
        END
  )
ORDER BY sizes.position, colors.position;

INSERT INTO inventory (variant_id, physical_quantity, reserved_quantity, updated_at)
SELECT variant.id, 10, 0, CURRENT_TIMESTAMP
FROM product_variants variant
JOIN products product ON product.id = variant.product_id
WHERE product.slug = 'cuburi-moi-poveste'
  AND variant.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM inventory existing WHERE existing.variant_id = variant.id);

INSERT INTO inventory_movements (
    variant_id, type, physical_delta, reserved_delta, reason, created_at
)
SELECT
    variant.id,
    'catalog_variant_seed',
    inventory.physical_quantity,
    0,
    'Variante Mărime × Culoare pentru Cuburi moi „Poveste”',
    CURRENT_TIMESTAMP
FROM product_variants variant
JOIN products product ON product.id = variant.product_id
JOIN inventory ON inventory.variant_id = variant.id
WHERE product.slug = 'cuburi-moi-poveste'
  AND variant.status = 'active'
  AND NOT EXISTS (
      SELECT 1 FROM inventory_movements movement WHERE movement.variant_id = variant.id
  );
