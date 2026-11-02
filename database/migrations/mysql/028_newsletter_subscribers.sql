CREATE TABLE newsletter_subscribers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    name VARCHAR(190) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
    subscribe_source VARCHAR(40) NOT NULL,
    unsubscribe_source VARCHAR(40) NULL,
    subscribed_at DATETIME NOT NULL,
    unsubscribed_at DATETIME NULL,
    last_notified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_newsletter_subscribers_status_date (status, subscribed_at),
    INDEX idx_newsletter_subscribers_source (subscribe_source),
    INDEX idx_newsletter_subscribers_user (user_id),
    CONSTRAINT fk_newsletter_subscribers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscription_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subscriber_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    source VARCHAR(40) NOT NULL,
    details_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_newsletter_events_subscriber_date (subscriber_id, created_at),
    CONSTRAINT fk_newsletter_events_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    preview_text VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subscriber_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    opened_at DATETIME NULL,
    clicked_at DATETIME NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_newsletter_delivery_subscriber_message (subscriber_id, message_id),
    INDEX idx_newsletter_deliveries_subscriber_date (subscriber_id, sent_at),
    INDEX idx_newsletter_deliveries_message_status (message_id, status),
    CONSTRAINT fk_newsletter_deliveries_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
    CONSTRAINT fk_newsletter_deliveries_message FOREIGN KEY (message_id) REFERENCES newsletter_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
