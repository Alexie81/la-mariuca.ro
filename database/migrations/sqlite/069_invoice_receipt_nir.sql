ALTER TABLE nir_documents ADD COLUMN invoice_receipt_id INTEGER NULL REFERENCES invoice_receipts(id);
CREATE UNIQUE INDEX nir_documents_invoice_receipt ON nir_documents(invoice_receipt_id);
