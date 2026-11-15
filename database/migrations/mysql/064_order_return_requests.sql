CREATE TABLE order_return_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(190) NOT NULL UNIQUE,
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    reason TEXT NOT NULL,
    quantities_json LONGTEXT NOT NULL,
    decision_reason TEXT NOT NULL,
    return_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at VARCHAR(40) NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at VARCHAR(40) NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (return_id) REFERENCES order_returns(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (decided_by) REFERENCES users(id),
    INDEX idx_order_return_requests_order (order_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
