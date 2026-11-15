ALTER TABLE products ADD COLUMN source_name TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN supplier_code TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN model TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN unit TEXT NOT NULL DEFAULT 'buc';
ALTER TABLE products ADD COLUMN extended_title TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN vat_rate_basis_points INTEGER NOT NULL DEFAULT 2100 CHECK (vat_rate_basis_points BETWEEN 0 AND 10000);
ALTER TABLE products ADD COLUMN currency TEXT NOT NULL DEFAULT 'RON';
ALTER TABLE products ADD COLUMN source_price_bani INTEGER NULL CHECK (source_price_bani IS NULL OR source_price_bani >= 0);
ALTER TABLE products ADD COLUMN external_reference_price_bani INTEGER NULL CHECK (external_reference_price_bani IS NULL OR external_reference_price_bani >= 0);
ALTER TABLE products ADD COLUMN margin_basis_points INTEGER NULL CHECK (margin_basis_points IS NULL OR margin_basis_points BETWEEN -100000 AND 100000);
ALTER TABLE products ADD COLUMN compatibility_text TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN tags TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN warranty_legal TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN warranty_commercial TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN manufacturer_address TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN manufacturer_email TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN eu_responsible_person TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN ce_marking INTEGER NOT NULL DEFAULT 0 CHECK (ce_marking IN (0,1));
ALTER TABLE products ADD COLUMN model_identifier TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN safety_warnings TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN conformity_documents TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN manuals TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN software_updates TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN repairability TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN spare_parts_availability TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN public_availability TEXT NOT NULL DEFAULT 'automatic' CHECK (public_availability IN ('automatic','in_stock','preorder','on_request','unavailable'));
ALTER TABLE products ADD COLUMN backorder_policy TEXT NOT NULL DEFAULT 'deny' CHECK (backorder_policy IN ('deny','allow','on_request'));
ALTER TABLE products ADD COLUMN canonical_url TEXT NOT NULL DEFAULT '';
ALTER TABLE products ADD COLUMN structured_data_enabled INTEGER NOT NULL DEFAULT 1 CHECK (structured_data_enabled IN (0,1));
ALTER TABLE products ADD COLUMN external_stock_quantity INTEGER NULL;

CREATE TABLE product_specifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    group_name TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL,
    value TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_product_specifications_product ON product_specifications(product_id,position,id);

CREATE TABLE product_faqs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_product_faqs_product ON product_faqs(product_id,position,id);

CREATE TABLE product_view_daily (
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    viewed_on TEXT NOT NULL,
    views INTEGER NOT NULL DEFAULT 0 CHECK (views >= 0),
    PRIMARY KEY(product_id,viewed_on)
);
