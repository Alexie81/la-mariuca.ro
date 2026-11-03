ALTER TABLE coupons ADD COLUMN name TEXT NOT NULL DEFAULT '';
ALTER TABLE coupons ADD COLUMN scope TEXT NOT NULL DEFAULT 'order' CHECK (scope IN ('order','category','product','shipping'));
ALTER TABLE coupons ADD COLUMN target_id INTEGER NULL;
ALTER TABLE coupons ADD COLUMN value_type TEXT NULL CHECK (value_type IS NULL OR value_type IN ('percent','fixed','free'));
ALTER TABLE coupons ADD COLUMN topbar_enabled INTEGER NOT NULL DEFAULT 0 CHECK (topbar_enabled IN (0,1));
ALTER TABLE coupons ADD COLUMN topbar_message TEXT NOT NULL DEFAULT '' CHECK (length(topbar_message) <= 140);
ALTER TABLE coupons ADD COLUMN topbar_priority INTEGER NOT NULL DEFAULT 0 CHECK (topbar_priority BETWEEN 0 AND 1000);
ALTER TABLE coupons ADD COLUMN created_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE coupons ADD COLUMN updated_at TEXT NULL;
ALTER TABLE coupons ADD COLUMN deleted_at TEXT NULL;

UPDATE coupons
SET name=code,
    value_type=type,
    updated_at=created_at
WHERE name='' OR value_type IS NULL OR updated_at IS NULL;

CREATE INDEX idx_coupons_registry_active ON coupons(status, starts_at, ends_at, deleted_at);
CREATE INDEX idx_coupons_topbar_active ON coupons(topbar_enabled, status, topbar_priority, starts_at, ends_at);

CREATE TABLE promotions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    scope TEXT NOT NULL CHECK (scope IN ('order','category','product','shipping')),
    value_type TEXT NOT NULL CHECK (value_type IN ('percent','fixed','free')),
    value INTEGER NOT NULL DEFAULT 0 CHECK (
        (value_type='percent' AND value BETWEEN 1 AND 10000)
        OR (value_type='fixed' AND value > 0)
        OR (value_type='free' AND scope='shipping' AND value=0)
    ),
    target_id INTEGER NULL,
    starts_at TEXT NULL,
    ends_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    topbar_enabled INTEGER NOT NULL DEFAULT 0 CHECK (topbar_enabled IN (0,1)),
    topbar_message TEXT NOT NULL DEFAULT '' CHECK (length(topbar_message) <= 140),
    topbar_priority INTEGER NOT NULL DEFAULT 0 CHECK (topbar_priority BETWEEN 0 AND 1000),
    created_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL,
    CHECK (
        (scope IN ('product','category') AND target_id IS NOT NULL)
        OR (scope IN ('order','shipping') AND target_id IS NULL)
    ),
    CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)
);
CREATE INDEX idx_promotions_registry_active ON promotions(status, starts_at, ends_at, deleted_at);
CREATE INDEX idx_promotions_topbar_active ON promotions(topbar_enabled, status, topbar_priority, starts_at, ends_at);
CREATE INDEX idx_promotions_target ON promotions(scope, target_id);

ALTER TABLE orders ADD COLUMN promotion_id INTEGER NULL REFERENCES promotions(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN shipping_promotion_id INTEGER NULL REFERENCES promotions(id) ON DELETE SET NULL;
CREATE INDEX idx_orders_promotion ON orders(promotion_id);
CREATE INDEX idx_orders_shipping_promotion ON orders(shipping_promotion_id);
