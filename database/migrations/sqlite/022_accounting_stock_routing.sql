ALTER TABLE order_items ADD COLUMN accounting_scope_type TEXT NULL CHECK (accounting_scope_type IS NULL OR accounting_scope_type IN ('product', 'variant'));
ALTER TABLE order_items ADD COLUMN accounting_scope_id INTEGER NULL;
ALTER TABLE order_items ADD COLUMN accounting_sku TEXT NULL;

UPDATE order_items
SET accounting_scope_type = CASE
        WHEN variant_id IS NOT NULL
             AND COALESCE((SELECT variant.track_in_conta FROM product_variants variant WHERE variant.id = order_items.variant_id), 0) = 1
             AND COALESCE((SELECT variant.options_json FROM product_variants variant WHERE variant.id = order_items.variant_id), '{}') NOT IN ('', '{}', '[]')
            THEN 'variant'
        WHEN product_id IS NOT NULL
             AND COALESCE((SELECT product.track_in_conta FROM products product WHERE product.id = order_items.product_id), 0) = 1
            THEN 'product'
        ELSE NULL
    END,
    accounting_scope_id = CASE
        WHEN variant_id IS NOT NULL
             AND COALESCE((SELECT variant.track_in_conta FROM product_variants variant WHERE variant.id = order_items.variant_id), 0) = 1
             AND COALESCE((SELECT variant.options_json FROM product_variants variant WHERE variant.id = order_items.variant_id), '{}') NOT IN ('', '{}', '[]')
            THEN variant_id
        WHEN product_id IS NOT NULL
             AND COALESCE((SELECT product.track_in_conta FROM products product WHERE product.id = order_items.product_id), 0) = 1
            THEN product_id
        ELSE NULL
    END,
    accounting_sku = CASE
        WHEN variant_id IS NOT NULL
             AND COALESCE((SELECT variant.track_in_conta FROM product_variants variant WHERE variant.id = order_items.variant_id), 0) = 1
             AND COALESCE((SELECT variant.options_json FROM product_variants variant WHERE variant.id = order_items.variant_id), '{}') NOT IN ('', '{}', '[]')
            THEN (SELECT variant.sku FROM product_variants variant WHERE variant.id = order_items.variant_id)
        WHEN product_id IS NOT NULL
             AND COALESCE((SELECT product.track_in_conta FROM products product WHERE product.id = order_items.product_id), 0) = 1
            THEN (SELECT product.primary_sku FROM products product WHERE product.id = order_items.product_id)
        ELSE NULL
    END;

CREATE TABLE accounting_stock_balances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope_type TEXT NOT NULL CHECK (scope_type IN ('product', 'variant')),
    scope_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    sku TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(scope_type, scope_id)
);
CREATE INDEX idx_accounting_balances_product ON accounting_stock_balances(product_id, scope_type);
CREATE INDEX idx_accounting_balances_variant ON accounting_stock_balances(variant_id);

CREATE TABLE accounting_stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key TEXT NOT NULL UNIQUE,
    scope_type TEXT NOT NULL CHECK (scope_type IN ('product', 'variant')),
    scope_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    sku TEXT NOT NULL,
    quantity_delta INTEGER NOT NULL CHECK (quantity_delta <> 0),
    type TEXT NOT NULL CHECK (type IN ('sale', 'nir', 'manual_adjustment', 'return')),
    reason TEXT NOT NULL,
    reference_type TEXT NULL,
    reference_id INTEGER NULL,
    actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_accounting_movements_scope ON accounting_stock_movements(scope_type, scope_id, created_at);
CREATE INDEX idx_accounting_movements_product ON accounting_stock_movements(product_id, created_at);
CREATE INDEX idx_accounting_movements_reference ON accounting_stock_movements(reference_type, reference_id);
