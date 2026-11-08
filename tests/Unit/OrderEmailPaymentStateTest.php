<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderEmailViewData;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderEmailPaymentStateTest extends TestCase
{
    #[DataProvider('states')]
    public function testSharedEmailDataUsesRecordedPaymentState(string $method, string $status, string $orderStatus, string $label, bool $paid): void
    {
        $root = sys_get_temp_dir() . '/no-order-email-env-' . bin2hex(random_bytes(8));
        $config = Config::load($root);
        $order = [
            'payment_method' => $method, 'payment_status' => $status, 'status' => $orderStatus,
            'items' => [], 'quantity' => 0, 'shipping_discount_bani' => 0, 'shipping_bani' => 1900,
            'subtotal_bani' => 10000, 'discount_bani' => 0, 'tax_bani' => 0, 'total_bani' => 11900,
            'first_name' => 'Ana', 'last_name' => 'Pop', 'order_number' => 'LM-EMAIL-STATE',
            'created_at' => '2026-11-08T10:00:00+00:00', 'shipping_address' => [],
            'phone' => '0700000000', 'email' => 'email-state@example.test', 'shipping_method_name' => 'Curier',
        ];
        $data = (new OrderEmailViewData($config, new OrderEmailImages($config, $root)))->forOrder($order);
        self::assertSame($label, $data['payment_state']);
        self::assertSame($paid, $data['paid']);
    }

    public static function states(): array
    {
        return [
            'card pending' => ['stripe', 'pending', 'new', 'Plată în așteptare', false],
            'card failed' => ['stripe', 'failed', 'cancelled', 'Plată eșuată', false],
            'card paid' => ['stripe', 'paid', 'confirmed', 'Plătit', true],
            'card refunded' => ['stripe', 'refunded', 'cancelled', 'Rambursat', false],
            'card partial refund' => ['stripe', 'partially_refunded', 'returned', 'Rambursat parțial', false],
            'cash pending' => ['cod', 'pending', 'new', 'De achitat la livrare', false],
            'cash cancelled' => ['cod', 'pending', 'cancelled', 'Fără plată la livrare', false],
            'cash paid' => ['cod', 'paid', 'delivered', 'Plătit', true],
        ];
    }
}
