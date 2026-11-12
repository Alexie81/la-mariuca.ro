ALTER TABLE nir_documents ADD COLUMN draft_sequence INTEGER NULL;
UPDATE nir_documents SET draft_sequence=(SELECT COUNT(*) FROM nir_documents preceding WHERE preceding.status='draft' AND preceding.id<=nir_documents.id) WHERE status='draft';
CREATE UNIQUE INDEX nir_documents_draft_sequence ON nir_documents(draft_sequence);
CREATE TABLE nir_draft_sequence (id INTEGER PRIMARY KEY, next_number INTEGER NOT NULL);
INSERT INTO nir_draft_sequence(id,next_number) SELECT 1,COALESCE(MAX(draft_sequence),0)+1 FROM nir_documents;
