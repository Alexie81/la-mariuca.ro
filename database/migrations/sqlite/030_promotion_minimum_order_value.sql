ALTER TABLE promotions ADD COLUMN minimum_bani INTEGER NOT NULL DEFAULT 0 CHECK (minimum_bani >= 0);
CREATE INDEX idx_promotions_minimum_active ON promotions(status, minimum_bani, starts_at, ends_at);
