<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

final class StockPagination
{
    public const SIZES = [12, 24, 48, 96];

    public static function size(mixed $value): int
    {
        $size = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        return in_array($size, self::SIZES, true) ? $size : 24;
    }

    /** @return list<int|null> Null represents a gap between page links. */
    public static function numbers(int $page, int $pages): array
    {
        $pages = max(1, $pages);
        $page = max(1, min($page, $pages));
        if ($pages <= 7) {
            return range(1, $pages);
        }
        if ($page <= 4) {
            return [1, 2, 3, 4, 5, null, $pages];
        }
        if ($page >= $pages - 3) {
            return [1, null, $pages - 4, $pages - 3, $pages - 2, $pages - 1, $pages];
        }
        return [1, null, $page - 1, $page, $page + 1, null, $pages];
    }
}
