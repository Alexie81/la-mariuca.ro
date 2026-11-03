CREATE TABLE shipping_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    type ENUM('courier','locker','pickup','other') NOT NULL DEFAULT 'courier',
    price_bani INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_min_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    estimated_max_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_shipping_methods_checkout(status, deleted_at, is_default, position, id),
    CHECK (estimated_max_days >= estimated_min_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO shipping_methods
    (code,name,description,type,price_bani,estimated_min_days,estimated_max_days,position,is_default,status,created_at,updated_at,deleted_at)
VALUES
    ('curier-standard','Curier standard','Livrare la adresă prin curier.','courier',1900,1,3,10,1,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL);

ALTER TABLE orders
    ADD COLUMN shipping_method_id BIGINT UNSIGNED NULL AFTER shipping_method,
    ADD COLUMN shipping_method_name VARCHAR(120) NOT NULL DEFAULT '' AFTER shipping_method_id,
    ADD INDEX idx_orders_shipping_method (shipping_method_id),
    ADD CONSTRAINT fk_orders_shipping_method FOREIGN KEY (shipping_method_id) REFERENCES shipping_methods(id) ON DELETE SET NULL;
