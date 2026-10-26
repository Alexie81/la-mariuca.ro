CREATE TABLE admin_notification_reads (
    admin_user_id BIGINT UNSIGNED NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    read_at DATETIME NOT NULL,
    PRIMARY KEY (admin_user_id, notification_key),
    INDEX idx_admin_notification_reads_user(admin_user_id, read_at),
    CONSTRAINT fk_admin_notification_reads_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_notification_preferences (
    admin_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    orders_enabled TINYINT(1) NOT NULL DEFAULT 1,
    payments_enabled TINYINT(1) NOT NULL DEFAULT 1,
    stock_enabled TINYINT(1) NOT NULL DEFAULT 1,
    reviews_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_admin_notification_preferences_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
