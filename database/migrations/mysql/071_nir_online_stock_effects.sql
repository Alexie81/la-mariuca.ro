CREATE TABLE nir_online_stock_effects (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 nir_id BIGINT UNSIGNED NOT NULL,
 line_id BIGINT UNSIGNED NOT NULL,
 variant_id BIGINT UNSIGNED NOT NULL,
 quantity_delta BIGINT NOT NULL,
 created_at VARCHAR(32) NOT NULL,
 updated_at VARCHAR(32) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_nir_online_stock_effects_line(line_id),
 KEY idx_nir_online_stock_effects_variant(variant_id,nir_id),
 CONSTRAINT fk_nir_online_effect_document FOREIGN KEY(nir_id) REFERENCES nir_documents(id) ON DELETE CASCADE,
 CONSTRAINT fk_nir_online_effect_line FOREIGN KEY(line_id) REFERENCES nir_lines(id) ON DELETE CASCADE,
 CONSTRAINT fk_nir_online_effect_variant FOREIGN KEY(variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO inventory(variant_id,physical_quantity,reserved_quantity,updated_at)
SELECT DISTINCT line.variant_id,0,0,COALESCE(document.confirmed_at,document.updated_at)
FROM nir_lines line
JOIN nir_documents document ON document.id=line.nir_id
JOIN product_variants variant ON variant.id=line.variant_id
WHERE document.status='confirmed'
  AND document.type IN ('supplier_receipt','supplier_return','opening_balance')
  AND line.variant_id IS NOT NULL
  AND variant.stock_unlimited=0
  AND MOD(line.stock_quantity_units,10000)=0;

INSERT INTO nir_online_stock_effects(nir_id,line_id,variant_id,quantity_delta,created_at,updated_at)
SELECT document.id,line.id,line.variant_id,line.stock_quantity_units DIV 10000,
       COALESCE(document.confirmed_at,document.updated_at),COALESCE(document.confirmed_at,document.updated_at)
FROM nir_lines line
JOIN nir_documents document ON document.id=line.nir_id
JOIN product_variants variant ON variant.id=line.variant_id
WHERE document.status='confirmed'
  AND document.type IN ('supplier_receipt','supplier_return','opening_balance')
  AND line.variant_id IS NOT NULL
  AND variant.stock_unlimited=0
  AND MOD(line.stock_quantity_units,10000)=0;

UPDATE inventory current_inventory
JOIN (SELECT variant_id,SUM(quantity_delta) AS quantity_delta FROM nir_online_stock_effects GROUP BY variant_id) effect
  ON effect.variant_id=current_inventory.variant_id
SET current_inventory.physical_quantity=current_inventory.physical_quantity+effect.quantity_delta,
    current_inventory.updated_at=UTC_TIMESTAMP();

INSERT INTO inventory_movements(variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,actor_user_id,created_at)
SELECT effect.variant_id,
       CASE WHEN effect.quantity_delta>=0 THEN 'receive' ELSE 'supplier_return' END,
       effect.quantity_delta,0,'Sincronizare stoc online din NIR','nir_online_effect',effect.id,NULL,effect.created_at
FROM nir_online_stock_effects effect
WHERE effect.quantity_delta<>0;
