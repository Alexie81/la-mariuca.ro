<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Orders\InternalOrderEmail;
use App\Modules\Orders\OrderCancellationEmail;
use App\Modules\Orders\OrderConfirmationEmail;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class EmailPublicLinksTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment;
    /** @var array<string,mixed> */
    private array $server;
    private int $orderId;
    private string $orderNumber;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $_ENV['WEB_PUSH_ENABLED'] = 'false';
        $_ENV['COD_ENABLED'] = 'true';
        $_ENV['MAIL_DELIVERY_MODE'] = 'preview';
        parent::setUp();
        $this->seed();
        // The order is deliberately created under the local development URL.
        // Email destinations must come from the current deployment, not the stored order.
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND i.physical_quantity>=1 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email'=>'public-links@example.test', 'first_name'=>'Ana', 'last_name'=>'Pop', 'phone'=>'0700000000',
            'address'=>'Strada Florilor 10', 'city'=>'București — Sector 1', 'county'=>'București', 'postal_code'=>'010101',
            'shipping_method_id'=>(string) $shipping, 'payment_method'=>'cod', 'terms'=>'1', 'idempotency_key'=>bin2hex(random_bytes(32)),
        ]);
        $this->orderId = (int) $order['id'];
        $this->orderNumber = (string) $this->pdo->query('SELECT order_number FROM orders WHERE id=' . $this->orderId)->fetchColumn();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->environment;
        $_SERVER = $this->server;
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function publicInstallations(): iterable
    {
        yield 'canonical production domain' => ['https://la-mariuca.ro', '', 'https://la-mariuca.ro'];
        yield 'public deployment under a subdirectory' => ['https://la-mariuca.ro/', '/boutique/', 'https://la-mariuca.ro/boutique'];
    }

    #[DataProvider('publicInstallations')]
    public function testEveryEmailUsesTheConfiguredPublicDeploymentAndItsCtasReachTheCorrectOrder(string $origin, string $basePath, string $expectedRoot): void
    {
        $_ENV['APP_URL'] = $origin;
        $_ENV['APP_BASE_PATH'] = $basePath;
        // Request/server headers must not be used to construct public email links.
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8084';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'untrusted.example.test';
        $config = Config::load($this->root);
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $config, $this->root);
        $twig = new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]);
        $images = new OrderEmailImages($config, $this->root);
        $confirmation = new OrderConfirmationEmail($tracking, $config, $twig, $images);
        $internal = new InternalOrderEmail($tracking, $config, $twig, $images);
        $cancellation = new OrderCancellationEmail($tracking, $config, $twig, $images);
        $trackingUrl = $tracking->emailUrl($this->orderId, 'public-links@example.test');
        $adminUrl = $expectedRoot . '/admin/comenzi/' . $this->orderId . '/';

        $mail = $confirmation->render($this->orderId, 'public-links@example.test');
        $this->assertPublicEmail($mail, $expectedRoot, $trackingUrl);
        $this->assertPublicEmail($internal->render($this->orderId, 'team@example.test'), $expectedRoot, $adminUrl);

        $this->pdo->prepare("UPDATE orders SET payment_method='stripe',payment_status='paid',status='confirmed' WHERE id=?")->execute([$this->orderId]);
        $this->assertPublicEmail($confirmation->render($this->orderId, 'public-links@example.test'), $expectedRoot, $trackingUrl);
        $this->assertPublicEmail($internal->render($this->orderId, 'team@example.test'), $expectedRoot, $adminUrl);

        $requestId = $tracking->requestCancellation($this->orderId, 'Doresc să aleg altă mărime pentru acest produs.');
        $this->assertPublicEmail($cancellation->render($this->orderId, $requestId, 'public-links@example.test', 'order_cancellation_received'), $expectedRoot, $trackingUrl);
        $this->assertPublicEmail($cancellation->render($this->orderId, $requestId, 'team@example.test', 'internal_order_cancellation'), $expectedRoot, $adminUrl . '#cerere-anulare');
        // Isolated presentation fixtures: both immutable decision variants use the same private CTA.
        foreach (['accepted', 'rejected'] as $decision) {
            $this->pdo->prepare('UPDATE order_cancellation_requests SET status=?,decision_reason=?,resolved_at=? WHERE id=?')
                ->execute([$decision, 'Răspunsul magazinului pentru solicitarea primită.', gmdate('c'), $requestId]);
            $this->pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$decision === 'accepted' ? 'cancelled' : 'confirmed', $this->orderId]);
            $this->assertPublicEmail($cancellation->render($this->orderId, $requestId, 'public-links@example.test', 'order_cancellation_decision'), $expectedRoot, $trackingUrl);
        }

        // In-process HTTP only: clicking the emailed link grants this order without a login.
        $_SESSION = [];
        $access = $this->get($trackingUrl);
        self::assertSame(303, $access->getStatusCode());
        self::assertSame($config->path('urmarire-comanda/'), $access->getHeaderLine('Location'));
        self::assertSame($this->orderId, (int) $_SESSION['tracked_order']['id']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        $page = $this->get($expectedRoot . '/urmarire-comanda/');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString($this->orderNumber, (string) $page->getBody());
        self::assertStringContainsString('Răspunsul magazinului pentru solicitarea primită.', (string) $page->getBody());

        // The product destination is public and opens the actual product, without the private order token.
        $product = $this->get($this->productUrl($expectedRoot));
        self::assertSame(200, $product->getStatusCode());
        self::assertStringContainsString((string) $this->pdo->query('SELECT product_name FROM order_items WHERE order_id=' . $this->orderId)->fetchColumn(), (string) $product->getBody());

        // The internal destination stays protected and preserves the public deployment path.
        $_SESSION = [];
        $admin = $this->get($adminUrl);
        self::assertSame(303, $admin->getStatusCode());
        self::assertSame($config->path('admin'), $admin->getHeaderLine('Location'));
        self::assertStringNotContainsString('Strada Florilor', (string) $admin->getBody());
    }

    /** @param array{subject:string,text:string,html:string} $mail */
    private function assertPublicEmail(array $mail, string $root, string $cta): void
    {
        foreach (['localhost', '127.0.0.1', 'untrusted.example.test'] as $localHost) {
            self::assertStringNotContainsString($localHost, $mail['html']);
            self::assertStringNotContainsString($localHost, $mail['text']);
        }
        self::assertStringContainsString('href="' . $cta . '"', $mail['html']);
        self::assertStringContainsString($cta, $mail['text']);
        self::assertStringContainsString('<v:roundrect href="' . $cta . '"', $mail['html']);
        preg_match_all('/\b(?:href|src)="([^"]+)"/', $mail['html'], $matches);
        self::assertNotEmpty($matches[1]);
        $unexpected = [];
        foreach (array_unique($matches[1]) as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_starts_with($url, $root . '/') && !str_starts_with($url, 'mailto:')
                && !in_array($url, ['https://cab-it.ro/', 'https://anpc.ro/sal/'], true)) {
                $unexpected[] = $url;
            }
        }
        self::assertSame([], $unexpected, 'Every internal link and asset must use the configured deployment root.');
        $productUrl = $this->productUrl($root);
        // Both thumbnail and name open the product in customer and internal emails.
        preg_match_all('~<a\b[^>]*href="' . preg_quote($productUrl, '~') . '"[^>]*>~', $mail['html'], $productLinks);
        self::assertCount(2, $productLinks[0]);
        foreach ($productLinks[0] as $anchor) {
            self::assertStringContainsString('target="_blank"', $anchor);
            self::assertStringContainsString('rel="noopener noreferrer"', $anchor);
        }
        self::assertStringContainsString('Deschide produsul: ' . $productUrl, $mail['text']);
    }

    private function productUrl(string $root): string
    {
        $slug = (string) $this->pdo->query('SELECT p.slug FROM products p JOIN order_items i ON i.product_id=p.id WHERE i.order_id=' . $this->orderId)->fetchColumn();
        return $root . '/produs/' . rawurlencode($slug) . '/';
    }

    /** @return iterable<string,array{string}> */
    public static function unavailableProducts(): iterable
    {
        yield 'removed product' => ['missing'];
        yield 'unpublished product' => ['draft'];
        yield 'archived product' => ['archived'];
    }

    #[DataProvider('unavailableProducts')]
    public function testUnavailableProductKeepsOrderSnapshotWithoutDeadLinks(string $availability): void
    {
        if ($availability === 'missing') {
            $this->pdo->prepare('UPDATE order_items SET product_id=NULL,variant_id=NULL WHERE order_id=?')->execute([$this->orderId]);
        } else {
            $this->pdo->prepare('UPDATE products SET status=? WHERE id=(SELECT product_id FROM order_items WHERE order_id=?)')->execute([$availability, $this->orderId]);
        }
        $name = (string) $this->pdo->query('SELECT product_name FROM order_items WHERE order_id=' . $this->orderId)->fetchColumn();
        $config = Config::load($this->root);
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $config, $this->root);
        $twig = new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape'=>'html', 'strict_variables'=>true]);
        $images = new OrderEmailImages($config, $this->root);
        $requestId = $tracking->requestCancellation($this->orderId, 'Doresc să aleg altă mărime pentru acest produs.');
        $emails = [
            (new OrderConfirmationEmail($tracking, $config, $twig, $images))->render($this->orderId, 'public-links@example.test'),
            (new InternalOrderEmail($tracking, $config, $twig, $images))->render($this->orderId, 'team@example.test'),
            (new OrderCancellationEmail($tracking, $config, $twig, $images))->render($this->orderId, $requestId, 'public-links@example.test', 'order_cancellation_received'),
            (new OrderCancellationEmail($tracking, $config, $twig, $images))->render($this->orderId, $requestId, 'team@example.test', 'internal_order_cancellation'),
        ];
        foreach ($emails as $mail) {
            self::assertStringContainsString($name, $mail['html']);
            self::assertStringContainsString($name, $mail['text']);
            self::assertDoesNotMatchRegularExpression('~href="[^"]*/produs/~', $mail['html']);
            self::assertStringNotContainsString('Deschide produsul:', $mail['text']);
        }
    }

    private function get(string $url): ResponseInterface
    {
        $app = AppFactory::create($this->root);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $url, ['REMOTE_ADDR'=>'127.0.0.1']));
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
