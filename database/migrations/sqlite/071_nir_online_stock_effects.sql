CREATE TABLE nir_online_stock_effects (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 nir_id INTEGER NOT NULL REFERENCES nir_documents(id) ON DELETE CASCADE,
 line_id INTEGER NOT NULL UNIQUE REFERENCES nir_lines(id) ON DELETE CASCADE,
 variant_id INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
 quantity_delta INTEGER NOT NULL,
 created_at VARCHAR(32) NOT NULL,
 updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX idx_nir_online_stock_effects_variant ON nir_online_stock_effects(variant_id,nir_id);

INSERT INTO inventory(variant_id,physical_quantity,reserved_quantity,updated_at)
SELECT DISTINCT line.variant_id,0,0,COALESCE(document.confirmed_at,document.updated_at)
FROM nir_lines line
JOIN nir_documents document ON document.id=line.nir_id
JOIN product_variants variant ON variant.id=line.variant_id
WHERE document.status='confirmed'
  AND document.type IN ('supplier_receipt','supplier_return','opening_balance')
  AND line.variant_id IS NOT NULL
  AND variant.stock_unlimited=0
  AND line.stock_quantity_units%10000=0
  AND NOT EXISTS(SELECT 1 FROM inventory current_inventory WHERE current_inventory.variant_id=line.variant_id);

INSERT INTO nir_online_stock_effects(nir_id,line_id,variant_id,quantity_delta,created_at,updated_at)
SELECT document.id,line.id,line.variant_id,line.stock_quantity_units/10000,
       COALESCE(document.confirmed_at,document.updated_at),COALESCE(document.confirmed_at,document.updated_at)
FROM nir_lines line
JOIN nir_documents document ON document.id=line.nir_id
JOIN product_variants variant ON variant.id=line.variant_id
WHERE document.status='confirmed'
  AND document.type IN ('supplier_receipt','supplier_return','opening_balance')
  AND line.variant_id IS NOT NULL
  AND variant.stock_unlimited=0
  AND line.stock_quantity_units%10000=0;

UPDATE inventory
SET physical_quantity=physical_quantity+COALESCE((
      SELECT SUM(effect.quantity_delta) FROM nir_online_stock_effects effect WHERE effect.variant_id=inventory.variant_id
    ),0),
    updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')
WHERE EXISTS(SELECT 1 FROM nir_online_stock_effects effect WHERE effect.variant_id=inventory.variant_id);

INSERT INTO inventory_movements(variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,actor_user_id,created_at)
SELECT effect.variant_id,
       CASE WHEN effect.quantity_delta>=0 THEN 'receive' ELSE 'supplier_return' END,
       effect.quantity_delta,0,'Sincronizare stoc online din NIR','nir_online_effect',effect.id,NULL,effect.created_at
FROM nir_online_stock_effects effect
WHERE effect.quantity_delta<>0;
