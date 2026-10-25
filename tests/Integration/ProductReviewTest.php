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
        self::assertStringContainsString('DEMO · exemplu de afișare', $body);
        self::assertStringContainsString('action="/produs/costumas-micul-gentleman/recenzie/"', $body);
        self::assertStringContainsString('name="review_consent"', $body);
        self::assertStringContainsString('data-review-carousel', $body);
        self::assertStringContainsString('data-review-open', $body);
        self::assertStringContainsString('data-review-dialog', $body);
        self::assertStringContainsString('data-review-expand', $body);
        self::assertStringContainsString('data-review-detail-dialog', $body);
        self::assertStringContainsString('aria-label="Recenzia precedentă"', $body);
    }

    public function testValidReviewSubmissionIsStoredPendingForModeration(): void
    {
        $this->seed();
        $_SESSION['_csrf'] = 'review-test-token';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/produs/costumas-micul-gentleman/recenzie/', ['REMOTE_ADDR' => '127.0.0.55'])
            ->withParsedBody([
                '_csrf' => 'review-test-token',
                'rating' => '5',
                'author_name' => 'Maria Pop',
                'author_email' => 'maria@example.test',
                'title' => 'Exact ce căutam',
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
}
