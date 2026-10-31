ALTER TABLE product_variants ADD COLUMN stock_unlimited INTEGER NOT NULL DEFAULT 0 CHECK (stock_unlimited IN (0, 1));
ALTER TABLE product_variants ADD COLUMN track_in_conta INTEGER NOT NULL DEFAULT 1 CHECK (track_in_conta IN (0, 1));
ALTER TABLE product_variants ADD COLUMN discount_type TEXT NULL CHECK (discount_type IS NULL OR discount_type IN ('percent', 'fixed'));
ALTER TABLE product_variants ADD COLUMN discount_value INTEGER NULL CHECK (discount_value IS NULL OR discount_value >= 0);

