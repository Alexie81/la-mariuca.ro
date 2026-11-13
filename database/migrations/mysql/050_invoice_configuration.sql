CREATE TABLE invoice_series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT '',
    deleted_at VARCHAR(30) NULL,
    created_at VARCHAR(30) NOT NULL,
    updated_at VARCHAR(30) NOT NULL,
    INDEX invoice_series_visible (deleted_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO invoice_series (code,label,created_at,updated_at) VALUES ('LM','Facturi La Măriuca',UTC_TIMESTAMP(),UTC_TIMESTAMP());
CREATE TABLE invoice_configuration (
    id TINYINT UNSIGNED PRIMARY KEY,
    active_series_id BIGINT UNSIGNED NOT NULL,
    due_days INT NOT NULL DEFAULT 0,
    default_notes TEXT NOT NULL,
    revision BIGINT NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL,
    CHECK (id = 1),
    CHECK (due_days BETWEEN 0 AND 3650),
    FOREIGN KEY (active_series_id) REFERENCES invoice_series(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO invoice_configuration (id,active_series_id,default_notes,updated_at) VALUES (1,1,'',UTC_TIMESTAMP());
CREATE TABLE invoice_configuration_snapshots (
    document_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    series_code VARCHAR(30) NOT NULL,
    series_label VARCHAR(100) NOT NULL,
    invoice_number BIGINT NOT NULL,
    due_days INT NOT NULL,
    default_notes TEXT NOT NULL,
    issued_at VARCHAR(30) NOT NULL,
    due_date VARCHAR(10) NOT NULL,
    configuration_revision BIGINT NOT NULL,
    CHECK (invoice_number > 0),
    UNIQUE KEY invoice_snapshot_number (series_code,invoice_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
