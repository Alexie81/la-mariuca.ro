CREATE TABLE payment_method_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    cod_enabled INTEGER NULL CHECK (cod_enabled IN (0, 1)),
    stripe_enabled INTEGER NULL CHECK (stripe_enabled IN (0, 1)),
    updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NULL
);
INSERT INTO payment_method_settings (id) VALUES (1);
