<?php

declare(strict_types=1);

namespace App\Modules\Shipping;

final class StoredDeliveryEstimate implements DeliveryEstimateProvider
{
    /** @param array<string,mixed> $method @return array<string,mixed> */
    public static function snapshot(array $method): array
    {
        return ['version' => 1, 'source' => 'shipping_method', 'min_days' => (int) $method['estimated_min_days'],
            'max_days' => (int) $method['estimated_max_days'], 'type' => (string) $method['type'],
            'basis' => 'working_days_after_handover'];
    }

    public function forOrder(array $order): ?array
    {
        $estimate = $order['shipping_estimate'] ?? null;
        if (!is_array($estimate) || !isset($estimate['min_days'], $estimate['max_days'])) { return null; }
        $min = (int) $estimate['min_days'];
        $max = (int) $estimate['max_days'];
        if ($min < 0 || $max < $min || $max > 365) { return null; }
        $label = $min === $max ? ($min === 0 ? 'În aceeași zi lucrătoare' : $min . ($min === 1 ? ' zi lucrătoare' : ' zile lucrătoare'))
            : $min . '–' . $max . ' zile lucrătoare';
        return ['label' => $label, 'detail' => ($estimate['type'] ?? '') === 'pickup'
            ? 'Estimare de la confirmarea pregătirii pentru ridicare.'
            : 'Estimare de la predarea coletului către curier.'];
    }
}
