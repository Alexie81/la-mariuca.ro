ALTER TABLE product_variants
    ADD COLUMN stock_unlimited TINYINT(1) NOT NULL DEFAULT 0 AFTER compare_price_bani,
    ADD COLUMN track_in_conta TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_unlimited,
    ADD COLUMN discount_type ENUM('percent', 'fixed') NULL AFTER track_in_conta,
    ADD COLUMN discount_value INT UNSIGNED NULL AFTER discount_type;
