CREATE TABLE order_document_operations (
 request_key TEXT PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id),
 action TEXT NOT NULL, payload_hash TEXT NOT NULL, result_id INTEGER NULL, created_at TEXT NOT NULL
);
CREATE TABLE order_return_documents (
 return_id INTEGER NOT NULL REFERENCES order_returns(id),
 invoice_id INTEGER NOT NULL UNIQUE REFERENCES invoices(id),
 PRIMARY KEY(return_id,invoice_id)
);
