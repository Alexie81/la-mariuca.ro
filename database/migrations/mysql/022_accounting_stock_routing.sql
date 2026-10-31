ALTER TABLE order_items
    ADD COLUMN accounting_scope_type ENUM('product', 'variant') NULL AFTER personalization_json,
    ADD COLUMN accounting_scope_id BIGINT UNSIGNED NULL AFTER accounting_scope_type,
    ADD COLUMN accounting_sku VARCHAR(100) NULL AFTER accounting_scope_id;

UPDATE order_items item
LEFT JOIN product_variants variant ON variant.id = item.variant_id
LEFT JOIN products product ON product.id = item.product_id
SET item.accounting_scope_type = CASE
        WHEN variant.id IS NOT NULL AND variant.track_in_conta = 1 AND JSON_LENGTH(variant.options_json) > 0 THEN 'variant'
        WHEN product.id IS NOT NULL AND product.track_in_conta = 1 THEN 'product'
        ELSE NULL
    END,
    item.accounting_scope_id = CASE
        WHEN variant.id IS NOT NULL AND variant.track_in_conta = 1 AND JSON_LENGTH(variant.options_json) > 0 THEN variant.id
        WHEN product.id IS NOT NULL AND product.track_in_conta = 1 THEN product.id
        ELSE NULL
    END,
    item.accounting_sku = CASE
        WHEN variant.id IS NOT NULL AND variant.track_in_conta = 1 AND JSON_LENGTH(variant.options_json) > 0 THEN variant.sku
        WHEN product.id IS NOT NULL AND product.track_in_conta = 1 THEN product.primary_sku
        ELSE NULL
    END;

CREATE TABLE accounting_stock_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_type ENUM('product', 'variant') NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    sku VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_accounting_balance_scope(scope_type, scope_id),
    INDEX idx_accounting_balances_product(product_id, scope_type),
    INDEX idx_accounting_balances_variant(variant_id),
    CONSTRAINT fk_accounting_balance_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_accounting_balance_variant FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(190) NOT NULL,
    scope_type ENUM('product', 'variant') NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    sku VARCHAR(100) NOT NULL,
    quantity_delta INT NOT NULL,
    type ENUM('sale', 'nir', 'manual_adjustment', 'return') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    reference_type VARCHAR(60) NULL,
    reference_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_accounting_movement_event(event_key),
    INDEX idx_accounting_movements_scope(scope_type, scope_id, created_at),
    INDEX idx_accounting_movements_product(product_id, created_at),
    INDEX idx_accounting_movements_reference(reference_type, reference_id),
    CONSTRAINT fk_accounting_movement_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_accounting_movement_variant FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_accounting_movement_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (quantity_delta <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
