ALTER TABLE companies ADD COLUMN is_vat_payer INTEGER NOT NULL DEFAULT 0 CHECK (is_vat_payer IN (0,1));
ALTER TABLE companies ADD COLUMN vat_rate_basis_points INTEGER NOT NULL DEFAULT 2100 CHECK (vat_rate_basis_points BETWEEN 0 AND 10000);
