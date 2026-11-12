ALTER TABLE company_warehouses ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;
CREATE TABLE nir_write_lock (id INTEGER PRIMARY KEY, revision BIGINT NOT NULL DEFAULT 0);
INSERT INTO nir_write_lock(id,revision) VALUES (1,0);
CREATE TABLE nir_sequences (year INTEGER PRIMARY KEY, next_number BIGINT NOT NULL);
CREATE TABLE nir_locations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, warehouse_id INTEGER NOT NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT,
 name VARCHAR(190) NOT NULL, address TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, is_default INTEGER NOT NULL DEFAULT 0,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
);
INSERT INTO nir_locations(warehouse_id,name,address,is_active,is_default,created_at,updated_at)
 SELECT w.id,w.name,CASE WHEN c.physical_address<>'' THEN c.physical_address ELSE c.registered_address END,1,1,w.created_at,w.updated_at FROM company_warehouses w JOIN companies c ON c.id=w.company_id;
CREATE INDEX nir_locations_warehouse ON nir_locations(warehouse_id,is_active);
CREATE TABLE nir_documents (
 id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(50) NULL UNIQUE, temp_number VARCHAR(60) NOT NULL UNIQUE,
 type VARCHAR(30) NOT NULL DEFAULT 'supplier_receipt', status VARCHAR(20) NOT NULL DEFAULT 'draft', row_version INTEGER NOT NULL DEFAULT 1,
 supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE RESTRICT, company_id INTEGER NULL REFERENCES companies(id) ON DELETE RESTRICT,
 warehouse_id INTEGER NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT, location_id INTEGER NULL REFERENCES nir_locations(id) ON DELETE RESTRICT,
 invoice_series VARCHAR(100) NOT NULL DEFAULT '', invoice_number VARCHAR(190) NOT NULL DEFAULT '', invoice_date VARCHAR(10) NOT NULL DEFAULT '',
 nir_at VARCHAR(32) NOT NULL DEFAULT '', received_at VARCHAR(32) NOT NULL DEFAULT '', currency VARCHAR(3) NOT NULL DEFAULT 'RON', exchange_rate VARCHAR(32) NOT NULL DEFAULT '1', exchange_rate_date VARCHAR(10) NOT NULL DEFAULT '',
 notes TEXT NOT NULL, external_id VARCHAR(190) NOT NULL DEFAULT '', reason TEXT NOT NULL,
 supplier_snapshot TEXT NOT NULL, company_snapshot TEXT NOT NULL, warehouse_snapshot TEXT NOT NULL, location_snapshot TEXT NOT NULL,
 net_bani BIGINT NOT NULL DEFAULT 0, tax_bani BIGINT NOT NULL DEFAULT 0, total_bani BIGINT NOT NULL DEFAULT 0, discount_bani BIGINT NOT NULL DEFAULT 0,
 net_ron_bani BIGINT NOT NULL DEFAULT 0, tax_ron_bani BIGINT NOT NULL DEFAULT 0, total_ron_bani BIGINT NOT NULL DEFAULT 0, inventory_bani BIGINT NOT NULL DEFAULT 0,
 vat_in_cost INTEGER NOT NULL DEFAULT 0, fingerprint VARCHAR(64) NULL, original_id INTEGER NULL REFERENCES nir_documents(id) ON DELETE RESTRICT,
 return_id INTEGER NULL REFERENCES order_returns(id) ON DELETE RESTRICT, order_id INTEGER NULL REFERENCES orders(id) ON DELETE RESTRICT,
 created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, confirmed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, confirmed_at VARCHAR(32) NULL
);
CREATE INDEX nir_documents_date ON nir_documents(nir_at,status);
CREATE INDEX nir_documents_supplier ON nir_documents(supplier_id,invoice_number,invoice_date);
CREATE INDEX nir_documents_original ON nir_documents(original_id);
CREATE UNIQUE INDEX nir_documents_fingerprint ON nir_documents(fingerprint);
CREATE UNIQUE INDEX nir_documents_return ON nir_documents(return_id);
CREATE TABLE nir_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NOT NULL REFERENCES nir_documents(id) ON DELETE CASCADE, position INTEGER NOT NULL,
 product_id INTEGER NULL REFERENCES products(id) ON DELETE RESTRICT, variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
 scope_type VARCHAR(10) NULL, scope_id BIGINT NULL, tracked INTEGER NOT NULL DEFAULT 1,
 supplier_code VARCHAR(190) NOT NULL DEFAULT '', supplier_name TEXT NOT NULL, supplier_ean VARCHAR(100) NOT NULL DEFAULT '',
 product_name TEXT NOT NULL, sku VARCHAR(190) NOT NULL DEFAULT '', image_path TEXT NOT NULL, category_name TEXT NOT NULL, brand VARCHAR(190) NOT NULL DEFAULT '',
 purchase_unit VARCHAR(30) NOT NULL DEFAULT 'buc', stock_unit VARCHAR(30) NOT NULL DEFAULT 'buc',
 invoice_quantity VARCHAR(32) NOT NULL, received_quantity VARCHAR(32) NOT NULL, accepted_quantity VARCHAR(32) NOT NULL, rejected_quantity VARCHAR(32) NOT NULL,
 conversion_factor VARCHAR(32) NOT NULL, stock_quantity VARCHAR(32) NOT NULL, stock_quantity_units BIGINT NOT NULL,
 price_mode VARCHAR(20) NOT NULL DEFAULT 'unit', unit_price VARCHAR(40) NOT NULL, discount_percent VARCHAR(32) NOT NULL, vat_percent VARCHAR(32) NOT NULL,
 extra_cost_ron VARCHAR(40) NOT NULL, net_override VARCHAR(40) NOT NULL DEFAULT '', tax_override VARCHAR(40) NOT NULL DEFAULT '', gross_override VARCHAR(40) NOT NULL DEFAULT '',
 net_bani BIGINT NOT NULL, tax_bani BIGINT NOT NULL, total_bani BIGINT NOT NULL, discount_bani BIGINT NOT NULL,
 net_ron_bani BIGINT NOT NULL, tax_ron_bani BIGINT NOT NULL, total_ron_bani BIGINT NOT NULL, inventory_bani BIGINT NOT NULL, unit_cost_ron VARCHAR(40) NOT NULL,
 difference_reason VARCHAR(50) NOT NULL DEFAULT '', difference_notes TEXT NOT NULL, save_reference INTEGER NOT NULL DEFAULT 0,
 original_line_id INTEGER NULL REFERENCES nir_lines(id) ON DELETE RESTRICT, return_item_id INTEGER NULL,
 UNIQUE(nir_id,position)
);
CREATE INDEX nir_lines_scope ON nir_lines(scope_type,scope_id,nir_id);
CREATE TABLE nir_supplier_references (
 id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
 product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT, variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
 supplier_code VARCHAR(190) NOT NULL, normalized_code VARCHAR(220) NOT NULL, supplier_name TEXT NOT NULL, normalized_name VARCHAR(255) NOT NULL,
 supplier_ean VARCHAR(100) NOT NULL DEFAULT '', purchase_unit VARCHAR(30) NOT NULL, stock_unit VARCHAR(30) NOT NULL, conversion_factor VARCHAR(32) NOT NULL,
 is_primary INTEGER NOT NULL DEFAULT 0, last_price VARCHAR(40) NULL, last_currency VARCHAR(3) NULL, last_received_at VARCHAR(32) NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE(supplier_id,normalized_code)
);
CREATE INDEX nir_refs_ean ON nir_supplier_references(supplier_id,supplier_ean);
CREATE TABLE nir_stock_balances (
 id INTEGER PRIMARY KEY AUTOINCREMENT, warehouse_id INTEGER NOT NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT,
 scope_type VARCHAR(10) NOT NULL, scope_id BIGINT NOT NULL, product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
 variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT, sku VARCHAR(190) NOT NULL,
 quantity_units BIGINT NOT NULL DEFAULT 0, unit_cost_ron VARCHAR(40) NOT NULL DEFAULT '0', updated_at VARCHAR(32) NOT NULL,
 UNIQUE(warehouse_id,scope_type,scope_id)
);
CREATE TABLE nir_stock_movements (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NULL REFERENCES nir_documents(id) ON DELETE RESTRICT, line_id INTEGER NULL REFERENCES nir_lines(id) ON DELETE RESTRICT,
 warehouse_id INTEGER NOT NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT, scope_type VARCHAR(10) NOT NULL, scope_id BIGINT NOT NULL,
 type VARCHAR(40) NOT NULL, quantity_units BIGINT NOT NULL, before_units BIGINT NOT NULL, after_units BIGINT NOT NULL,
 event_key VARCHAR(190) NOT NULL UNIQUE, source_type VARCHAR(40) NOT NULL, source_id BIGINT NULL, actor_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, created_at VARCHAR(32) NOT NULL
);
CREATE TABLE nir_fifo_layers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NOT NULL REFERENCES nir_documents(id) ON DELETE RESTRICT, line_id INTEGER NOT NULL REFERENCES nir_lines(id) ON DELETE RESTRICT,
 warehouse_id INTEGER NOT NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT, scope_type VARCHAR(10) NOT NULL, scope_id BIGINT NOT NULL,
 initial_units BIGINT NOT NULL, remaining_units BIGINT NOT NULL, initial_value_bani BIGINT NOT NULL, remaining_value_bani BIGINT NOT NULL,
 unit_cost_ron VARCHAR(40) NOT NULL, received_at VARCHAR(32) NOT NULL, created_at VARCHAR(32) NOT NULL,
 original_layer_id INTEGER NULL REFERENCES nir_fifo_layers(id) ON DELETE RESTRICT
);
CREATE INDEX nir_fifo_available ON nir_fifo_layers(scope_type,scope_id,warehouse_id,received_at,id);
CREATE TABLE nir_fifo_consumptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, layer_id INTEGER NOT NULL REFERENCES nir_fifo_layers(id) ON DELETE RESTRICT,
 source_type VARCHAR(40) NOT NULL, source_id BIGINT NOT NULL, quantity_units BIGINT NOT NULL, value_bani BIGINT NOT NULL, returned_units BIGINT NOT NULL DEFAULT 0, returned_value_bani BIGINT NOT NULL DEFAULT 0,
 created_at VARCHAR(32) NOT NULL
);
CREATE INDEX nir_fifo_consumption_source ON nir_fifo_consumptions(source_type,source_id);
CREATE TABLE nir_pending_consumptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, event_key VARCHAR(190) NOT NULL UNIQUE, warehouse_id INTEGER NOT NULL REFERENCES company_warehouses(id) ON DELETE RESTRICT,
 scope_type VARCHAR(10) NOT NULL, scope_id BIGINT NOT NULL, product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
 variant_id INTEGER NULL REFERENCES product_variants(id) ON DELETE RESTRICT, sku VARCHAR(190) NOT NULL,
 source_type VARCHAR(40) NOT NULL, source_id BIGINT NOT NULL, quantity_units BIGINT NOT NULL, created_at VARCHAR(32) NOT NULL
);
CREATE TABLE nir_consumption_requests (
 request_key VARCHAR(190) PRIMARY KEY, request_hash VARCHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL
);
CREATE TABLE nir_attachments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NOT NULL REFERENCES nir_documents(id) ON DELETE CASCADE,
 stored_name VARCHAR(190) NOT NULL UNIQUE, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(190) NOT NULL, extension VARCHAR(10) NOT NULL,
 size_bytes BIGINT NOT NULL, sha256 VARCHAR(64) NOT NULL, extraction_status VARCHAR(40) NOT NULL DEFAULT 'manual',
 created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, created_at VARCHAR(32) NOT NULL, UNIQUE(nir_id,sha256)
);
CREATE INDEX nir_attachments_hash ON nir_attachments(sha256);
CREATE TABLE nir_audit (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NULL, action VARCHAR(80) NOT NULL,
 actor_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, created_at VARCHAR(32) NOT NULL,
 before_json TEXT NOT NULL, after_json TEXT NOT NULL, context_json TEXT NOT NULL
);
CREATE INDEX nir_audit_document ON nir_audit(nir_id,id);
CREATE TABLE nir_idempotency (
 request_key VARCHAR(190) PRIMARY KEY, action VARCHAR(40) NOT NULL, nir_id INTEGER NULL REFERENCES nir_documents(id) ON DELETE CASCADE,
 request_hash VARCHAR(64) NOT NULL, result_id INTEGER NOT NULL, created_at VARCHAR(32) NOT NULL
);
CREATE TABLE nir_outbox (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nir_id INTEGER NOT NULL REFERENCES nir_documents(id) ON DELETE RESTRICT,
 event_type VARCHAR(80) NOT NULL, payload_json TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, processed_at VARCHAR(32) NULL
);
CREATE TABLE nir_return_reminders (
 return_id INTEGER NOT NULL REFERENCES order_returns(id) ON DELETE CASCADE, actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 dismissed_on VARCHAR(10) NOT NULL, PRIMARY KEY(return_id,actor_id)
);
