ALTER TABLE nir_documents ADD COLUMN draft_sequence BIGINT NULL;
UPDATE nir_documents d JOIN (SELECT id,ROW_NUMBER() OVER (ORDER BY id) AS ordinal FROM nir_documents WHERE status='draft') numbered ON numbered.id=d.id SET d.draft_sequence=numbered.ordinal;
CREATE UNIQUE INDEX nir_documents_draft_sequence ON nir_documents(draft_sequence);
CREATE TABLE nir_draft_sequence (id BIGINT PRIMARY KEY, next_number BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO nir_draft_sequence(id,next_number) SELECT 1,COALESCE(MAX(draft_sequence),0)+1 FROM nir_documents;
