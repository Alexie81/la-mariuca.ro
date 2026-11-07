<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use App\Modules\Checkout\CheckoutService;
use App\Shared\Config\Config;
use App\Shared\Security\AdminOrderDestination;
use App\Shared\Security\Auth;
use App\Shared\Security\NullQrProvider;
use App\Shared\Security\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use RobThree\Auth\TwoFactorAuth;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class AdminOrderDestinationTest extends DatabaseTestCase
{
    private const PASSWORD = 'Admin-Destination-2026!';
    private int $adminId;
    private int $orderId;
    /** @var array<string,mixed> */
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        parent::setUp();
        $this->seed();
        $_ENV['COD_ENABLED'] = 'true';
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES ('order.admin@example.test',:password,'Ana','Admin','administrator','active',:created,:updated)")
            ->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'created' => gmdate('c'), 'updated' => gmdate('c')]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $variant = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN products p ON p.id=v.product_id JOIN inventory i ON i.variant_id=v.id WHERE p.status='published' AND v.status='active' AND i.physical_quantity-i.reserved_quantity>1 ORDER BY v.id LIMIT 1")->fetchColumn();
        $shipping = (int) $this->pdo->query("SELECT id FROM shipping_methods WHERE status='active' AND deleted_at IS NULL ORDER BY is_default DESC,position,id LIMIT 1")->fetchColumn();
        $cart = new CartService($this->pdo);
        $cart->add($variant, 1);
        $order = (new CheckoutService($this->pdo, $cart, Config::load($this->root)))->place([
            'email' => 'buyer.destination@example.test', 'first_name' => 'Ana', 'last_name' => 'Pop', 'phone' => '0700000000',
            'address' => 'Strada Test 1', 'city' => 'București — Sector 1', 'county' => 'București', 'postal_code' => '',
            'shipping_method_id' => $shipping, 'payment_method' => 'cod', 'idempotency_key' => bin2hex(random_bytes(20)), 'terms' => '1',
        ]);
        $this->orderId = $order['id'];
        $this->request('GET', '/admin');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV = $this->originalEnv;
    }

    public function testGuestReturnsToEmailedOrderAfterLoginAndWrongPasswordDoesNotLoseIt(): void
    {
        $destination = '/admin/comenzi/' . $this->orderId . '/';
        $guest = $this->request('GET', $destination . '?return_url=https://outside.example');
        self::assertSame(303, $guest->getStatusCode());
        self::assertSame('/admin', $guest->getHeaderLine('Location'));
        self::assertSame($this->orderId, $_SESSION['admin_order_destination']['order_id']);
        self::assertSame('/admin', $this->login('wrong-password')->getHeaderLine('Location'));
        self::assertArrayHasKey('admin_order_destination', $_SESSION);
        $login = $this->login(self::PASSWORD, ['return_url' => 'https://outside.example']);
        self::assertSame($destination, $login->getHeaderLine('Location'));
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
        self::assertSame(200, $this->request('GET', $destination)->getStatusCode());
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
    }

    public function testConfiguredBasePathIsPreservedAndOnlyOperatorOrAdminCanComplete(): void
    {
        $_ENV['APP_BASE_PATH'] = '/boutique';
        $destination = '/boutique/admin/comenzi/' . $this->orderId . '/';
        self::assertSame('/boutique/admin', $this->request('GET', $destination)->getHeaderLine('Location'));
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=" . $this->adminId);
        self::assertSame('/boutique/admin', $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertArrayHasKey('admin_order_destination', $_SESSION);
        $this->pdo->exec("UPDATE users SET role='operator' WHERE id=" . $this->adminId);
        self::assertSame($destination, $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertSame(200, $this->request('GET', $destination)->getStatusCode());
    }

    public function testTotpRetainsDestinationUntilSecondFactorSucceeds(): void
    {
        $totp = new TotpService(Config::load($this->root));
        $secret = $totp->createSecret();
        $this->pdo->prepare('UPDATE users SET totp_enabled=1,totp_secret_encrypted=:secret WHERE id=:id')
            ->execute(['secret' => $totp->encrypt($secret), 'id' => $this->adminId]);
        $destination = '/admin/comenzi/' . $this->orderId . '/';
        $this->request('GET', $destination);
        self::assertSame('/cont/totp/', $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayHasKey('admin_order_destination', $_SESSION);
        self::assertSame('/cont/totp/', $this->request('POST', '/cont/totp/', ['_csrf' => $_SESSION['_csrf'], 'code' => 'bad-code'])->getHeaderLine('Location'));
        self::assertArrayHasKey('admin_order_destination', $_SESSION);
        $code = (new TwoFactorAuth(new NullQrProvider(), 'La Mariuca Boutique'))->getCode($secret);
        $success = $this->request('POST', '/cont/totp/', ['_csrf' => $_SESSION['_csrf'], 'code' => $code]);
        self::assertSame($destination, $success->getHeaderLine('Location'));
        self::assertSame($this->adminId, $_SESSION['user_id']);
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
    }

    public function testExpiredDestinationAndDifferentBaseAreDiscardedAfterLogin(): void
    {
        $this->request('GET', '/admin/comenzi/' . $this->orderId . '/');
        $_SESSION['admin_order_destination']['expires_at'] = time();
        self::assertSame('/admin', $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
        (new Auth($this->pdo))->logout();
        $this->request('GET', '/admin/comenzi/' . $this->orderId . '/');
        $_ENV['APP_BASE_PATH'] = '/boutique';
        self::assertSame('/boutique/admin', $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
    }

    public function testExternalSessionDestinationIsNeverUsedAndLogoutClearsPendingIntent(): void
    {
        $_SESSION['admin_order_destination'] = ['url' => 'https://outside.example', 'expires_at' => time() + 900];
        self::assertSame('/admin', $this->login(self::PASSWORD)->getHeaderLine('Location'));
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
        $_SESSION['admin_order_destination'] = ['order_id' => 'https://outside.example', 'base_path' => '/', 'expires_at' => time() + 900];
        self::assertNull((new AdminOrderDestination(Config::load($this->root)))->consume(new Auth($this->pdo)));
        $_SESSION['admin_order_destination'] = ['order_id' => $this->orderId, 'base_path' => '/', 'expires_at' => time() + 900];
        self::assertSame('/admin', $this->request('POST', '/admin/deconectare/', ['_csrf' => $_SESSION['_csrf']])->getHeaderLine('Location'));
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
    }

    /** @return iterable<string,array{string,string}> */
    public static function ignoredRequests(): iterable
    {
        yield 'mutation is never replayed' => ['POST', '/admin/comenzi/31/'];
        yield 'status action is not order page' => ['GET', '/admin/comenzi/31/status/modal/'];
        yield 'outside URL in query' => ['GET', '/admin?return_url=https://outside.example'];
        yield 'zero id' => ['GET', '/admin/comenzi/0/'];
        yield 'encoded URL' => ['GET', '/admin/comenzi/https%3A%2F%2Foutside.example/'];
        yield 'different prefix' => ['GET', '/outside/admin/comenzi/31/'];
        yield 'overflow id' => ['GET', '/admin/comenzi/9999999999999999999999999999999/'];
    }

    #[DataProvider('ignoredRequests')]
    public function testOnlyExactNumericOrderGetPathsCanBecomeDestinations(string $method, string $path): void
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);
        (new AdminOrderDestination(Config::load($this->root)))->remember($request);
        self::assertArrayNotHasKey('admin_order_destination', $_SESSION);
    }

    /** @param array<string,mixed> $extra */
    private function login(string $password, array $extra = []): ResponseInterface
    {
        $path = Config::load($this->root)->path('admin');
        return $this->request('POST', $path, ['_csrf' => $_SESSION['_csrf'], 'email' => 'order.admin@example.test', 'password' => $password] + $extra);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.97']);
        if ($data !== []) { $request = $request->withParsedBody($data); }
        $app = AppFactory::create($this->root);
        try { return $app->handle($request); }
        finally { unset($app); gc_collect_cycles(); }
    }
}
