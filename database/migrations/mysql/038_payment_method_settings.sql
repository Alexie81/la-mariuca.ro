CREATE TABLE payment_method_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    cod_enabled TINYINT UNSIGNED NULL,
    stripe_enabled TINYINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    CONSTRAINT chk_payment_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_payment_settings_cod CHECK (cod_enabled IN (0, 1)),
    CONSTRAINT chk_payment_settings_stripe CHECK (stripe_enabled IN (0, 1)),
    CONSTRAINT fk_payment_settings_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO payment_method_settings (id) VALUES (1);
