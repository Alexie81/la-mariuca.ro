ALTER TABLE companies
    ADD COLUMN is_vat_payer TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN vat_rate_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 2100,
    ADD CONSTRAINT chk_company_vat_payer CHECK (is_vat_payer IN (0,1)),
    ADD CONSTRAINT chk_company_vat_rate CHECK (vat_rate_basis_points BETWEEN 0 AND 10000);
