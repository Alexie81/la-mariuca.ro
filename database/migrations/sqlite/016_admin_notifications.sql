CREATE TABLE admin_notification_reads (
    admin_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_key TEXT NOT NULL,
    read_at TEXT NOT NULL,
    PRIMARY KEY (admin_user_id, notification_key)
);

CREATE INDEX idx_admin_notification_reads_user
    ON admin_notification_reads(admin_user_id, read_at);

CREATE TABLE admin_notification_preferences (
    admin_user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    orders_enabled INTEGER NOT NULL DEFAULT 1 CHECK (orders_enabled IN (0, 1)),
    payments_enabled INTEGER NOT NULL DEFAULT 1 CHECK (payments_enabled IN (0, 1)),
    stock_enabled INTEGER NOT NULL DEFAULT 1 CHECK (stock_enabled IN (0, 1)),
    reviews_enabled INTEGER NOT NULL DEFAULT 1 CHECK (reviews_enabled IN (0, 1)),
    updated_at TEXT NOT NULL
);
