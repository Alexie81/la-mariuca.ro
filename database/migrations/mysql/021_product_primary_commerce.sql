ALTER TABLE products
    ADD COLUMN primary_sku VARCHAR(100) NULL AFTER slug,
    ADD COLUMN track_in_conta TINYINT(1) NOT NULL DEFAULT 1 AFTER primary_sku,
    ADD UNIQUE KEY uq_products_primary_sku (primary_sku);

UPDATE products product
LEFT JOIN product_variants variant ON variant.id = (
    SELECT candidate.id
    FROM product_variants candidate
    WHERE candidate.product_id = product.id
    ORDER BY candidate.is_default DESC, candidate.id ASC
    LIMIT 1
)
SET product.primary_sku = variant.sku,
    product.track_in_conta = COALESCE(variant.track_in_conta, 1)
WHERE product.primary_sku IS NULL;
