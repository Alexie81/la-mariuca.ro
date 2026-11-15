ALTER TABLE order_returns ADD COLUMN status_before TEXT NULL;
ALTER TABLE order_returns ADD COLUMN voided_at TEXT NULL;
ALTER TABLE order_returns ADD COLUMN voided_by INTEGER NULL REFERENCES users(id);
ALTER TABLE order_returns ADD COLUMN void_reason TEXT NULL;
CREATE INDEX idx_order_returns_active ON order_returns(order_id,voided_at);
