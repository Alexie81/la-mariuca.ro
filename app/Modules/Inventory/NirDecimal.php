<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exact decimal arithmetic: money is persisted in bani and quantities in 1/10,000 units. */
final class NirDecimal
{
    public static function number(mixed $value, int $scale, string $field, bool $negative = false): BigDecimal
    {
        if (!is_scalar($value) || is_bool($value)) { throw new NirValidationException([$field=>'Introdu un număr valid.']); }
        $text = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d{1,10}(?:\.\d{1,' . $scale . '})?$/D', $text)) { throw new NirValidationException([$field=>'Valoarea este invalidă sau are prea multe zecimale (maximum ' . $scale . ').']); }
        $number = BigDecimal::of($text);
        if (!$negative && $number->isNegative()) { throw new NirValidationException([$field=>'Valoarea nu poate fi negativă.']); }
        return $number->toScale($scale);
    }

    public static function bani(BigDecimal $number): int
    {
        try { return $number->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt(); }
        catch (\Brick\Math\Exception\MathException) { throw new NirValidationException(['lines'=>'Valoarea documentului depășește limita numerică acceptată.']); }
    }
    public static function money(int $bani): BigDecimal { return BigDecimal::of($bani)->dividedBy(100, 2); }
    public static function units(BigDecimal $quantity): int { return $quantity->multipliedBy(10000)->toScale(0, RoundingMode::Unnecessary)->toInt(); }
    public static function quantity(int $units): string { return (string) BigDecimal::of($units)->dividedBy(10000, 4); }

    /** @param array<array-key,int|string> $values */
    public static function sum(array $values): int
    {
        $sum=BigDecimal::zero(); foreach($values as $value) { $sum=$sum->plus($value); }
        try { return $sum->toInt(); } catch(\Brick\Math\Exception\MathException) { throw new NirValidationException(['lines'=>'Totalul documentului depășește limita numerică acceptată.']); }
    }

    /** @param array<string,mixed> $line @return array<string,mixed> */
    public static function line(array $line, string $rate, bool $vatInCost = false, string $prefix = 'lines'): array
    {
        $values = [];
        foreach (['invoice_quantity','received_quantity','accepted_quantity','rejected_quantity','conversion_factor'] as $field) {
            $values[$field] = self::number($line[$field] ?? ($field === 'rejected_quantity' ? '0' : '1'), 4, $prefix . '.' . $field);
        }
        foreach (['unit_price','discount_percent','vat_percent','extra_cost_ron'] as $field) {
            $values[$field] = self::number($line[$field] ?? ($field === 'vat_percent' ? '21' : '0'), 6, $prefix . '.' . $field);
        }
        foreach (['discount_percent','vat_percent'] as $field) {
            if ($values[$field]->isGreaterThan(100)) { throw new NirValidationException([$prefix . '.' . $field=>'Procentul trebuie să fie între 0 și 100.']); }
        }
        if ($values['conversion_factor']->isZero()) { throw new NirValidationException([$prefix . '.conversion_factor'=>'Factorul de conversie trebuie să fie pozitiv.']); }
        $quantity = $values['accepted_quantity'];
        $stockQuantity = $quantity->multipliedBy($values['conversion_factor']);
        try { $stockUnits = self::units($stockQuantity); } catch (\Throwable) { throw new NirValidationException([$prefix . '.conversion_factor'=>'Cantitatea rezultată trebuie să aibă cel mult 4 zecimale.']); }
        $gross = $quantity->multipliedBy($values['unit_price']);
        $discount = $gross->multipliedBy($values['discount_percent'])->dividedByExact(100);
        $net = $gross->minus($discount)->toScale(2, RoundingMode::HalfUp);
        $mode = (string) ($line['price_mode'] ?? 'unit');
        if (!in_array($mode, ['unit','line_net','exact_vat','gross'], true)) { throw new NirValidationException([$prefix . '.price_mode'=>'Modul de calcul nu este valid.']); }
        if (in_array($mode, ['line_net','exact_vat'], true)) { $net = self::number($line['net_override'] ?? '0', 2, $prefix . '.net_override'); $discount = BigDecimal::zero(); }
        if ($mode === 'gross') {
            $total = self::number($line['gross_override'] ?? '0', 2, $prefix . '.gross_override');
            $net = $total->dividedBy(BigDecimal::of(1)->plus($values['vat_percent']->dividedBy(100, 8)), 2, RoundingMode::HalfUp);
            $tax = $total->minus($net);
            $discount = BigDecimal::zero();
        } else {
            $tax = $mode === 'exact_vat' ? self::number($line['tax_override'] ?? '0', 2, $prefix . '.tax_override') : $net->multipliedBy($values['vat_percent'])->dividedBy(100, 2, RoundingMode::HalfUp);
            $total = $net->plus($tax);
        }
        if ($mode !== 'unit' && !$quantity->isZero()) { $values['unit_price'] = $net->dividedBy($quantity, 6, RoundingMode::HalfUp); }
        $netRon = self::bani($net->multipliedBy($rate));
        $totalRon = self::bani($total->multipliedBy($rate));
        $inventory = self::sum([$vatInCost ? $totalRon : $netRon, self::bani($values['extra_cost_ron'])]);
        if (isset($line['tracked']) && !(bool)$line['tracked']) { $inventory = 0; }
        foreach ($values as $key=>$value) { $line[$key] = (string) $value; }
        return array_replace($line, ['price_mode'=>$mode, 'stock_quantity'=>self::quantity($stockUnits), 'stock_quantity_units'=>$stockUnits, 'net_bani'=>self::bani($net), 'tax_bani'=>self::bani($tax), 'total_bani'=>self::bani($total), 'discount_bani'=>self::bani($discount), 'net_ron_bani'=>$netRon, 'tax_ron_bani'=>$totalRon-$netRon, 'total_ron_bani'=>$totalRon, 'inventory_bani'=>$inventory, 'unit_cost_ron'=>$stockUnits === 0 ? '0.000000' : (string) self::money($inventory)->dividedBy(BigDecimal::of(self::quantity($stockUnits)), 6, RoundingMode::HalfUp)]);
    }
}
