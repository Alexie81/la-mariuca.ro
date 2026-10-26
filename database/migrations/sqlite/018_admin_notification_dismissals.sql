CREATE TABLE admin_notification_dismissals (
    admin_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_key TEXT NOT NULL,
    dismissed_at TEXT NOT NULL,
    PRIMARY KEY (admin_user_id, notification_key)
);

CREATE INDEX idx_admin_notification_dismissals_user
    ON admin_notification_dismissals(admin_user_id, dismissed_at);
