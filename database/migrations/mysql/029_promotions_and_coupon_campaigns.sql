ALTER TABLE coupons
    ADD COLUMN name VARCHAR(190) NOT NULL DEFAULT '' AFTER code,
    ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'order' AFTER name,
    ADD COLUMN target_id BIGINT UNSIGNED NULL AFTER scope,
    ADD COLUMN value_type VARCHAR(20) NULL AFTER target_id,
    ADD COLUMN topbar_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN topbar_message VARCHAR(140) NOT NULL DEFAULT '' AFTER topbar_enabled,
    ADD COLUMN topbar_priority SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER topbar_message,
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER topbar_priority,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD INDEX idx_coupons_registry_active (status, starts_at, ends_at, deleted_at),
    ADD INDEX idx_coupons_topbar_active (topbar_enabled, status, topbar_priority, starts_at, ends_at),
    ADD CONSTRAINT fk_coupons_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE coupons
SET name=code,
    value_type=type,
    updated_at=created_at
WHERE name='' OR value_type IS NULL OR updated_at IS NULL;

CREATE TABLE promotions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    scope VARCHAR(20) NOT NULL,
    value_type VARCHAR(20) NOT NULL,
    value INT UNSIGNED NOT NULL DEFAULT 0,
    target_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    topbar_enabled TINYINT(1) NOT NULL DEFAULT 0,
    topbar_message VARCHAR(140) NOT NULL DEFAULT '',
    topbar_priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_promotions_registry_active (status, starts_at, ends_at, deleted_at),
    INDEX idx_promotions_topbar_active (topbar_enabled, status, topbar_priority, starts_at, ends_at),
    INDEX idx_promotions_target (scope, target_id),
    CONSTRAINT fk_promotions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (scope IN ('order','category','product','shipping')),
    CHECK (value_type IN ('percent','fixed','free')),
    CHECK (
        (value_type='percent' AND value BETWEEN 1 AND 10000)
        OR (value_type='fixed' AND value > 0)
        OR (value_type='free' AND scope='shipping' AND value=0)
    ),
    CHECK (
        (scope IN ('product','category') AND target_id IS NOT NULL)
        OR (scope IN ('order','shipping') AND target_id IS NULL)
    ),
    CHECK (topbar_priority BETWEEN 0 AND 1000),
    CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN promotion_id BIGINT UNSIGNED NULL AFTER customer_shipping_discount_id,
    ADD COLUMN shipping_promotion_id BIGINT UNSIGNED NULL AFTER promotion_id,
    ADD INDEX idx_orders_promotion (promotion_id),
    ADD INDEX idx_orders_shipping_promotion (shipping_promotion_id),
    ADD CONSTRAINT fk_orders_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_orders_shipping_promotion FOREIGN KEY (shipping_promotion_id) REFERENCES promotions(id) ON DELETE SET NULL;
