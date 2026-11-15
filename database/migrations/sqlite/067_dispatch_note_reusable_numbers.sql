-- Rebuild the parent and its only FK child without disabling foreign keys.
CREATE TEMP TABLE dispatch_note_actions_backup AS SELECT * FROM dispatch_note_actions;
DROP TABLE dispatch_note_actions;
CREATE TABLE dispatch_notes_rebuilt (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 document_key TEXT NOT NULL UNIQUE,
 order_id INTEGER NOT NULL REFERENCES orders(id),
 active_order_id INTEGER NULL UNIQUE REFERENCES orders(id),
 series_code TEXT NOT NULL,
 number INTEGER NOT NULL,
 active_number INTEGER NULL,
 issued_at TEXT NOT NULL,
 snapshot_json TEXT NOT NULL,
 row_version INTEGER NOT NULL DEFAULT 1,
 created_by INTEGER NOT NULL REFERENCES users(id),
 deleted_at TEXT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(series_code,active_number)
);
INSERT INTO dispatch_notes_rebuilt SELECT id,document_key,order_id,active_order_id,series_code,number,CASE WHEN deleted_at IS NULL THEN number ELSE NULL END,issued_at,snapshot_json,row_version,created_by,deleted_at,updated_at FROM dispatch_notes;
DROP TABLE dispatch_notes;
ALTER TABLE dispatch_notes_rebuilt RENAME TO dispatch_notes;
CREATE INDEX dispatch_notes_order ON dispatch_notes(order_id,deleted_at,id);
CREATE TABLE dispatch_note_actions (
 request_key TEXT PRIMARY KEY,
 action TEXT NOT NULL,
 request_hash TEXT NOT NULL,
 dispatch_note_id INTEGER NOT NULL REFERENCES dispatch_notes(id),
 created_at TEXT NOT NULL
);
INSERT INTO dispatch_note_actions SELECT * FROM dispatch_note_actions_backup;
DROP TABLE dispatch_note_actions_backup;
UPDATE dispatch_note_sequences SET next_number=(SELECT COALESCE(MAX(active_number),0)+1 FROM dispatch_notes WHERE series_code='LM-AVZ-' || dispatch_note_sequences.year);
