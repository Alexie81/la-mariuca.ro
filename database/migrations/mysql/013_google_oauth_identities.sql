CREATE TABLE oauth_identities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('google') NOT NULL,
    provider_subject VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_oauth_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_oauth_provider_subject(provider, provider_subject),
    UNIQUE KEY uq_oauth_user_provider(user_id, provider),
    INDEX idx_oauth_identities_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
