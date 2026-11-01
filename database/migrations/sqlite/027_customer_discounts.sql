CREATE TABLE customer_discounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope TEXT NOT NULL CHECK (scope IN ('order','category','product','shipping')),
    value_type TEXT NOT NULL CHECK (value_type IN ('percent','fixed','free')),
    value INTEGER NOT NULL DEFAULT 0 CHECK (value >= 0),
    target_id INTEGER NULL,
    starts_at TEXT NULL,
    ends_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
CREATE INDEX idx_customer_discounts_user_active ON customer_discounts(user_id, status, starts_at, ends_at);

ALTER TABLE orders ADD COLUMN customer_discount_id INTEGER NULL REFERENCES customer_discounts(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN customer_shipping_discount_id INTEGER NULL REFERENCES customer_discounts(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN shipping_discount_bani INTEGER NOT NULL DEFAULT 0;
