CREATE TABLE customer_discounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scope VARCHAR(20) NOT NULL,
    value_type VARCHAR(20) NOT NULL,
    value INT UNSIGNED NOT NULL DEFAULT 0,
    target_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_customer_discounts_user_active (user_id, status, starts_at, ends_at),
    CONSTRAINT fk_customer_discounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_discounts_admin FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN customer_discount_id BIGINT UNSIGNED NULL AFTER discount_bani,
    ADD COLUMN customer_shipping_discount_id BIGINT UNSIGNED NULL AFTER customer_discount_id,
    ADD COLUMN shipping_discount_bani INT UNSIGNED NOT NULL DEFAULT 0 AFTER shipping_bani,
    ADD CONSTRAINT fk_orders_customer_discount FOREIGN KEY (customer_discount_id) REFERENCES customer_discounts(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_orders_customer_shipping_discount FOREIGN KEY (customer_shipping_discount_id) REFERENCES customer_discounts(id) ON DELETE SET NULL;
