ALTER TABLE users ADD COLUMN blocked_reason TEXT NULL;
ALTER TABLE users ADD COLUMN blocked_at TEXT NULL;
ALTER TABLE users ADD COLUMN blocked_by_user_id INTEGER NULL;
