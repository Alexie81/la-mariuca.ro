CREATE TABLE checkout_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_token_hash TEXT NOT NULL UNIQUE,
    idempotency_key TEXT NOT NULL UNIQUE,
    cart_id INTEGER NOT NULL REFERENCES carts(id) ON DELETE RESTRICT,
    active_cart_id INTEGER NULL UNIQUE REFERENCES carts(id) ON DELETE RESTRICT,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','failed','expired','completed')),
    currency TEXT NOT NULL,
    total_bani INTEGER NOT NULL CHECK(total_bani > 0),
    snapshot_json TEXT NOT NULL,
    checkout_session_id TEXT NULL UNIQUE,
    provider_payment_id TEXT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    order_id INTEGER NULL UNIQUE REFERENCES orders(id) ON DELETE RESTRICT,
    stripe_mode TEXT NOT NULL CHECK(stripe_mode IN ('test','live')),
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_checkout_attempts_cart ON checkout_attempts(cart_id,status);
CREATE INDEX idx_checkout_attempts_expiry ON checkout_attempts(status,expires_at);
CREATE TABLE checkout_attempt_holds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES checkout_attempts(id) ON DELETE RESTRICT,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','released','transferred')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(attempt_id,variant_id)
);
ALTER TABLE coupon_redemptions ADD COLUMN attempt_id INTEGER NULL REFERENCES checkout_attempts(id) ON DELETE RESTRICT;
CREATE UNIQUE INDEX uq_coupon_redemption_attempt ON coupon_redemptions(attempt_id);
