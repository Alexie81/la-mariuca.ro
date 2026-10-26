CREATE TABLE admin_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL UNIQUE,
    p256dh VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255) NOT NULL,
    content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
    device_label VARCHAR(120) NOT NULL,
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_admin_push_subscriptions_user_status(admin_user_id, status),
    CONSTRAINT fk_admin_push_subscriptions_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_push_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(190) NOT NULL UNIQUE,
    type ENUM('comenzi', 'plati', 'stoc', 'recenzii', 'test') NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(255) NOT NULL,
    target_path VARCHAR(500) NOT NULL,
    urgency ENUM('very-low', 'low', 'normal', 'high') NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_push_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    last_error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_admin_push_delivery(event_id, subscription_id),
    INDEX idx_admin_push_deliveries_queue(status, available_at, attempts),
    CONSTRAINT fk_admin_push_delivery_event FOREIGN KEY (event_id) REFERENCES admin_push_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_push_delivery_subscription FOREIGN KEY (subscription_id) REFERENCES admin_push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
