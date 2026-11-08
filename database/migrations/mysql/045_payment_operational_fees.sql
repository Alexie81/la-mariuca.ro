CREATE TABLE payment_operational_fees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_method VARCHAR(16) NOT NULL,
    name VARCHAR(100) NOT NULL,
    amount_bani INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    FOREIGN KEY(created_by) REFERENCES users(id),
    INDEX idx_payment_operational_fees_method(payment_method,deleted_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE orders ADD COLUMN payment_fee_bani INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN payment_fees_json JSON NULL;
UPDATE orders SET payment_fees_json='[]' WHERE payment_fees_json IS NULL;
