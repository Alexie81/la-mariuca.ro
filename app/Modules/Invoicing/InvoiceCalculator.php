<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Bani for stored totals; decimal strings for unit prices and quantities. */
final class InvoiceCalculator
{
    public static function ratio(int $value,int $numerator,int $denominator): int
    {
        if($denominator<=0) { throw new InvoiceException('Cantitatea de calcul nu este validă.'); }
        return BigDecimal::of($value)->multipliedBy($numerator)->dividedBy($denominator,0,RoundingMode::HalfUp)->toInt();
    }
    public static function bani(string $value): int
    {
        $value=str_replace(',','.',trim($value));
        if(!preg_match('/^\d{1,9}(\.\d{1,2})?$/D',$value)) { throw new InvoiceException('Introdu o sumă pozitivă, cu cel mult două zecimale.'); }
        return BigDecimal::of($value)->multipliedBy(100)->toInt();
    }
    public static function units(string $value): int
    {
        $value=str_replace(',','.',trim($value));
        if(!preg_match('/^\d{1,7}(\.\d{1,4})?$/D',$value)) { throw new InvoiceException('Cantitatea nu este validă.'); }
        return BigDecimal::of($value)->multipliedBy(10000)->toInt();
    }
    public static function quantity(int $units): string { return rtrim(rtrim((string)BigDecimal::of($units)->dividedBy(10000,4),'0'),'.'); }
    public static function money(int $bani,string $currency='RON'): string
    {
        $absolute=abs($bani); return ($bani<0?'−':'').number_format(intdiv($absolute,100),0,',','.').','.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT).' '.($currency==='RON'?'Lei':$currency);
    }
    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public static function line(array $identity,int $units,int $grossBefore,int $discount,int $vat): array
    {
        if($units<=0 || $grossBefore<0 || $discount<0 || $discount>$grossBefore || $vat<0 || $vat>10000) { throw new InvoiceException('Verifică sumele, cantitatea și TVA-ul poziției.'); }
        $gross=$grossBefore-$discount; $net=self::ratio($gross,10000,10000+$vat);
        return array_replace($identity,['quantity_units'=>$units,'unit_gross'=>(string)BigDecimal::of($grossBefore)->multipliedBy(100)->dividedBy($units,8,RoundingMode::HalfUp),
            'unit_net'=>(string)BigDecimal::of($grossBefore)->multipliedBy(100)->multipliedBy(10000)->dividedBy(BigDecimal::of($units)->multipliedBy(10000+$vat),8,RoundingMode::HalfUp),
            'vat_basis_points'=>$vat,'discount_bani'=>$discount,'net_bani'=>$net,'tax_bani'=>$gross-$net,'gross_bani'=>$gross]);
    }
    /** @param list<array<string,mixed>> $lines @return array{net_bani:int,tax_bani:int,gross_bani:int,discount_bani:int} */
    public static function totals(array $lines): array
    {
        $sum=['net_bani'=>0,'tax_bani'=>0,'gross_bani'=>0,'discount_bani'=>0];
        foreach($lines as $line) { foreach($sum as $field=>$_) { $sum[$field]+=(int)$line[$field]; } }
        return $sum;
    }
    /** Cumulative proration makes any sequence of partial returns sum to the original cents.
     * @param array<string,mixed> $original @return array<string,mixed>
     */
    public static function credit(array $original,int $units,int $already): array
    {
        $available=(int)$original['quantity_units']-$already;
        if($units<=0 || $units>$available) { throw new InvoiceException('Cantitatea depășește ce mai poate fi returnat din această factură.'); }
        $line=$original; unset($line['id'],$line['invoice_id']); $line['parent_line_id']=$original['id']; $line['quantity_units']=-$units;
        foreach(['net_bani','gross_bani','discount_bani'] as $field) {
            $line[$field]=-(self::ratio((int)$original[$field],$already+$units,(int)$original['quantity_units'])-self::ratio((int)$original[$field],$already,(int)$original['quantity_units']));
        }
        $line['tax_bani']=$line['gross_bani']-$line['net_bani']; return $line;
    }
}
