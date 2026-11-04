CREATE TABLE order_returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    return_type ENUM('partial','full') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    notes TEXT NOT NULL,
    restock TINYINT(1) NOT NULL DEFAULT 1,
    refund_mode ENUM('none','partial','full') NOT NULL DEFAULT 'none',
    refund_bani INT UNSIGNED NOT NULL DEFAULT 0,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_order_returns_order (order_id, created_at),
    CONSTRAINT fk_order_returns_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_returns_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    restocked_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    line_refund_bani INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_order_return_item (return_id, order_item_id),
    INDEX idx_order_return_items_order_item (order_item_id),
    CONSTRAINT fk_order_return_items_return FOREIGN KEY (return_id) REFERENCES order_returns(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_return_items_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CHECK (quantity > 0),
    CHECK (restocked_quantity <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
