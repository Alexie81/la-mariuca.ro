CREATE TABLE admin_remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    selector_hash CHAR(64) NOT NULL UNIQUE,
    validator_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_admin_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_remember_user_expiry(user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
