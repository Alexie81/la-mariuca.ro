CREATE TABLE dispatch_note_sequences (
 year INTEGER PRIMARY KEY,
 next_number INTEGER NOT NULL CHECK(next_number > 0)
);
CREATE TABLE dispatch_notes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 document_key TEXT NOT NULL UNIQUE,
 order_id INTEGER NOT NULL REFERENCES orders(id),
 active_order_id INTEGER NULL UNIQUE REFERENCES orders(id),
 series_code TEXT NOT NULL,
 number INTEGER NOT NULL,
 issued_at TEXT NOT NULL,
 snapshot_json TEXT NOT NULL,
 row_version INTEGER NOT NULL DEFAULT 1,
 created_by INTEGER NOT NULL REFERENCES users(id),
 deleted_at TEXT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(series_code,number)
);
CREATE INDEX dispatch_notes_order ON dispatch_notes(order_id,deleted_at,id);
CREATE TABLE dispatch_note_actions (
 request_key TEXT PRIMARY KEY,
 action TEXT NOT NULL,
 request_hash TEXT NOT NULL,
 dispatch_note_id INTEGER NOT NULL REFERENCES dispatch_notes(id),
 created_at TEXT NOT NULL
);
