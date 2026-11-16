<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use PDO;

/** Applies confirmed NIR quantities to numeric online stock exactly once. */
final class NirOnlineStockService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $document @param array<string,mixed> $line @param array<string,mixed> $actor */
    public function setLineEffect(array $document,array $line,int $quantityUnits,array $actor,string $reason): void
    {
        $variantId=(int)($line['variant_id'] ?? 0); $lineId=(int)($line['id'] ?? 0); $nirId=(int)($document['id'] ?? 0);
        if($variantId<=0 || $lineId<=0 || $nirId<=0) { return; }
        $variant=$this->pdo->prepare('SELECT id,stock_unlimited FROM product_variants WHERE id=:id');
        $variant->execute(['id'=>$variantId]); $variant=$variant->fetch();
        if(!is_array($variant)) { throw new NirConflictException('Varianta NIR-ului nu mai există.'); }
        // The online inventory stores whole sellable pieces. Fractional accounting
        // units remain fully valid in NIR/FIFO, but cannot be mirrored numerically.
        $desired=(bool)$variant['stock_unlimited'] || $quantityUnits%10000!==0 ? 0 : intdiv($quantityUnits,10000);
        $existing=$this->pdo->prepare('SELECT * FROM nir_online_stock_effects WHERE line_id=:line');
        $existing->execute(['line'=>$lineId]); $effect=$existing->fetch(); $current=is_array($effect)?(int)$effect['quantity_delta']:0;
        $delta=$desired-$current; $now=gmdate('c');
        if(!is_array($effect)) {
            $insert=$this->pdo->prepare('INSERT INTO nir_online_stock_effects(nir_id,line_id,variant_id,quantity_delta,created_at,updated_at) VALUES(:nir,:line,:variant,:quantity,:now,:now)');
            $insert->execute(['nir'=>$nirId,'line'=>$lineId,'variant'=>$variantId,'quantity'=>$desired,'now'=>$now]);
            $effectId=(int)$this->pdo->lastInsertId();
        } else {
            if((int)$effect['nir_id']!==$nirId || (int)$effect['variant_id']!==$variantId) { throw new NirConflictException('Efectul online al poziției NIR este inconsistent.'); }
            $this->pdo->prepare('UPDATE nir_online_stock_effects SET quantity_delta=:quantity,updated_at=:now WHERE id=:id')->execute(['quantity'=>$desired,'now'=>$now,'id'=>$effect['id']]);
            $effectId=(int)$effect['id'];
        }
        if($delta===0) { return; }
        $this->pdo->prepare('INSERT INTO inventory(variant_id,physical_quantity,reserved_quantity,updated_at) SELECT :variant,0,0,:now WHERE NOT EXISTS(SELECT 1 FROM inventory WHERE variant_id=:same_variant)')->execute(['variant'=>$variantId,'same_variant'=>$variantId,'now'=>$now]);
        $update=$this->pdo->prepare('UPDATE inventory SET physical_quantity=physical_quantity+:delta,updated_at=:now WHERE variant_id=:variant');
        $update->execute(['delta'=>$delta,'now'=>$now,'variant'=>$variantId]);
        if($update->rowCount()!==1) { throw new NirConflictException('Stocul online al variantei nu a putut fi actualizat.'); }
        $type=$delta>0?'receive':'supplier_return';
        $this->pdo->prepare('INSERT INTO inventory_movements(variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,actor_user_id,created_at) VALUES(:variant,:type,:delta,0,:reason,\'nir_online_effect\',:reference,:actor,:now)')
            ->execute(['variant'=>$variantId,'type'=>$type,'delta'=>$delta,'reason'=>mb_substr($reason,0,500),'reference'=>$effectId,'actor'=>(int)($actor['id'] ?? 0) ?: null,'now'=>$now]);
    }
}
