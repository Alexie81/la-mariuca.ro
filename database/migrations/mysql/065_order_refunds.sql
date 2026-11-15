CREATE TABLE order_refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(190) NOT NULL UNIQUE,
 amount_bani BIGINT NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at VARCHAR(30) NOT NULL,
 FOREIGN KEY (order_id) REFERENCES orders(id),
 FOREIGN KEY (actor_id) REFERENCES users(id),
 INDEX idx_order_refunds_order(order_id,id),
 CHECK(amount_bani>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE invoice_refunds ADD COLUMN order_refund_id BIGINT UNSIGNED NULL,
 ADD INDEX idx_invoice_refunds_order_refund(order_refund_id),
 ADD CONSTRAINT fk_invoice_refunds_order_refund FOREIGN KEY(order_refund_id) REFERENCES order_refunds(id);
