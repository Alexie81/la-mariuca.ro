CREATE TABLE dispatch_note_sequences (
 year BIGINT PRIMARY KEY,
 next_number BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE dispatch_notes (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,
 document_key VARCHAR(128) NOT NULL UNIQUE,
 order_id BIGINT NOT NULL,
 active_order_id BIGINT NULL UNIQUE,
 series_code VARCHAR(30) NOT NULL,
 number BIGINT NOT NULL,
 issued_at VARCHAR(30) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 row_version BIGINT NOT NULL DEFAULT 1,
 created_by BIGINT NOT NULL,
 deleted_at VARCHAR(30) NULL,
 updated_at VARCHAR(30) NOT NULL,
 UNIQUE KEY dispatch_note_number(series_code,number),
 KEY dispatch_notes_order(order_id,deleted_at,id),
 CONSTRAINT dispatch_notes_order_fk FOREIGN KEY(order_id) REFERENCES orders(id),
 CONSTRAINT dispatch_notes_active_order_fk FOREIGN KEY(active_order_id) REFERENCES orders(id),
 CONSTRAINT dispatch_notes_user_fk FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE dispatch_note_actions (
 request_key VARCHAR(128) PRIMARY KEY,
 action VARCHAR(30) NOT NULL,
 request_hash VARCHAR(64) NOT NULL,
 dispatch_note_id BIGINT NOT NULL,
 created_at VARCHAR(30) NOT NULL,
 CONSTRAINT dispatch_note_actions_note_fk FOREIGN KEY(dispatch_note_id) REFERENCES dispatch_notes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
