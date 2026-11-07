CREATE TABLE order_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    document_number VARCHAR(190) NOT NULL,
    private_path VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    INDEX idx_order_documents_order(order_id, revoked_at),
    CONSTRAINT fk_order_documents_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
