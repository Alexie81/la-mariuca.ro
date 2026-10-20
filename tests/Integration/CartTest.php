<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Cart\CartService;
use Tests\DatabaseTestCase;

final class CartTest extends DatabaseTestCase
{
    public function testProductCardToggleAddsThenRemovesVariant(): void
    {
        $this->seed();
        $variantId = (int) $this->pdo->query(
            'SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id = v.id WHERE i.physical_quantity > 0 LIMIT 1'
        )->fetchColumn();
        $cart = new CartService($this->pdo);

        self::assertSame('added', $cart->toggle($variantId));
        self::assertSame(1, $cart->summary()['count']);

        self::assertSame('removed', $cart->toggle($variantId));
        self::assertSame(0, $cart->summary()['count']);
    }
}
