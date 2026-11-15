CREATE TABLE stock_online_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    physical_delta INT NOT NULL,
    before_quantity INT NOT NULL,
    after_quantity INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_stock_online_adjustments_request(request_key),
    INDEX idx_stock_online_adjustments_product_created(product_id, created_at),
    CONSTRAINT fk_stock_online_adjustments_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_online_adjustments_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_online_adjustments_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
