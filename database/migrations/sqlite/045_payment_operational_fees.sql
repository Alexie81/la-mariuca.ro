CREATE TABLE payment_operational_fees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_method TEXT NOT NULL CHECK (payment_method IN ('cod','stripe')),
    name TEXT NOT NULL,
    amount_bani INTEGER NOT NULL CHECK (amount_bani > 0 AND amount_bani <= 1000000),
    created_by INTEGER NULL REFERENCES users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
CREATE INDEX idx_payment_operational_fees_method ON payment_operational_fees(payment_method,deleted_at,id);
ALTER TABLE orders ADD COLUMN payment_fee_bani INTEGER NOT NULL DEFAULT 0 CHECK (payment_fee_bani >= 0);
ALTER TABLE orders ADD COLUMN payment_fees_json TEXT NOT NULL DEFAULT '[]';
