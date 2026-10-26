<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;
use RuntimeException;

final class AdminOrderDemoSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(int $count = 25): int
    {
        $count = max(1, min(100, $count));
        $products = $this->pdo->query(
            "SELECT p.id product_id,p.name,v.id variant_id,v.sku,v.option_summary,v.price_bani "
            . "FROM products p JOIN product_variants v ON v.product_id=p.id AND v.status='active' "
            . "WHERE p.status='published' ORDER BY p.id,v.is_default DESC,v.id LIMIT 30"
        )->fetchAll();
        if ($products === []) {
            throw new RuntimeException('Catalogul nu conține produse publicate pentru comenzile demo.');
        }

        $customers = [
            ['Ana', 'Popescu'], ['Maria', 'Ionescu'], ['Ioana', 'Dumitrescu'], ['Elena', 'Marin'],
            ['Andreea', 'Stoica'], ['Gabriela', 'Radu'], ['Cristina', 'Matei'], ['Mihaela', 'Stan'],
            ['Diana', 'Georgescu'], ['Alina', 'Tudor'], ['Nicoleta', 'Munteanu'], ['Larisa', 'Dinu'],
        ];
        $statuses = ['new', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled', 'returned'];
        $cities = [
            ['București', 'București', '010101'], ['Cluj-Napoca', 'Cluj', '400001'],
            ['Iași', 'Iași', '700001'], ['Brașov', 'Brașov', '500001'], ['Timișoara', 'Timiș', '300001'],
            ['Constanța', 'Constanța', '900001'], ['Craiova', 'Dolj', '200001'], ['Sibiu', 'Sibiu', '550001'],
        ];

        $cartInsert = $this->pdo->prepare(
            "INSERT INTO carts (token,user_id,status,coupon_code,created_at,updated_at,expires_at) VALUES (:token,NULL,'converted',NULL,:created,:created,:expires)"
        );
        $orderInsert = $this->pdo->prepare(
            'INSERT INTO orders (order_number,public_token_hash,idempotency_key,cart_id,user_id,email,first_name,last_name,phone,customer_type,company_json,shipping_address_json,billing_address_json,subtotal_bani,discount_bani,shipping_bani,tax_bani,total_bani,currency,status,payment_status,payment_method,shipping_method,awb,internal_notes,terms_accepted_at,marketing_consent,created_at,updated_at,shipped_at,cancelled_at) '
            . "VALUES (:number,:token_hash,:idempotency,:cart,NULL,:email,:first_name,:last_name,:phone,'person','{}',:shipping,:billing,:subtotal,:discount,:shipping_cost,0,:total,'RON',:status,:payment_status,:payment_method,'curier',:awb,'Comandă demonstrativă pentru verificarea panoului administrativ.',:terms,0,:created,:created,:shipped_at,:cancelled_at)"
        );
        $itemInsert = $this->pdo->prepare(
            'INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_summary,sku,quantity,unit_price_bani,personalization_bani,line_total_bani,tax_bani,personalization_json) '
            . "VALUES (:order,:product,:variant,:name,:summary,:sku,:quantity,:unit_price,0,:line_total,0,'{}')"
        );
        $paymentInsert = $this->pdo->prepare(
            'INSERT INTO payments (order_id,provider,provider_payment_id,amount_bani,refunded_bani,currency,status,created_at,updated_at) '
            . "VALUES (:order,'stripe',:provider_id,:amount,:refunded,'RON',:status,:created,:created)"
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->pdo->beginTransaction();
        try {
            for ($index = 0; $index < $count; $index++) {
                [$firstName, $lastName] = $customers[$index % count($customers)];
                [$city, $county, $postalCode] = $cities[$index % count($cities)];
                $product = $products[$index % count($products)];
                $status = $statuses[$index % count($statuses)];
                $paymentMethod = $index % 3 === 0 ? 'stripe' : 'cod';
                $paymentStatus = match (true) {
                    $status === 'returned' => 'refunded',
                    $status === 'cancelled' => 'failed',
                    in_array($status, ['shipped', 'delivered'], true) || $paymentMethod === 'stripe' => 'paid',
                    default => 'pending',
                };
                $quantity = ($index % 3) + 1;
                $subtotal = (int) $product['price_bani'] * $quantity;
                $discount = $index % 5 === 0 ? min(2500, (int) round($subtotal * 0.1)) : 0;
                $shippingCost = $subtotal - $discount >= 35000 ? 0 : 1900;
                $total = $subtotal - $discount + $shippingCost;
                $createdAt = $now->modify('-' . ($count - $index) . ' hours')->format('Y-m-d H:i:s');
                $expiresAt = $now->modify('+30 days')->format('Y-m-d H:i:s');
                $address = json_encode([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'address' => 'Strada Atelierului nr. ' . ($index + 1),
                    'city' => $city,
                    'county' => $county,
                    'postal_code' => $postalCode,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                $cartInsert->execute([
                    'token' => hash('sha256', 'admin-demo-cart-' . $createdAt . '-' . $index . '-' . random_bytes(6)),
                    'created' => $createdAt,
                    'expires' => $expiresAt,
                ]);
                $cartId = (int) $this->pdo->lastInsertId();
                $orderNumber = 'LM-DEMO-' . str_pad((string) $cartId, 6, '0', STR_PAD_LEFT);
                $awb = in_array($status, ['shipped', 'delivered', 'returned'], true) ? 'DEMOAWB' . str_pad((string) $cartId, 8, '0', STR_PAD_LEFT) : null;
                $orderInsert->execute([
                    'number' => $orderNumber,
                    'token_hash' => hash('sha256', 'admin-demo-public-' . $cartId),
                    'idempotency' => 'admin-demo-order-' . $cartId,
                    'cart' => $cartId,
                    'email' => strtolower($firstName . '.' . $lastName . '+' . $cartId . '@example.test'),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => '07' . str_pad((string) (30000000 + $cartId), 8, '0', STR_PAD_LEFT),
                    'shipping' => $address,
                    'billing' => $address,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'shipping_cost' => $shippingCost,
                    'total' => $total,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'payment_method' => $paymentMethod,
                    'awb' => $awb,
                    'terms' => $createdAt,
                    'created' => $createdAt,
                    'shipped_at' => $awb !== null ? $createdAt : null,
                    'cancelled_at' => $status === 'cancelled' ? $createdAt : null,
                ]);
                $orderId = (int) $this->pdo->lastInsertId();

                $itemInsert->execute([
                    'order' => $orderId,
                    'product' => (int) $product['product_id'],
                    'variant' => (int) $product['variant_id'],
                    'name' => (string) $product['name'],
                    'summary' => (string) $product['option_summary'],
                    'sku' => (string) $product['sku'],
                    'quantity' => $quantity,
                    'unit_price' => (int) $product['price_bani'],
                    'line_total' => $subtotal,
                ]);

                if ($paymentMethod === 'stripe') {
                    $paymentInsert->execute([
                        'order' => $orderId,
                        'provider_id' => 'pi_demo_' . $cartId,
                        'amount' => $total,
                        'refunded' => $paymentStatus === 'refunded' ? $total : 0,
                        'status' => $paymentStatus === 'paid' ? 'succeeded' : ($paymentStatus === 'refunded' ? 'refunded' : 'failed'),
                        'created' => $createdAt,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $count;
    }
}
