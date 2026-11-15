<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** An agreed refund is not a payment. Amounts are authoritative integer bani. */
final class ReturnSettlement
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function calculate(int $base,array $input): array
    {
        $costs=[];
        if(($input['transport_enabled'] ?? '')==='1') { $costs[]=self::cost('Transport retur',$input['transport_cost'] ?? ''); }
        if(($input['other_costs_enabled'] ?? '')==='1') {
            $rows=$input['return_costs'] ?? [];
            if(!is_array($rows) || count($rows)>10) { throw new InvoiceException('Poți adăuga cel mult 10 costuri.'); }
            foreach($rows as $row) {
                if(!is_array($row)) { throw new InvoiceException('Costurile nu sunt valide.'); }
                if(trim((string)($row['name'] ?? ''))==='' && trim((string)($row['amount'] ?? ''))==='') { continue; }
                $costs[]=self::cost(trim((string)($row['name'] ?? '')),$row['amount'] ?? '');
            }
            if(count($costs)===(($input['transport_enabled'] ?? '')==='1'?1:0)) { throw new InvoiceException('Adaugă cel puțin un cost sau dezactivează opțiunea.'); }
        }
        $costTotal=array_sum(array_column($costs,'amount_bani'));
        if($costTotal>$base) { throw new InvoiceException('Costurile nu pot depăși valoarea produselor selectate.'); }
        $automatic=$base-$costTotal;
        $custom=($input['custom_refund_enabled'] ?? '')==='1';
        $refund=$custom?InvoiceCalculator::bani((string)($input['refund_total'] ?? '')):$automatic;
        if($refund>$base) { throw new InvoiceException('Suma de restituit nu poate depăși valoarea selectată pentru retur.'); }
        return ['base_bani'=>$base,'costs'=>$costs,'costs_bani'=>$costTotal,'refund_bani'=>$refund,'adjustment_bani'=>$refund-$automatic,'custom_total'=>$custom];
    }
    /** @return array{name:string,amount_bani:int} */
    private static function cost(string $name,mixed $amount): array
    {
        if(mb_strlen($name)<2 || mb_strlen($name)>150 || !is_string($amount)) { throw new InvoiceException('Completează denumirea și suma fiecărui cost.'); }
        $bani=InvoiceCalculator::bani($amount);
        if($bani<=0) { throw new InvoiceException('Un cost activ trebuie să fie mai mare decât zero.'); }
        return ['name'=>$name,'amount_bani'=>$bani];
    }
    /** Values for every selectable quantity, using original discounts and cumulative rounding.
     * @param array<string,mixed> $order @return array<int,array<int,int>> */
    public static function quotes(array $order): array
    {
        $quotes=[];$cumulative=0;$allocated=0;
        foreach($order['items'] as $item) {
            $cumulative+=(int)$item['line_total_bani'];
            $next=InvoiceCalculator::ratio((int)$order['discount_bani'],$cumulative,max(1,(int)$order['subtotal_bani']));
            $gross=(int)$item['line_total_bani']-($next-$allocated);$allocated=$next;
            $quantity=(int)$item['quantity'];$available=(int)$item['returnable_quantity'];$already=$quantity-$available;
            $quotes[(int)$item['id']]=[0=>0];
            for($q=1;$q<=$available;$q++) { $quotes[(int)$item['id']][$q]=InvoiceCalculator::ratio($gross,$already+$q,$quantity)-InvoiceCalculator::ratio($gross,$already,$quantity); }
        }
        return $quotes;
    }
    /** @param array<string,mixed> $settlement */
    public static function retentionName(array $settlement): string
    {
        $name=implode(', ',array_column($settlement['costs'] ?? [],'name'));
        if(($settlement['adjustment_bani'] ?? 0)!==0) { $name.=($name!==''?' · ':'').'Ajustare sumă de restituit'; }
        return mb_substr($name?:'Ajustare sumă de restituit',0,200);
    }
}
