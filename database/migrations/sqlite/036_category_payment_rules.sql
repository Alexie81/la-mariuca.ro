ALTER TABLE categories ADD COLUMN allow_card INTEGER NOT NULL DEFAULT 1 CHECK (allow_card IN (0, 1));
ALTER TABLE categories ADD COLUMN allow_cod INTEGER NOT NULL DEFAULT 1 CHECK (allow_cod IN (0, 1));
