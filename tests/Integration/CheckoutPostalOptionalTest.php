<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\DatabaseTestCase;

final class CheckoutPostalOptionalTest extends DatabaseTestCase
{
    /** @return iterable<string,array{0:?string}> */
    public static function absentPostalCodes(): iterable
    {
        yield 'omitted' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('absentPostalCodes')]
    public function testOrderAcceptsManualLocationWithoutPostalCode(?string $postalCode): void
    {
        $this->seed();
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id JOIN products p ON p.id=v.product_id WHERE p.status='published' AND i.physical_quantity>0 LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $data = $this->checkoutData();
        if ($postalCode !== null) {
            $data['postal_code'] = $postalCode;
        }
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place($data);
        $stored = $this->pdo->query('SELECT shipping_address_json,payment_method FROM orders WHERE id=' . (int) $order['id'])->fetch();
        $address = json_decode((string) $stored['shipping_address_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $address['postal_code']);
        self::assertSame('Cluj', $address['county']);
        self::assertSame('Cluj-Napoca', $address['city']);
        self::assertSame('Strada Florilor 10', $address['address']);
        self::assertSame('cod', $stored['payment_method']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testNonemptyMalformedPostalCodesAreRejectedBeforeCreatingAnOrder(): void
    {
        $this->seed();
        $checkout = new CheckoutService($this->pdo, new CartService($this->pdo), Config::load($this->root));
        foreach (['123', 'abcdef', '1234567', '12 456'] as $postalCode) {
            try {
                $checkout->place($this->checkoutData() + ['postal_code' => $postalCode]);
                self::fail('Un cod poștal completat incorect trebuie respins.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('6 cifre', $exception->getMessage());
            }
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_reservations')->fetchColumn());
    }

    /** @return array<string,string> */
    private function checkoutData(): array
    {
        $shippingMethodId = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        return [
            'email' => 'postal-optional@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop',
            'phone' => '0700000000', 'address' => 'Strada Florilor 10', 'county' => 'Cluj',
            'city' => 'Cluj-Napoca', 'shipping_method_id' => (string) $shippingMethodId,
            'payment_method' => 'cod', 'idempotency_key' => 'postal-optional-order', 'terms' => '1',
        ];
    }
}
