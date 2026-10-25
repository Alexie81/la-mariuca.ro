<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Catalog\ProductRecommendationService;
use App\Modules\Reviews\ProductReviewRepository;
use App\Modules\Reviews\ProductReviewService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class ProductReviewTest extends DatabaseTestCase
{
    public function testEmptyReviewStateDoesNotRenderRatingOverview(): void
    {
        $this->seed();

        $body = (string) $this->get('/produs/costumas-micul-gentleman/')->getBody();

        self::assertStringNotContainsString('product-review-overview', $body);
        self::assertStringNotContainsString('0 recenzii afișate', $body);
        self::assertStringContainsString('Primele impresii se scriu aici', $body);
        self::assertStringContainsString('Scrie prima recenzie', $body);
        self::assertStringContainsString('product-reviews-empty-action', $body);
    }

    public function testApprovedReviewAndReviewFormAreRenderedOnProductPage(): void
    {
        $this->seed();
        $productId = (int) $this->pdo->query("SELECT id FROM products WHERE slug = 'costumas-micul-gentleman'")->fetchColumn();
        $now = gmdate('c');
        (new ProductReviewRepository($this->pdo))->create([
            'product_id' => $productId,
            'customer_id' => null,
            'author_name' => 'Ana M.',
            'author_email' => 'ana@example.test',
            'rating' => 5,
            'title' => 'Foarte atent lucrat',
            'body' => 'Materialul este plăcut, iar detaliile arată foarte frumos.',
            'status' => 'approved',
            'is_verified_purchase' => false,
            'is_demo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $body = (string) $this->get('/produs/costumas-micul-gentleman/')->getBody();

        self::assertStringContainsString('Ana M.', $body);
        self::assertStringContainsString('Foarte atent lucrat', $body);
        self::assertStringNotContainsString('DEMO · exemplu de afișare', $body);
        self::assertStringContainsString('action="/produs/costumas-micul-gentleman/recenzie/"', $body);
        self::assertStringContainsString('name="review_consent"', $body);
        self::assertStringContainsString('name="order_number"', $body);
        self::assertStringContainsString('data-review-form', $body);
        self::assertStringContainsString('product-review-consent-switch', $body);
        self::assertStringContainsString('data-review-carousel', $body);
        self::assertStringContainsString('data-review-open', $body);
        self::assertStringContainsString('data-review-dialog', $body);
        self::assertStringContainsString('data-review-expand', $body);
        self::assertStringContainsString('data-review-detail-dialog', $body);
        self::assertStringContainsString('product-review-carousel-heart', $body);
        self::assertStringContainsString('product-review-stars--average', $body);
        self::assertStringContainsString('product-review-star-fill', $body);
        self::assertStringContainsString('data-review-score-value=', $body);
        self::assertStringContainsString('aria-label="Recenzia precedentă"', $body);
    }

    public function testValidReviewSubmissionIsStoredPendingForModeration(): void
    {
        $this->seed();
        $productId = (int) $this->pdo->query("SELECT id FROM products WHERE slug = 'costumas-micul-gentleman'")->fetchColumn();
        $this->createOrderContainingProduct('LM-2026-000777', 'maria@example.test', $productId, 'delivered');
        $_SESSION['_csrf'] = 'review-test-token';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/produs/costumas-micul-gentleman/recenzie/', ['REMOTE_ADDR' => '127.0.0.55'])
            ->withParsedBody([
                '_csrf' => 'review-test-token',
                'rating' => '5',
                'author_name' => 'Maria Pop',
                'author_email' => 'maria@example.test',
                'order_number' => 'LM-2026-000777',
                'body' => 'Materialul este moale, iar produsul arată foarte atent lucrat.',
                'review_consent' => '1',
                'website' => '',
            ]);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/produs/costumas-micul-gentleman/#formular-recenzie', $response->getHeaderLine('Location'));
        $review = $this->pdo->query("SELECT * FROM product_reviews WHERE author_email = 'maria@example.test'")->fetch();
        self::assertIsArray($review);
        self::assertSame('pending', $review['status']);
        self::assertSame(5, (int) $review['rating']);
        self::assertSame(0, (int) $review['is_demo']);
        self::assertSame('LM-2026-000777', $review['order_number']);
        self::assertSame(1, (int) $review['is_verified_purchase']);
    }

    public function testUnknownOrderNumberReturnsFieldErrorAndDoesNotStoreReview(): void
    {
        $this->seed();
        $_SESSION['_csrf'] = 'review-order-test-token';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/produs/costumas-micul-gentleman/recenzie/', ['REMOTE_ADDR' => '127.0.0.56'])
            ->withHeader('Accept', 'application/json')
            ->withParsedBody([
                '_csrf' => 'review-order-test-token',
                'rating' => '5',
                'author_name' => 'Maria Pop',
                'author_email' => 'maria.necunoscuta@example.test',
                'order_number' => 'LM-2026-INEXISTENTA',
                'body' => 'Materialul este moale, iar produsul arată foarte atent lucrat.',
                'review_consent' => '1',
                'website' => '',
            ]);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(false, $payload['ok']);
        self::assertSame('order_number', $payload['field']);
        self::assertSame('Nu am putut găsi numărul comenzii.', $payload['message']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_reviews WHERE author_email = 'maria.necunoscuta@example.test'")->fetchColumn());
    }

    public function testModerationPublishesPendingReview(): void
    {
        $this->seed();
        $productId = (int) $this->pdo->query("SELECT id FROM products WHERE slug = 'costumas-micul-gentleman'")->fetchColumn();
        $adminId = (int) $this->pdo->query("SELECT id FROM users WHERE role = 'administrator' LIMIT 1")->fetchColumn();
        if ($adminId === 0) {
            $now = gmdate('c');
            $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('admin-review@example.test','x','Admin','Review','administrator','active',:now,:now,:now)");
            $statement->execute(['now' => $now]);
            $adminId = (int) $this->pdo->lastInsertId();
        }

        $repository = new ProductReviewRepository($this->pdo);
        $now = gmdate('c');
        $reviewId = $repository->create([
            'product_id' => $productId,
            'customer_id' => null,
            'author_name' => 'Elena',
            'author_email' => 'elena@example.test',
            'rating' => 4,
            'title' => '',
            'body' => 'O recenzie suficient de lungă pentru testarea moderării.',
            'status' => 'pending',
            'is_verified_purchase' => false,
            'is_demo' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        (new ProductReviewService($repository))->moderate($reviewId, 'approve', $adminId);

        self::assertSame('approved', $this->pdo->query("SELECT status FROM product_reviews WHERE id = {$reviewId}")->fetchColumn());
    }

    public function testDemoReviewPackAddsTenAdditionalReviewsIdempotently(): void
    {
        $this->seed();
        $repository = new ProductReviewRepository($this->pdo);

        $firstRun = $repository->ensureDemoPackForProductSlug('costumas-micul-gentleman');
        $secondRun = $repository->ensureDemoPackForProductSlug('costumas-micul-gentleman');

        self::assertCount(11, $firstRun);
        self::assertSame($firstRun, $secondRun);
        self::assertSame(11, (int) $this->pdo->query("SELECT COUNT(*) FROM product_reviews WHERE is_demo = 1")->fetchColumn());
        self::assertSame(9, (int) $this->pdo->query("SELECT COUNT(*) FROM product_reviews WHERE is_demo = 1 AND rating = 5")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM product_reviews WHERE is_demo = 1 AND rating = 4")->fetchColumn());
        self::assertGreaterThan(600, mb_strlen((string) $this->pdo->query("SELECT body FROM product_reviews WHERE author_email = 'recenzie-demo@localhost.invalid'")->fetchColumn()));
    }

    public function testSimilarProductsExcludeCurrentProductAndAreLimited(): void
    {
        $this->seed();
        $catalog = new CatalogRepository($this->pdo);
        $product = $catalog->productBySlug('costumas-micul-gentleman');
        self::assertIsArray($product);

        $similar = (new ProductRecommendationService($catalog))->similarTo($product, 15);

        self::assertNotEmpty($similar);
        self::assertLessThanOrEqual(15, count($similar));
        self::assertNotContains((int) $product['id'], array_map(static fn(array $item): int => (int) $item['id'], $similar));
        self::assertArrayHasKey('similarity_score', $similar[0]);
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }

    private function createOrderContainingProduct(string $orderNumber, string $email, int $productId, string $status = 'confirmed'): void
    {
        $now = gmdate('c');
        $token = 'review-order-' . bin2hex(random_bytes(6));
        $cart = $this->pdo->prepare('INSERT INTO carts (token, status, created_at, updated_at, expires_at) VALUES (:token, :status, :now, :now, :expires)');
        $cart->execute([
            'token' => $token,
            'status' => 'converted',
            'now' => $now,
            'expires' => gmdate('c', time() + 86400),
        ]);
        $cartId = (int) $this->pdo->lastInsertId();

        $order = $this->pdo->prepare('INSERT INTO orders
            (order_number, public_token_hash, idempotency_key, cart_id, user_id, email, first_name, last_name, phone,
             customer_type, company_json, shipping_address_json, billing_address_json, subtotal_bani, discount_bani,
             shipping_bani, tax_bani, total_bani, currency, status, payment_status, payment_method, shipping_method,
             internal_notes, terms_accepted_at, marketing_consent, created_at, updated_at)
            VALUES
            (:number, :public_token, :idempotency, :cart, NULL, :email, :first_name, :last_name, :phone,
             :customer_type, :company_json, :shipping_json, :billing_json, :subtotal, 0,
             0, 0, :total, :currency, :status, :payment_status, :payment_method, :shipping_method,
             :notes, :terms_at, 0, :created, :updated)');
        $order->execute([
            'number' => $orderNumber,
            'public_token' => hash('sha256', $token . '-public'),
            'idempotency' => hash('sha256', $token . '-idempotency'),
            'cart' => $cartId,
            'email' => $email,
            'first_name' => 'Maria',
            'last_name' => 'Pop',
            'phone' => '0700000000',
            'customer_type' => 'person',
            'company_json' => '{}',
            'shipping_json' => '{}',
            'billing_json' => '{}',
            'subtotal' => 13900,
            'total' => 13900,
            'currency' => 'RON',
            'status' => $status,
            'payment_status' => 'paid',
            'payment_method' => 'cod',
            'shipping_method' => 'manual',
            'notes' => '',
            'terms_at' => $now,
            'created' => $now,
            'updated' => $now,
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $item = $this->pdo->prepare('INSERT INTO order_items
            (order_id, product_id, variant_id, product_name, variant_summary, sku, quantity, unit_price_bani,
             personalization_bani, line_total_bani, tax_bani, personalization_json)
            VALUES (:order_id, :product_id, NULL, :name, :variant, :sku, 1, :price, 0, :price, 0, :personalization)');
        $item->execute([
            'order_id' => $orderId,
            'product_id' => $productId,
            'name' => 'Produs test recenzie',
            'variant' => '',
            'sku' => 'REVIEW-TEST',
            'price' => 13900,
            'personalization' => '{}',
        ]);
    }
}
