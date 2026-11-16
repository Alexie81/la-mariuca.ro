CREATE TABLE request_automation_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    lease_token TEXT NULL,
    lease_until INTEGER NOT NULL DEFAULT 0,
    next_run_at INTEGER NOT NULL DEFAULT 0,
    schedule_version INTEGER NOT NULL DEFAULT 0
);
INSERT INTO request_automation_state (id) VALUES (1);
CREATE TABLE automation_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_key TEXT NOT NULL UNIQUE,
    revision INTEGER NOT NULL DEFAULT 1,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    target_path TEXT NOT NULL,
    created_at TEXT NOT NULL,
    resolved_at TEXT NULL
);
