CREATE TABLE order_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id INTEGER NOT NULL REFERENCES orders(id),
 request_key VARCHAR(190) NOT NULL UNIQUE,
 amount_bani BIGINT NOT NULL CHECK(amount_bani>0),
 actor_id INTEGER NOT NULL REFERENCES users(id),
 created_at VARCHAR(30) NOT NULL
);
CREATE INDEX idx_order_refunds_order ON order_refunds(order_id,id);
ALTER TABLE invoice_refunds ADD COLUMN order_refund_id INTEGER NULL REFERENCES order_refunds(id);
CREATE INDEX idx_invoice_refunds_order_refund ON invoice_refunds(order_refund_id);
