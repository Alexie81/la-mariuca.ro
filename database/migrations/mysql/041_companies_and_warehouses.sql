CREATE TABLE companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    legal_name VARCHAR(190) NOT NULL,
    trade_name VARCHAR(190) NOT NULL DEFAULT '',
    tax_id VARCHAR(60) NOT NULL DEFAULT '',
    trade_register VARCHAR(80) NOT NULL DEFAULT '',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    default_slot TINYINT GENERATED ALWAYS AS (CASE WHEN is_default=1 THEN 1 ELSE NULL END) STORED,
    registered_address VARCHAR(1000) NOT NULL DEFAULT '',
    city VARCHAR(120) NOT NULL DEFAULT '',
    county VARCHAR(120) NOT NULL DEFAULT '',
    postal_code VARCHAR(20) NOT NULL DEFAULT '',
    country VARCHAR(100) NOT NULL DEFAULT 'România',
    email VARCHAR(254) NOT NULL DEFAULT '',
    phone VARCHAR(50) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    physical_address VARCHAR(1000) NOT NULL DEFAULT '',
    service_area VARCHAR(500) NOT NULL DEFAULT '',
    bank_name VARCHAR(190) NOT NULL DEFAULT '',
    iban VARCHAR(34) NOT NULL DEFAULT '',
    share_capital_bani BIGINT UNSIGNED NULL,
    stamp_path VARCHAR(40) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY companies_single_default(default_slot),
    CONSTRAINT chk_company_default CHECK (is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_warehouses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    name_key VARCHAR(120) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    default_slot BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN is_default=1 THEN company_id ELSE NULL END) STORED,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY company_warehouses_name(company_id,name_key),
    UNIQUE KEY company_warehouses_single_default(default_slot),
    CONSTRAINT fk_warehouse_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT chk_warehouse_default CHECK (is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE company_write_lock (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL DEFAULT 0, CONSTRAINT chk_company_write_lock CHECK (id=1)) ENGINE=InnoDB;
INSERT INTO company_write_lock(id,revision) VALUES (1,0);
