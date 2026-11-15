ALTER TABLE nir_documents ADD COLUMN invoice_receipt_id BIGINT UNSIGNED NULL,
 ADD CONSTRAINT nir_documents_invoice_receipt_fk FOREIGN KEY (invoice_receipt_id) REFERENCES invoice_receipts(id),
 ADD UNIQUE INDEX nir_documents_invoice_receipt (invoice_receipt_id);
