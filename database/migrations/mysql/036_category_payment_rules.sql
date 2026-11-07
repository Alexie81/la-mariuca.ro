ALTER TABLE categories
    ADD COLUMN allow_card TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN allow_cod TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD CONSTRAINT chk_category_allow_card CHECK (allow_card IN (0, 1)),
    ADD CONSTRAINT chk_category_allow_cod CHECK (allow_cod IN (0, 1));
