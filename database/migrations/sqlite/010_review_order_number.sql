ALTER TABLE product_reviews ADD COLUMN order_number TEXT NOT NULL DEFAULT '';

CREATE INDEX idx_product_reviews_order_number
    ON product_reviews(order_number);
