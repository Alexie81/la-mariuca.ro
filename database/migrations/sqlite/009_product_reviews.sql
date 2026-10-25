CREATE TABLE product_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    customer_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    author_name TEXT NOT NULL,
    author_email TEXT NOT NULL,
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title TEXT NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    is_verified_purchase INTEGER NOT NULL DEFAULT 0,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    moderated_at TEXT NULL,
    moderated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_product_reviews_public
    ON product_reviews(product_id, status, created_at);
CREATE INDEX idx_product_reviews_moderation
    ON product_reviews(status, created_at);
CREATE INDEX idx_product_reviews_author
    ON product_reviews(product_id, author_email, created_at);
