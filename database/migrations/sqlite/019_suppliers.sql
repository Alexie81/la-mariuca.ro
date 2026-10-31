CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alias TEXT NOT NULL COLLATE NOCASE,
    company_name TEXT NULL,
    tax_id TEXT NULL,
    trade_register TEXT NULL,
    contact_name TEXT NULL,
    email TEXT NULL,
    phone TEXT NULL,
    website TEXT NULL,
    address TEXT NULL,
    notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(alias)
);

CREATE INDEX idx_suppliers_status_alias ON suppliers(status, alias);

ALTER TABLE products ADD COLUMN supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL;
CREATE INDEX idx_products_supplier ON products(supplier_id);
