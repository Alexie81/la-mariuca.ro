CREATE TABLE order_notification_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    recipients_json TEXT NOT NULL DEFAULT '[]',
    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NULL
);
INSERT INTO order_notification_settings (id, recipients_json) VALUES (1, '[]');

CREATE TABLE order_internal_email_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    recipient TEXT NOT NULL,
    outbox_message_id INTEGER NOT NULL UNIQUE REFERENCES outbox_messages(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (order_id, recipient)
);
