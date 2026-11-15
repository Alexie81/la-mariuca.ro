CREATE TABLE order_return_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id),
    request_key TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','accepted','rejected')),
    reason TEXT NOT NULL,
    quantities_json TEXT NOT NULL,
    decision_reason TEXT NOT NULL DEFAULT '',
    return_id INTEGER REFERENCES order_returns(id),
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    decided_by INTEGER REFERENCES users(id),
    decided_at TEXT
);
CREATE INDEX idx_order_return_requests_order ON order_return_requests(order_id,state);
