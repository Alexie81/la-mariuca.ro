CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'customer' CHECK (role IN ('customer','operator','administrator')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked','pending')),
    email_verified_at TEXT NULL,
    verification_token_hash TEXT NULL,
    reset_token_hash TEXT NULL,
    reset_expires_at TEXT NULL,
    totp_secret_encrypted TEXT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_name TEXT NOT NULL,
    storage_path TEXT NOT NULL UNIQUE,
    public_path TEXT NULL,
    mime_type TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    size_bytes INTEGER NOT NULL,
    alt_text TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES categories(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    image_media_id INTEGER NULL REFERENCES media(id) ON DELETE SET NULL,
    position INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    visibility TEXT NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','hidden')),
    seo_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    indexable INTEGER NOT NULL DEFAULT 1,
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_categories_parent_status ON categories(parent_id, status, position);

CREATE TABLE slug_redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    old_slug TEXT NOT NULL,
    new_slug TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(entity_type, old_slug)
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    short_description TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    brand TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    primary_category_id INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
    seo_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    composition TEXT NOT NULL DEFAULT '',
    care_instructions TEXT NOT NULL DEFAULT '',
    size_guide TEXT NOT NULL DEFAULT '',
    package_contents TEXT NOT NULL DEFAULT '',
    excluded_accessories TEXT NOT NULL DEFAULT '',
    featured INTEGER NOT NULL DEFAULT 0,
    indexable INTEGER NOT NULL DEFAULT 1,
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_products_status_featured ON products(status, featured, published_at);

CREATE TABLE product_categories (
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    PRIMARY KEY(product_id, category_id)
);
CREATE INDEX idx_product_categories_category ON product_categories(category_id, product_id);

CREATE TABLE product_variants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku TEXT NOT NULL UNIQUE,
    ean TEXT NULL UNIQUE,
    option_summary TEXT NOT NULL DEFAULT '',
    options_json TEXT NOT NULL DEFAULT '{}',
    options_key TEXT NOT NULL DEFAULT '',
    price_bani INTEGER NOT NULL CHECK (price_bani >= 0),
    compare_price_bani INTEGER NULL CHECK (compare_price_bani IS NULL OR compare_price_bani >= price_bani),
    weight_grams INTEGER NULL CHECK (weight_grams IS NULL OR weight_grams >= 0),
    image_media_id INTEGER NULL REFERENCES media(id) ON DELETE SET NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(product_id, options_key)
);
CREATE INDEX idx_variants_product_status ON product_variants(product_id, status);

CREATE TABLE product_images (
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(product_id, media_id)
);

CREATE TABLE attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    filterable INTEGER NOT NULL DEFAULT 1,
    variant_defining INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE attribute_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attribute_id INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
    value TEXT NOT NULL,
    slug TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    UNIQUE(attribute_id, slug)
);
CREATE TABLE product_attribute_values (
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    attribute_value_id INTEGER NOT NULL REFERENCES attribute_values(id) ON DELETE CASCADE,
    PRIMARY KEY(product_id, attribute_value_id)
);

CREATE TABLE personalization_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('text','select','checkbox')),
    label TEXT NOT NULL,
    allowed_values_json TEXT NOT NULL DEFAULT '[]',
    max_length INTEGER NULL,
    required INTEGER NOT NULL DEFAULT 0,
    surcharge_bani INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE inventory (
    variant_id INTEGER PRIMARY KEY REFERENCES product_variants(id) ON DELETE RESTRICT,
    physical_quantity INTEGER NOT NULL DEFAULT 0 CHECK (physical_quantity >= 0),
    reserved_quantity INTEGER NOT NULL DEFAULT 0 CHECK (reserved_quantity >= 0 AND reserved_quantity <= physical_quantity),
    updated_at TEXT NOT NULL
);
CREATE TABLE inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    type TEXT NOT NULL,
    physical_delta INTEGER NOT NULL DEFAULT 0,
    reserved_delta INTEGER NOT NULL DEFAULT 0,
    reason TEXT NOT NULL,
    reference_type TEXT NULL,
    reference_id INTEGER NULL,
    actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_inventory_movements_variant ON inventory_movements(variant_id, created_at);

CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT 'manual' CHECK (type IN ('manual','new','sale')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    indexable INTEGER NOT NULL DEFAULT 1,
    seo_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    published_at TEXT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE collection_products (
    collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY(collection_id, product_id)
);

CREATE TABLE carts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','converted','expired')),
    coupon_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE TABLE cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cart_id INTEGER NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    personalization_json TEXT NOT NULL DEFAULT '{}',
    personalization_key TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(cart_id, variant_id, personalization_key)
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL CHECK (type IN ('fixed','percent')),
    value INTEGER NOT NULL CHECK (value > 0),
    minimum_bani INTEGER NOT NULL DEFAULT 0,
    usage_limit INTEGER NULL,
    per_customer_limit INTEGER NULL,
    starts_at TEXT NULL,
    ends_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TEXT NOT NULL
);

CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_number TEXT NULL UNIQUE,
    public_token_hash TEXT NOT NULL UNIQUE,
    idempotency_key TEXT NOT NULL UNIQUE,
    cart_id INTEGER NOT NULL UNIQUE REFERENCES carts(id) ON DELETE RESTRICT,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    email TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    phone TEXT NOT NULL,
    customer_type TEXT NOT NULL DEFAULT 'person' CHECK (customer_type IN ('person','company')),
    company_json TEXT NOT NULL DEFAULT '{}',
    shipping_address_json TEXT NOT NULL,
    billing_address_json TEXT NOT NULL,
    subtotal_bani INTEGER NOT NULL,
    discount_bani INTEGER NOT NULL DEFAULT 0,
    shipping_bani INTEGER NOT NULL DEFAULT 0,
    tax_bani INTEGER NOT NULL DEFAULT 0,
    total_bani INTEGER NOT NULL,
    currency TEXT NOT NULL DEFAULT 'RON',
    status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','confirmed','preparing','shipped','delivered','cancelled','returned','manual_review')),
    payment_status TEXT NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending','paid','failed','partially_refunded','refunded')),
    payment_method TEXT NOT NULL CHECK (payment_method IN ('cod','stripe')),
    shipping_method TEXT NOT NULL DEFAULT 'manual',
    awb TEXT NULL,
    internal_notes TEXT NOT NULL DEFAULT '',
    terms_accepted_at TEXT NOT NULL,
    marketing_consent INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    shipped_at TEXT NULL,
    cancelled_at TEXT NULL
);
CREATE INDEX idx_orders_email_created ON orders(email, created_at);
CREATE INDEX idx_orders_status_created ON orders(status, created_at);

CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    product_id INTEGER NULL REFERENCES products(id) ON DELETE SET NULL,
    variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE SET NULL,
    product_name TEXT NOT NULL,
    variant_summary TEXT NOT NULL DEFAULT '',
    sku TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    unit_price_bani INTEGER NOT NULL,
    personalization_bani INTEGER NOT NULL DEFAULT 0,
    line_total_bani INTEGER NOT NULL,
    tax_bani INTEGER NOT NULL DEFAULT 0,
    personalization_json TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE inventory_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    variant_id INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','consumed','released')),
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(order_id, variant_id)
);

CREATE TABLE payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    provider TEXT NOT NULL,
    provider_payment_id TEXT NULL UNIQUE,
    amount_bani INTEGER NOT NULL,
    refunded_bani INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'RON',
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE payment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    provider_event_id TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'received',
    error_message TEXT NULL,
    received_at TEXT NOT NULL,
    processed_at TEXT NULL
);

CREATE TABLE coupon_redemptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL REFERENCES coupons(id) ON DELETE RESTRICT,
    order_id INTEGER NULL UNIQUE REFERENCES orders(id) ON DELETE RESTRICT,
    email TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('reserved','confirmed','released')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_coupon_redemptions_coupon ON coupon_redemptions(coupon_id, status);

CREATE TABLE addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    address_json TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE favorites (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY(user_id, product_id)
);

CREATE TABLE content_pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    body_html TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    legal_review_required INTEGER NOT NULL DEFAULT 0,
    seo_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    indexable INTEGER NOT NULL DEFAULT 1,
    published_at TEXT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE homepage_sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL CHECK (type IN ('hero','categories','products','banner','editorial','faq')),
    title TEXT NOT NULL DEFAULT '',
    content_json TEXT NOT NULL DEFAULT '{}',
    enabled INTEGER NOT NULL DEFAULT 1,
    position INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);
CREATE TABLE menu_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    target_type TEXT NOT NULL CHECK (target_type IN ('category','collection','page','url')),
    target_id INTEGER NULL,
    url TEXT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_menu_items_parent ON menu_items(parent_id, position);

CREATE TABLE settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);
CREATE TABLE outbox_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_type TEXT NOT NULL,
    recipient TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','sent','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    sent_at TEXT NULL
);
CREATE INDEX idx_outbox_status_available ON outbox_messages(status, available_at);
CREATE TABLE rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_key TEXT NOT NULL,
    occurred_at TEXT NOT NULL
);
CREATE INDEX idx_rate_limits_key_time ON rate_limits(bucket_key, occurred_at);
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NULL,
    context_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
