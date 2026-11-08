CREATE TABLE stripe_catalog_changes (
    product_id INTEGER PRIMARY KEY,
    revision INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
CREATE TABLE stripe_catalog_products (
    scope TEXT NOT NULL,
    product_id INTEGER NOT NULL,
    stripe_product_id TEXT NOT NULL,
    synced_revision INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT NULL,
    lease_token TEXT NULL,
    lease_expires_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY(scope, product_id),
    UNIQUE(scope, stripe_product_id)
);
CREATE TABLE stripe_catalog_prices (
    scope TEXT NOT NULL,
    product_id INTEGER NOT NULL,
    variant_id INTEGER NOT NULL,
    stripe_price_id TEXT NOT NULL,
    price_key TEXT NOT NULL,
    amount_bani INTEGER NOT NULL,
    currency TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL,
    PRIMARY KEY(scope, stripe_price_id)
);
CREATE INDEX idx_stripe_catalog_prices_variant ON stripe_catalog_prices(scope, product_id, variant_id, active);
