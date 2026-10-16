ALTER TABLE users ADD COLUMN totp_last_timeslice BIGINT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE admin_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL UNIQUE,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recovery_user_unused(user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
