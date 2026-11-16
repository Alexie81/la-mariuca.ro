ALTER TABLE categories ADD COLUMN homepage_section VARCHAR(32) DEFAULT NULL;
CREATE UNIQUE INDEX categories_homepage_section_unique ON categories(homepage_section);

UPDATE categories SET homepage_section='traditional' WHERE slug='costume-traditionale';
UPDATE categories SET homepage_section='baptism' WHERE slug='botez';
