ALTER TABLE promotions
    ADD COLUMN minimum_bani INT UNSIGNED NOT NULL DEFAULT 0 AFTER target_id,
    ADD INDEX idx_promotions_minimum_active (status, minimum_bani, starts_at, ends_at);
