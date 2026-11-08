CREATE TABLE stripe_catalog_changes (
    product_id BIGINT UNSIGNED PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE stripe_catalog_products (
    scope VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    stripe_product_id VARCHAR(190) NOT NULL,
    synced_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at VARCHAR(30) NOT NULL,
    last_error VARCHAR(255) NULL,
    lease_token VARCHAR(64) NULL,
    lease_expires_at VARCHAR(30) NULL,
    updated_at VARCHAR(30) NOT NULL,
    PRIMARY KEY(scope, product_id),
    UNIQUE KEY idx_stripe_catalog_product_remote(scope, stripe_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE stripe_catalog_prices (
    scope VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    stripe_price_id VARCHAR(190) NOT NULL,
    price_key VARCHAR(190) NOT NULL,
    amount_bani BIGINT UNSIGNED NOT NULL,
    currency VARCHAR(3) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL,
    PRIMARY KEY(scope, stripe_price_id),
    KEY idx_stripe_catalog_prices_variant(scope, product_id, variant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
