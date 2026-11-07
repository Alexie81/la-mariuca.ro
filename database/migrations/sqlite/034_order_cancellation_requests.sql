CREATE TABLE order_cancellation_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE RESTRICT,
    reason TEXT NOT NULL,
    order_status_at_request TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_order_cancellation_created ON order_cancellation_requests(created_at);
