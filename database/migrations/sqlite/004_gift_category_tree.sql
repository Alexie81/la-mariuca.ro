INSERT OR IGNORE INTO media (
    original_name, storage_path, public_path, mime_type, width, height, size_bytes, alt_text, created_at
) VALUES (
    'cadouri-editorial-2026.webp',
    'seeded/category-images/cadouri-editorial-2026.webp',
    'assets/images/categories/cadouri-editorial-2026.webp',
    'image/webp', 1122, 1402, 202780,
    'Cutie cadou pentru bebeluși cu hăinuțe tricotate, pantofiori, fundă roz și ursuleț',
    CURRENT_TIMESTAMP
);

UPDATE media
SET original_name = 'cadouri-editorial-2026.webp',
    public_path = 'assets/images/categories/cadouri-editorial-2026.webp',
    mime_type = 'image/webp', width = 1122, height = 1402, size_bytes = 202780,
    alt_text = 'Cutie cadou pentru bebeluși cu hăinuțe tricotate, pantofiori, fundă roz și ursuleț'
WHERE storage_path = 'seeded/category-images/cadouri-editorial-2026.webp';

UPDATE categories
SET image_media_id = (
    SELECT id FROM media WHERE storage_path = 'seeded/category-images/cadouri-editorial-2026.webp' LIMIT 1
), updated_at = CURRENT_TIMESTAMP
WHERE slug = 'cadouri';

INSERT OR IGNORE INTO categories (
    parent_id, name, slug, description, position, status, visibility,
    seo_title, meta_description, indexable, published_at, created_at, updated_at
)
SELECT id, 'Cadouri pentru bebeluși', 'cadouri-pentru-bebelusi',
       'Daruri delicate pentru primele luni, alese și pregătite cu grijă.',
       1, 'published', 'public', 'Cadouri pentru bebeluși',
       'Descoperă cadouri delicate pentru nou-născuți și bebeluși.',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM categories WHERE slug = 'cadouri' LIMIT 1;

INSERT OR IGNORE INTO categories (
    parent_id, name, slug, description, position, status, visibility,
    seo_title, meta_description, indexable, published_at, created_at, updated_at
)
SELECT id, 'Seturi cadou', 'seturi-cadou',
       'Seturi cadou asortate pentru bebeluși, gata să aducă bucurie.',
       1, 'published', 'public', 'Seturi cadou pentru bebeluși',
       'Seturi cadou asortate pentru bebeluși, pregătite pentru a fi dăruite.',
       1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM categories WHERE slug = 'cadouri-pentru-bebelusi' LIMIT 1;
