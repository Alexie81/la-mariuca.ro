ALTER TABLE users
    ADD COLUMN blocked_reason TEXT NULL AFTER last_login_at,
    ADD COLUMN blocked_at DATETIME NULL AFTER blocked_reason,
    ADD COLUMN blocked_by_user_id BIGINT UNSIGNED NULL AFTER blocked_at;
