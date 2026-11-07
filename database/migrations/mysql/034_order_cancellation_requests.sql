CREATE TABLE order_cancellation_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    reason TEXT NOT NULL,
    order_status_at_request VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_order_cancellation_created(created_at),
    CONSTRAINT fk_order_cancellation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
