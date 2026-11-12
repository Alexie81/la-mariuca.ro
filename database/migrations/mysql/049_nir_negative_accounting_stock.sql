INSERT INTO nir_stock_balances(warehouse_id,scope_type,scope_id,product_id,variant_id,sku,quantity_units,unit_cost_ron,updated_at)
SELECT p.warehouse_id,p.scope_type,p.scope_id,MIN(p.product_id),MIN(p.variant_id),MIN(p.sku),0,'0.000000',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ')
FROM nir_pending_consumptions p
WHERE NOT EXISTS (SELECT 1 FROM nir_stock_balances b WHERE b.warehouse_id=p.warehouse_id AND b.scope_type=p.scope_type AND b.scope_id=p.scope_id)
GROUP BY p.warehouse_id,p.scope_type,p.scope_id;
INSERT INTO nir_stock_movements(nir_id,line_id,warehouse_id,scope_type,scope_id,type,quantity_units,before_units,after_units,event_key,source_type,source_id,actor_id,created_at)
SELECT NULL,NULL,b.warehouse_id,b.scope_type,b.scope_id,'PENDING_STOCK_ADJUSTMENT',-p.units,b.quantity_units,b.quantity_units-p.units,CONCAT('migration049:',b.id),'reconciliation',b.id,NULL,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%sZ')
FROM nir_stock_balances b JOIN (SELECT warehouse_id,scope_type,scope_id,SUM(quantity_units) units FROM nir_pending_consumptions GROUP BY warehouse_id,scope_type,scope_id) p
ON p.warehouse_id=b.warehouse_id AND p.scope_type=b.scope_type AND p.scope_id=b.scope_id;
UPDATE nir_stock_balances b JOIN (SELECT warehouse_id,scope_type,scope_id,SUM(quantity_units) units FROM nir_pending_consumptions GROUP BY warehouse_id,scope_type,scope_id) p
ON p.warehouse_id=b.warehouse_id AND p.scope_type=b.scope_type AND p.scope_id=b.scope_id
SET b.quantity_units=b.quantity_units-p.units;
