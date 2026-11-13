CREATE TABLE invoice_series (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(30) NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT '',
    deleted_at VARCHAR(30) NULL,
    created_at VARCHAR(30) NOT NULL,
    updated_at VARCHAR(30) NOT NULL
);
CREATE INDEX invoice_series_visible ON invoice_series(deleted_at, id);
INSERT INTO invoice_series (code,label,created_at,updated_at) VALUES ('LM','Facturi La Măriuca',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
CREATE TABLE invoice_configuration (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    active_series_id INTEGER NOT NULL REFERENCES invoice_series(id),
    due_days INTEGER NOT NULL DEFAULT 0 CHECK (due_days BETWEEN 0 AND 3650),
    default_notes TEXT NOT NULL DEFAULT '',
    revision INTEGER NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL
);
INSERT INTO invoice_configuration (id,active_series_id,updated_at) VALUES (1,1,CURRENT_TIMESTAMP);
-- Frozen at issuance, never joined to mutable series/settings when rendering an invoice.
CREATE TABLE invoice_configuration_snapshots (
    document_key VARCHAR(128) PRIMARY KEY,
    series_id INTEGER NOT NULL,
    series_code VARCHAR(30) NOT NULL,
    series_label VARCHAR(100) NOT NULL,
    invoice_number INTEGER NOT NULL CHECK (invoice_number > 0),
    due_days INTEGER NOT NULL,
    default_notes TEXT NOT NULL,
    issued_at VARCHAR(30) NOT NULL,
    due_date VARCHAR(10) NOT NULL,
    configuration_revision INTEGER NOT NULL,
    UNIQUE (series_code,invoice_number)
);
