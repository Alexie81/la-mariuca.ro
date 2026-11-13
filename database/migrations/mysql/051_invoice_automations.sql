CREATE TABLE invoice_automation_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    card_issue TINYINT NOT NULL DEFAULT 0,
    card_email TINYINT NOT NULL DEFAULT 0,
    cod_issue TINYINT NOT NULL DEFAULT 0,
    cod_email TINYINT NOT NULL DEFAULT 0,
    revision BIGINT NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL,
    CHECK (id = 1),
    CHECK (card_issue IN (0,1) AND card_email IN (0,1) AND card_email <= card_issue),
    CHECK (cod_issue IN (0,1) AND cod_email IN (0,1) AND cod_email <= cod_issue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO invoice_automation_settings (id,updated_at) VALUES (1,UTC_TIMESTAMP());
CREATE TABLE invoice_automation_intents (
    order_id BIGINT UNSIGNED PRIMARY KEY,
    payment_method VARCHAR(20) NOT NULL,
    send_email TINYINT NOT NULL,
    settings_revision BIGINT NOT NULL,
    issue_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    email_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    created_at VARCHAR(30) NOT NULL,
    CHECK (send_email IN (0,1)),
    FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
