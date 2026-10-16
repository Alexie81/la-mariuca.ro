CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    role ENUM('customer','operator','administrator') NOT NULL DEFAULT 'customer',
    status ENUM('active','blocked','pending') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    verification_token_hash CHAR(64) NULL,
    reset_token_hash CHAR(64) NULL,
    reset_expires_at DATETIME NULL,
    totp_secret_encrypted TEXT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL UNIQUE,
    public_path VARCHAR(500) NULL,
    mime_type VARCHAR(80) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    alt_text VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    image_media_id BIGINT UNSIGNED NULL,
    position INT NOT NULL DEFAULT 0,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    visibility ENUM('public','hidden') NOT NULL DEFAULT 'public',
    seo_title VARCHAR(255) NOT NULL DEFAULT '',
    meta_description VARCHAR(500) NOT NULL DEFAULT '',
    indexable TINYINT(1) NOT NULL DEFAULT 1,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_categories_media FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL,
    INDEX idx_categories_parent_status(parent_id, status, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slug_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,
    old_slug VARCHAR(190) NOT NULL,
    new_slug VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_redirect(entity_type, old_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    short_description TEXT NOT NULL,
    description MEDIUMTEXT NOT NULL,
    brand VARCHAR(190) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    primary_category_id BIGINT UNSIGNED NULL,
    seo_title VARCHAR(255) NOT NULL DEFAULT '',
    meta_description VARCHAR(500) NOT NULL DEFAULT '',
    composition TEXT NOT NULL,
    care_instructions TEXT NOT NULL,
    size_guide TEXT NOT NULL,
    package_contents TEXT NOT NULL,
    excluded_accessories TEXT NOT NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    indexable TINYINT(1) NOT NULL DEFAULT 1,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_products_primary_category FOREIGN KEY (primary_category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_products_status_featured(status, featured, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_categories (
    product_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(product_id, category_id),
    CONSTRAINT fk_pc_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_product_categories_category(category_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    ean VARCHAR(64) NULL UNIQUE,
    option_summary VARCHAR(255) NOT NULL DEFAULT '',
    options_json JSON NOT NULL,
    options_key CHAR(64) NOT NULL,
    price_bani INT UNSIGNED NOT NULL,
    compare_price_bani INT UNSIGNED NULL,
    weight_grams INT UNSIGNED NULL,
    image_media_id BIGINT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_variant_media FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL,
    UNIQUE KEY uq_product_options(product_id, options_key),
    INDEX idx_variants_product_status(product_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (product_id BIGINT UNSIGNED NOT NULL, media_id BIGINT UNSIGNED NOT NULL, position INT NOT NULL DEFAULT 0, PRIMARY KEY(product_id, media_id), FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE attributes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, slug VARCHAR(190) NOT NULL UNIQUE, filterable TINYINT(1) NOT NULL DEFAULT 1, variant_defining TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE attribute_values (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attribute_id BIGINT UNSIGNED NOT NULL, value VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL, position INT NOT NULL DEFAULT 0, UNIQUE KEY uq_attribute_value(attribute_id, slug), FOREIGN KEY(attribute_id) REFERENCES attributes(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE product_attribute_values (product_id BIGINT UNSIGNED NOT NULL, attribute_value_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(product_id, attribute_value_id), FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, FOREIGN KEY(attribute_value_id) REFERENCES attribute_values(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE personalization_options (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, type ENUM('text','select','checkbox') NOT NULL, label VARCHAR(190) NOT NULL, allowed_values_json JSON NOT NULL, max_length INT UNSIGNED NULL, required TINYINT(1) NOT NULL DEFAULT 0, surcharge_bani INT UNSIGNED NOT NULL DEFAULT 0, position INT NOT NULL DEFAULT 0, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory (variant_id BIGINT UNSIGNED PRIMARY KEY, physical_quantity INT UNSIGNED NOT NULL DEFAULT 0, reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT, CHECK(reserved_quantity <= physical_quantity)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inventory_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, variant_id BIGINT UNSIGNED NOT NULL, type VARCHAR(60) NOT NULL, physical_delta INT NOT NULL DEFAULT 0, reserved_delta INT NOT NULL DEFAULT 0, reason VARCHAR(500) NOT NULL, reference_type VARCHAR(60) NULL, reference_id BIGINT UNSIGNED NULL, actor_user_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_inventory_movements_variant(variant_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE collections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL UNIQUE, description TEXT NOT NULL, type ENUM('manual','new','sale') NOT NULL DEFAULT 'manual', status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft', indexable TINYINT(1) NOT NULL DEFAULT 1, seo_title VARCHAR(255) NOT NULL DEFAULT '', meta_description VARCHAR(500) NOT NULL DEFAULT '', published_at DATETIME NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE collection_products (collection_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, position INT NOT NULL DEFAULT 0, PRIMARY KEY(collection_id, product_id), FOREIGN KEY(collection_id) REFERENCES collections(id) ON DELETE CASCADE, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE carts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(64) NOT NULL UNIQUE, user_id BIGINT UNSIGNED NULL, status ENUM('active','converted','expired') NOT NULL DEFAULT 'active', coupon_code VARCHAR(100) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE cart_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cart_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, personalization_json JSON NOT NULL, personalization_key CHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_cart_item(cart_id, variant_id, personalization_key), FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE CASCADE, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT, CHECK(quantity > 0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE coupons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(100) NOT NULL UNIQUE, type ENUM('fixed','percent') NOT NULL, value INT UNSIGNED NOT NULL, minimum_bani INT UNSIGNED NOT NULL DEFAULT 0, usage_limit INT UNSIGNED NULL, per_customer_limit INT UNSIGNED NULL, starts_at DATETIME NULL, ends_at DATETIME NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_number VARCHAR(40) NULL UNIQUE, public_token_hash CHAR(64) NOT NULL UNIQUE, idempotency_key CHAR(64) NOT NULL UNIQUE, cart_id BIGINT UNSIGNED NOT NULL UNIQUE, user_id BIGINT UNSIGNED NULL,
    email VARCHAR(254) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, phone VARCHAR(40) NOT NULL, customer_type ENUM('person','company') NOT NULL DEFAULT 'person', company_json JSON NOT NULL, shipping_address_json JSON NOT NULL, billing_address_json JSON NOT NULL,
    subtotal_bani INT UNSIGNED NOT NULL, discount_bani INT UNSIGNED NOT NULL DEFAULT 0, shipping_bani INT UNSIGNED NOT NULL DEFAULT 0, tax_bani INT UNSIGNED NOT NULL DEFAULT 0, total_bani INT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'RON',
    status ENUM('new','confirmed','preparing','shipped','delivered','cancelled','returned','manual_review') NOT NULL DEFAULT 'new', payment_status ENUM('pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'pending', payment_method ENUM('cod','stripe') NOT NULL, shipping_method VARCHAR(80) NOT NULL DEFAULT 'manual', awb VARCHAR(190) NULL, internal_notes TEXT NOT NULL,
    terms_accepted_at DATETIME NOT NULL, marketing_consent TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, shipped_at DATETIME NULL, cancelled_at DATETIME NULL,
    FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE RESTRICT, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_orders_email_created(email, created_at), INDEX idx_orders_status_created(status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL, variant_id BIGINT UNSIGNED NULL, product_name VARCHAR(255) NOT NULL, variant_summary VARCHAR(255) NOT NULL DEFAULT '', sku VARCHAR(100) NOT NULL, quantity INT UNSIGNED NOT NULL, unit_price_bani INT UNSIGNED NOT NULL, personalization_bani INT UNSIGNED NOT NULL DEFAULT 0, line_total_bani INT UNSIGNED NOT NULL, tax_bani INT UNSIGNED NOT NULL DEFAULT 0, personalization_json JSON NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inventory_reservations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status ENUM('active','consumed','released') NOT NULL DEFAULT 'active', expires_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_reservation(order_id, variant_id), FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT, FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(40) NOT NULL, provider_payment_id VARCHAR(255) NULL UNIQUE, amount_bani INT UNSIGNED NOT NULL, refunded_bani INT UNSIGNED NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL DEFAULT 'RON', status VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE payment_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(40) NOT NULL, provider_event_id VARCHAR(255) NOT NULL UNIQUE, event_type VARCHAR(190) NOT NULL, payload_json JSON NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'received', error_message TEXT NULL, received_at DATETIME NOT NULL, processed_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE coupon_redemptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, coupon_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NULL UNIQUE, email VARCHAR(254) NOT NULL, status ENUM('reserved','confirmed','released') NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT, INDEX idx_coupon_redemptions_coupon(coupon_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE addresses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, label VARCHAR(100) NOT NULL, address_json JSON NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE favorites (user_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(user_id, product_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE content_pages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(190) NOT NULL UNIQUE, body_html MEDIUMTEXT NOT NULL, status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft', legal_review_required TINYINT(1) NOT NULL DEFAULT 0, seo_title VARCHAR(255) NOT NULL DEFAULT '', meta_description VARCHAR(500) NOT NULL DEFAULT '', indexable TINYINT(1) NOT NULL DEFAULT 1, published_at DATETIME NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE homepage_sections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type ENUM('hero','categories','products','banner','editorial','faq') NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '', content_json JSON NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE menu_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NULL, label VARCHAR(190) NOT NULL, target_type ENUM('category','collection','page','url') NOT NULL, target_id BIGINT UNSIGNED NULL, url VARCHAR(500) NULL, position INT NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, FOREIGN KEY(parent_id) REFERENCES menu_items(id) ON DELETE CASCADE, INDEX idx_menu_items_parent(parent_id, position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL, is_public TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE outbox_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_type VARCHAR(100) NOT NULL, recipient VARCHAR(254) NOT NULL, payload_json JSON NOT NULL, status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending', attempts INT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, last_error TEXT NULL, created_at DATETIME NOT NULL, sent_at DATETIME NULL, INDEX idx_outbox_status_available(status, available_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE rate_limits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bucket_key CHAR(64) NOT NULL, occurred_at DATETIME NOT NULL, INDEX idx_rate_limits_key_time(bucket_key, occurred_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NULL, context_json JSON NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
