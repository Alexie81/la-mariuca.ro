ALTER TABLE products ADD COLUMN primary_sku TEXT NULL;
ALTER TABLE products ADD COLUMN track_in_conta INTEGER NOT NULL DEFAULT 1 CHECK (track_in_conta IN (0, 1));

UPDATE products
SET primary_sku = (
    SELECT variant.sku
    FROM product_variants variant
    WHERE variant.product_id = products.id
    ORDER BY variant.is_default DESC, variant.id ASC
    LIMIT 1
)
WHERE primary_sku IS NULL;

UPDATE products
SET track_in_conta = COALESCE((
    SELECT variant.track_in_conta
    FROM product_variants variant
    WHERE variant.product_id = products.id
    ORDER BY variant.is_default DESC, variant.id ASC
    LIMIT 1
), 1);

CREATE UNIQUE INDEX idx_products_primary_sku_nocase
    ON products(LOWER(primary_sku))
    WHERE primary_sku IS NOT NULL AND TRIM(primary_sku) <> '';
