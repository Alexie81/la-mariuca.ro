ALTER TABLE order_return_requests ADD COLUMN refund_details_json LONGTEXT NULL;
ALTER TABLE order_returns ADD COLUMN settlement_json LONGTEXT NULL;
