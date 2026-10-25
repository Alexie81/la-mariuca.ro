ALTER TABLE product_reviews
    ADD COLUMN order_number VARCHAR(40) NOT NULL DEFAULT '' AFTER author_email,
    ADD INDEX idx_product_reviews_order_number (order_number);
