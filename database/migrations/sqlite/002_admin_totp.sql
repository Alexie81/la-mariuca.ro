ALTER TABLE users ADD COLUMN totp_last_timeslice INTEGER NOT NULL DEFAULT 0;
CREATE TABLE admin_recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL UNIQUE,
    used_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_recovery_user_unused ON admin_recovery_codes(user_id, used_at);
