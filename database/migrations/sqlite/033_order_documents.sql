CREATE TABLE order_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    document_number TEXT NOT NULL,
    private_path TEXT NOT NULL,
    created_at TEXT NOT NULL,
    revoked_at TEXT NULL
);
CREATE INDEX idx_order_documents_order ON order_documents(order_id, revoked_at);
