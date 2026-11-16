CREATE TABLE request_automation_state (
    id INT PRIMARY KEY,
    lease_token VARCHAR(64) NULL,
    lease_until BIGINT NOT NULL DEFAULT 0,
    next_run_at BIGINT NOT NULL DEFAULT 0,
    schedule_version INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO request_automation_state (id) VALUES (1);
CREATE TABLE automation_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(190) NOT NULL UNIQUE,
    revision INT NOT NULL DEFAULT 1,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_path VARCHAR(255) NOT NULL,
    created_at VARCHAR(35) NOT NULL,
    resolved_at VARCHAR(35) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
