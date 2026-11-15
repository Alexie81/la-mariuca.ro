ALTER TABLE suppliers ADD COLUMN products_visible_online INTEGER NOT NULL DEFAULT 1 CHECK (products_visible_online IN (0,1));
