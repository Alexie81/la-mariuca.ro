ALTER TABLE dispatch_notes ADD COLUMN active_number BIGINT NULL AFTER number;
UPDATE dispatch_notes SET active_number=number WHERE deleted_at IS NULL;
ALTER TABLE dispatch_notes DROP INDEX dispatch_note_number, ADD UNIQUE KEY dispatch_note_active_number(series_code,active_number);
UPDATE dispatch_note_sequences s SET next_number=(SELECT COALESCE(MAX(n.active_number),0)+1 FROM dispatch_notes n WHERE n.series_code=CONCAT('LM-AVZ-',s.year));
