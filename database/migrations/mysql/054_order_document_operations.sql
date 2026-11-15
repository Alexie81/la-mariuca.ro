CREATE TABLE order_document_operations (
 request_key VARCHAR(190) PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL, payload_hash VARCHAR(64) NOT NULL, result_id BIGINT UNSIGNED NULL,
 created_at VARCHAR(30) NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE order_return_documents (
 return_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
 PRIMARY KEY(return_id,invoice_id), FOREIGN KEY(return_id) REFERENCES order_returns(id),
 FOREIGN KEY(invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
