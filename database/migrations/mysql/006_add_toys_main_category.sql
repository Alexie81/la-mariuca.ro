INSERT IGNORE INTO categories (
    parent_id, name, slug, description, position, status, visibility,
    seo_title, meta_description, indexable, published_at, created_at, updated_at
) VALUES (
    NULL, 'Jucării', 'jucarii',
    'Jucării blânde și daruri pentru joacă, alese pentru copilării pline de imaginație.',
    10, 'published', 'public', 'Jucării pentru copii',
    'Descoperă jucării atent alese pentru bebeluși și copii.',
    1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
);
