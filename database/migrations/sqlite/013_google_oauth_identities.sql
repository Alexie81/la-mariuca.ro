CREATE TABLE oauth_identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ('google')),
    provider_subject TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(provider, provider_subject),
    UNIQUE(user_id, provider)
);

CREATE INDEX idx_oauth_identities_user ON oauth_identities(user_id);
