INSERT OR IGNORE INTO categories (
    parent_id, name, slug, description, position, status, visibility,
    seo_title, meta_description, indexable, published_at, created_at, updated_at
) VALUES
    (NULL, 'Ținute de ocazie', 'tinute-de-ocazie', 'Ținute elegante pentru aniversări, serbări și cele mai frumoase momente ale copilăriei.', 7, 'published', 'public', 'Ținute de ocazie pentru copii', 'Descoperă ținute de ocazie elegante pentru fetițe și băieți, alese cu drag.', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (NULL, 'Pijamale', 'pijamale', 'Pijamale moi și confortabile pentru seri liniștite și dimineți cu zâmbete.', 8, 'published', 'public', 'Pijamale pentru copii', 'Pijamale confortabile pentru copii, în materiale plăcute și modele delicate.', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    (NULL, 'Cadouri', 'cadouri', 'Idei de cadouri atent alese pentru nou-născuți, aniversări și ocazii speciale.', 9, 'published', 'public', 'Cadouri pentru copii', 'Idei de cadouri pentru copii și nou-născuți, pregătite pentru momente speciale.', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
