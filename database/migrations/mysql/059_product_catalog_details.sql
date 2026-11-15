ALTER TABLE products
    ADD COLUMN source_name VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN supplier_code VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN model VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'buc',
    ADD COLUMN extended_title VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN vat_rate_basis_points INT UNSIGNED NOT NULL DEFAULT 2100,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'RON',
    ADD COLUMN source_price_bani BIGINT UNSIGNED NULL,
    ADD COLUMN external_reference_price_bani BIGINT UNSIGNED NULL,
    ADD COLUMN margin_basis_points INT NULL,
    ADD COLUMN compatibility_text TEXT NOT NULL,
    ADD COLUMN tags TEXT NOT NULL,
    ADD COLUMN warranty_legal TEXT NOT NULL,
    ADD COLUMN warranty_commercial TEXT NOT NULL,
    ADD COLUMN manufacturer_address TEXT NOT NULL,
    ADD COLUMN manufacturer_email VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN eu_responsible_person TEXT NOT NULL,
    ADD COLUMN ce_marking TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN model_identifier VARCHAR(190) NOT NULL DEFAULT '',
    ADD COLUMN safety_warnings TEXT NOT NULL,
    ADD COLUMN conformity_documents TEXT NOT NULL,
    ADD COLUMN manuals TEXT NOT NULL,
    ADD COLUMN software_updates TEXT NOT NULL,
    ADD COLUMN repairability TEXT NOT NULL,
    ADD COLUMN spare_parts_availability TEXT NOT NULL,
    ADD COLUMN public_availability VARCHAR(20) NOT NULL DEFAULT 'automatic',
    ADD COLUMN backorder_policy VARCHAR(20) NOT NULL DEFAULT 'deny',
    ADD COLUMN canonical_url VARCHAR(500) NOT NULL DEFAULT '',
    ADD COLUMN structured_data_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN external_stock_quantity BIGINT NULL;

CREATE TABLE product_specifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    group_name VARCHAR(190) NOT NULL DEFAULT '',
    name VARCHAR(190) NOT NULL,
    value TEXT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    INDEX idx_product_specifications_product(product_id,position,id),
    CONSTRAINT fk_product_specifications_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_faqs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    INDEX idx_product_faqs_product(product_id,position,id),
    CONSTRAINT fk_product_faqs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_view_daily (
    product_id BIGINT UNSIGNED NOT NULL,
    viewed_on DATE NOT NULL,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(product_id,viewed_on),
    CONSTRAINT fk_product_view_daily_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
