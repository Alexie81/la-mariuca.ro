ALTER TABLE order_cancellation_requests
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN decision_reason VARCHAR(1000) NOT NULL DEFAULT '',
    ADD COLUMN resolved_at DATETIME NULL,
    ADD COLUMN resolved_by BIGINT UNSIGNED NULL,
    ADD CONSTRAINT chk_order_cancellation_status CHECK (status IN ('pending','accepted','rejected')),
    ADD CONSTRAINT fk_order_cancellation_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_order_cancellation_status_created (status,created_at);
