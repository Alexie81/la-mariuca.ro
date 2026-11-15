ALTER TABLE product_reviews ADD COLUMN order_reference TEXT NULL;
ALTER TABLE product_reviews ADD COLUMN admin_response TEXT NOT NULL DEFAULT '';
ALTER TABLE product_reviews ADD COLUMN responded_at TEXT NULL;
ALTER TABLE product_reviews ADD COLUMN responded_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE product_reviews ADD COLUMN deleted_at TEXT NULL;
ALTER TABLE product_reviews ADD COLUMN deleted_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;

UPDATE product_reviews
SET order_reference = UPPER(TRIM(order_number))
WHERE TRIM(order_number) <> '';

CREATE UNIQUE INDEX uq_product_reviews_order_product
    ON product_reviews(product_id, order_reference);
CREATE INDEX idx_product_reviews_retention
    ON product_reviews(deleted_at, status, created_at);
