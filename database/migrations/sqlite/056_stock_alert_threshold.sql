ALTER TABLE products ADD COLUMN accounting_minimum_units BIGINT NOT NULL DEFAULT 0 CHECK (accounting_minimum_units >= 0);
