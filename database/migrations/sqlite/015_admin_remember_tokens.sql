CREATE TABLE admin_remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector_hash TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    user_agent_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_admin_remember_user_expiry
    ON admin_remember_tokens(user_id, expires_at);
