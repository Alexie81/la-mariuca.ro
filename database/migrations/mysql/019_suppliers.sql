CREATE TABLE suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alias VARCHAR(100) NOT NULL,
    company_name VARCHAR(190) NULL,
    tax_id VARCHAR(60) NULL,
    trade_register VARCHAR(80) NULL,
    contact_name VARCHAR(190) NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(500) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_suppliers_alias(alias),
    INDEX idx_suppliers_status_alias(status, alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN supplier_id BIGINT UNSIGNED NULL AFTER brand,
    ADD INDEX idx_products_supplier(supplier_id),
    ADD CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;
