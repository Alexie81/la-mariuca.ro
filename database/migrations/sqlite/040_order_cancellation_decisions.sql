ALTER TABLE order_cancellation_requests ADD COLUMN status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','rejected'));
ALTER TABLE order_cancellation_requests ADD COLUMN decision_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE order_cancellation_requests ADD COLUMN resolved_at TEXT NULL;
ALTER TABLE order_cancellation_requests ADD COLUMN resolved_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX idx_order_cancellation_status_created ON order_cancellation_requests(status,created_at);
