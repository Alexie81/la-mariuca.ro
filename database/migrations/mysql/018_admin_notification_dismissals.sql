CREATE TABLE admin_notification_dismissals (
    admin_user_id BIGINT UNSIGNED NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (admin_user_id, notification_key),
    INDEX idx_admin_notification_dismissals_user(admin_user_id, dismissed_at),
    CONSTRAINT fk_admin_notification_dismissals_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
