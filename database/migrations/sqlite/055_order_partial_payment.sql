-- Keep all historical payment states while adding partial cash-on-delivery payments.
ALTER TABLE orders RENAME COLUMN payment_status TO legacy_payment_status;
ALTER TABLE orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'
    CHECK (payment_status IN ('pending','partial','paid','failed','partially_refunded','refunded'));
UPDATE orders SET payment_status=legacy_payment_status;
ALTER TABLE orders DROP COLUMN legacy_payment_status;
