CREATE TABLE order_notification_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    recipients_json JSON NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    CONSTRAINT chk_order_notification_singleton CHECK (id = 1),
    CONSTRAINT fk_order_notification_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO order_notification_settings (id, recipients_json) VALUES (1, '[]');

CREATE TABLE order_internal_email_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    outbox_message_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_order_internal_email_recipient (order_id, recipient),
    UNIQUE KEY uq_order_internal_email_outbox (outbox_message_id),
    CONSTRAINT fk_order_internal_email_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_internal_email_outbox FOREIGN KEY (outbox_message_id) REFERENCES outbox_messages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
