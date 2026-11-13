CREATE TABLE invoice_automation_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    card_issue INTEGER NOT NULL DEFAULT 0 CHECK (card_issue IN (0,1)),
    card_email INTEGER NOT NULL DEFAULT 0 CHECK (card_email IN (0,1) AND card_email <= card_issue),
    cod_issue INTEGER NOT NULL DEFAULT 0 CHECK (cod_issue IN (0,1)),
    cod_email INTEGER NOT NULL DEFAULT 0 CHECK (cod_email IN (0,1) AND cod_email <= cod_issue),
    revision INTEGER NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL
);
INSERT INTO invoice_automation_settings (id,updated_at) VALUES (1,CURRENT_TIMESTAMP);
-- Integration seam only: no billing dispatcher is enabled by this migration.
CREATE TABLE invoice_automation_intents (
    order_id INTEGER PRIMARY KEY REFERENCES orders(id),
    payment_method VARCHAR(20) NOT NULL,
    send_email INTEGER NOT NULL CHECK (send_email IN (0,1)),
    settings_revision INTEGER NOT NULL,
    issue_key VARCHAR(100) NOT NULL UNIQUE,
    email_key VARCHAR(100) NOT NULL UNIQUE,
    created_at VARCHAR(30) NOT NULL
);
