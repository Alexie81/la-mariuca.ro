<?php

declare(strict_types=1);

namespace App\Shared\Support;

final class Money
{
    public static function format(int $bani): string
    {
        $negative = $bani < 0;
        $bani = abs($bani);
        $lei = intdiv($bani, 100);
        $fraction = $bani % 100;
        return ($negative ? '-' : '') . number_format($lei, 0, ',', '.') . ',' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) . ' Lei';
    }

    public static function parse(string $value): int
    {
        $normalized = str_replace([' ', '.'], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new \InvalidArgumentException('Preț invalid. Folosește de exemplu 129,90.');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
