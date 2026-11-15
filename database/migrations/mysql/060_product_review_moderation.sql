ALTER TABLE product_reviews
    ADD COLUMN order_reference VARCHAR(40) NULL AFTER order_number,
    ADD COLUMN admin_response TEXT NOT NULL AFTER body,
    ADD COLUMN responded_at DATETIME NULL AFTER moderated_by,
    ADD COLUMN responded_by BIGINT UNSIGNED NULL AFTER responded_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER responded_by,
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at;

UPDATE product_reviews
SET order_reference = UPPER(TRIM(order_number))
WHERE TRIM(order_number) <> '';

ALTER TABLE product_reviews
    ADD UNIQUE KEY uq_product_reviews_order_product(product_id, order_reference),
    ADD INDEX idx_product_reviews_retention(deleted_at, status, created_at),
    ADD CONSTRAINT fk_product_reviews_responder FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_product_reviews_deleter FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
