CREATE TABLE order_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    return_type TEXT NOT NULL CHECK (return_type IN ('partial','full')),
    reason TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    restock INTEGER NOT NULL DEFAULT 1 CHECK (restock IN (0,1)),
    refund_mode TEXT NOT NULL DEFAULT 'none' CHECK (refund_mode IN ('none','partial','full')),
    refund_bani INTEGER NOT NULL DEFAULT 0 CHECK (refund_bani >= 0),
    actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_order_returns_order ON order_returns(order_id, created_at);

CREATE TABLE order_return_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id INTEGER NOT NULL REFERENCES order_returns(id) ON DELETE RESTRICT,
    order_item_id INTEGER NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    restocked_quantity INTEGER NOT NULL DEFAULT 0 CHECK (restocked_quantity >= 0 AND restocked_quantity <= quantity),
    line_refund_bani INTEGER NOT NULL DEFAULT 0 CHECK (line_refund_bani >= 0),
    UNIQUE(return_id, order_item_id)
);

CREATE INDEX idx_order_return_items_order_item ON order_return_items(order_item_id);
