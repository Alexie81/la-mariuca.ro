ALTER TABLE order_returns ADD COLUMN status_before VARCHAR(40) NULL,
 ADD COLUMN voided_at VARCHAR(32) NULL,
 ADD COLUMN voided_by BIGINT UNSIGNED NULL,
 ADD COLUMN void_reason TEXT NULL,
 ADD INDEX idx_order_returns_active(order_id,voided_at),
 ADD CONSTRAINT fk_order_return_void_actor FOREIGN KEY(voided_by) REFERENCES users(id) ON DELETE SET NULL;
