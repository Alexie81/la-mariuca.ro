CREATE TABLE admin_push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    endpoint_hash TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth_token TEXT NOT NULL,
    content_encoding TEXT NOT NULL DEFAULT 'aes128gcm',
    device_label TEXT NOT NULL,
    user_agent TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked', 'expired')),
    failure_count INTEGER NOT NULL DEFAULT 0,
    last_seen_at TEXT NOT NULL,
    last_success_at TEXT NULL,
    last_failure_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_admin_push_subscriptions_user_status
    ON admin_push_subscriptions(admin_user_id, status);

CREATE TABLE admin_push_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL CHECK (type IN ('comenzi', 'plati', 'stoc', 'recenzii', 'test')),
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    target_path TEXT NOT NULL,
    urgency TEXT NOT NULL DEFAULT 'normal' CHECK (urgency IN ('very-low', 'low', 'normal', 'high')),
    created_at TEXT NOT NULL
);

CREATE TABLE admin_push_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES admin_push_events(id) ON DELETE CASCADE,
    subscription_id INTEGER NOT NULL REFERENCES admin_push_subscriptions(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'expired')),
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    last_error TEXT NULL,
    sent_at TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (event_id, subscription_id)
);

CREATE INDEX idx_admin_push_deliveries_queue
    ON admin_push_deliveries(status, available_at, attempts);
