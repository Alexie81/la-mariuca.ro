CREATE TABLE shipping_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT 'courier' CHECK (type IN ('courier','locker','pickup','other')),
    price_bani INTEGER NOT NULL DEFAULT 0 CHECK (price_bani >= 0),
    estimated_min_days INTEGER NOT NULL DEFAULT 1 CHECK (estimated_min_days >= 0),
    estimated_max_days INTEGER NOT NULL DEFAULT 3 CHECK (estimated_max_days >= estimated_min_days),
    position INTEGER NOT NULL DEFAULT 0 CHECK (position >= 0),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);

CREATE INDEX idx_shipping_methods_checkout ON shipping_methods(status, deleted_at, is_default, position, id);

INSERT INTO shipping_methods
    (code,name,description,type,price_bani,estimated_min_days,estimated_max_days,position,is_default,status,created_at,updated_at,deleted_at)
VALUES
    ('curier-standard','Curier standard','Livrare la adresă prin curier.','courier',1900,1,3,10,1,'active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);

ALTER TABLE orders ADD COLUMN shipping_method_id INTEGER NULL REFERENCES shipping_methods(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN shipping_method_name TEXT NOT NULL DEFAULT '';
CREATE INDEX idx_orders_shipping_method ON orders(shipping_method_id);
