ALTER TABLE orders MODIFY COLUMN payment_status
    ENUM('pending','partial','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'pending';
