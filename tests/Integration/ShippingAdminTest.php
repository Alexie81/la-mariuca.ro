<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Cart\CartService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class ShippingAdminTest extends DatabaseTestCase
{
    private const PASSWORD = 'Shipping-admin-2026!';
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('shipping.admin@example.test',:password,'Paula','Admin','administrator','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'shipping.admin@example.test',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
        $this->csrf = (string) $_SESSION['_csrf'];
    }

    public function testRegistryRequiresAdminAndRendersGuidedFormsWithoutFreeThreshold(): void
    {
        $adminSession = $_SESSION;
        $_SESSION = [];
        $anonymous = $this->get('/admin/livrare/');
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertSame('/admin', $anonymous->getHeaderLine('Location'));
        $_SESSION = $adminSession;

        $page = (string) $this->get('/admin/livrare/')->getBody();
        self::assertStringContainsString('Livrare', $page);
        self::assertStringContainsString('href="/admin/livrare/" aria-current="page"', $page);
        self::assertStringContainsString('data-admin-shipping-dialog', $page);
        self::assertStringContainsString('Adaugă metodă', $page);
        self::assertStringContainsString('href="/admin/cupoane-promotii/"', $page);
        self::assertStringNotContainsString('name="minimum"', $page);
        self::assertStringNotContainsString('name="free_threshold"', $page);
        self::assertStringNotContainsString('SHIPPING_FREE_THRESHOLD', $page);

        $promotions = strpos($page, 'href="/admin/cupoane-promotii/"');
        $shipping = strpos($page, 'href="/admin/livrare/"');
        $stock = strpos($page, 'Stoc și achiziții');
        self::assertIsInt($promotions);
        self::assertIsInt($shipping);
        self::assertIsInt($stock);
        self::assertTrue($promotions < $shipping && $shipping < $stock);

        $fullForm = (string) $this->get('/admin/livrare/noua/')->getBody();
        self::assertStringContainsString('data-admin-shipping-form', $fullForm);
        self::assertStringContainsString('name="_csrf"', $fullForm);
        self::assertStringContainsString('name="name"', $fullForm);
        self::assertStringContainsString('name="code"', $fullForm);
        self::assertStringContainsString('name="type"', $fullForm);
        self::assertStringContainsString('name="price"', $fullForm);
        self::assertStringContainsString('name="estimated_min_days"', $fullForm);
        self::assertStringContainsString('name="estimated_max_days"', $fullForm);
        self::assertStringContainsString('name="position"', $fullForm);
        self::assertStringContainsString('name="is_default"', $fullForm);
        self::assertStringContainsString('name="status"', $fullForm);
        self::assertStringNotContainsString('name="minimum"', $fullForm);

        $modal = (string) $this->get('/admin/livrare/noua/modal/')->getBody();
        self::assertStringContainsString('data-admin-shipping-form', $modal);
        self::assertStringContainsString('Adaugă o metodă de livrare', $modal);
        self::assertStringNotContainsString('<html', $modal);

        $withoutCsrf = $this->post('/admin/livrare/noua/', $this->validPayload());
        self::assertSame(419, $withoutCsrf->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code='fan-courier'")->fetchColumn());
    }

    public function testCreateEditOrderValidationAndStateFlow(): void
    {
        $this->pdo->exec('DELETE FROM shipping_methods');

        $invalid = $this->post('/admin/livrare/noua/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'name' => '',
            'price' => '-1',
            'estimated_min_days' => '5',
            'estimated_max_days' => '2',
            'status' => 'necunoscut',
        ]), ['Accept' => 'application/json']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM shipping_methods')->fetchColumn());

        $tooLongDescription = $this->post('/admin/livrare/noua/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'description' => str_repeat('ă', 161),
        ]), ['Accept' => 'application/json']);
        self::assertSame(422, $tooLongDescription->getStatusCode());
        self::assertStringContainsString('maximum 160 de caractere', (string) $tooLongDescription->getBody());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM shipping_methods')->fetchColumn());

        $fan = $this->post('/admin/livrare/noua/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'is_default' => '1',
        ]), ['Accept' => 'application/json']);
        self::assertSame(200, $fan->getStatusCode());
        $fanPayload = $this->json($fan);
        self::assertTrue($fanPayload['ok']);
        $fanId = (int) $fanPayload['id'];
        $fanRow = $this->shippingMethod($fanId);
        self::assertSame('Fan Courier', $fanRow['name']);
        self::assertSame('fan-courier', $fanRow['code']);
        self::assertSame('courier', $fanRow['type']);
        self::assertSame(2490, (int) $fanRow['price_bani']);
        self::assertSame(1, (int) $fanRow['estimated_min_days']);
        self::assertSame(2, (int) $fanRow['estimated_max_days']);
        self::assertSame(20, (int) $fanRow['position']);
        self::assertSame(1, (int) $fanRow['is_default']);
        self::assertSame('active', $fanRow['status']);

        $locker = $this->post('/admin/livrare/noua/', [
            '_csrf' => $this->csrf,
            'name' => 'Cargus Ship & Go',
            'code' => 'cargus-locker',
            'type' => 'locker',
            'description' => 'Ridicare din punctul ales de client.',
            'price' => '19,50',
            'estimated_min_days' => '2',
            'estimated_max_days' => '3',
            'position' => '10',
            'status' => 'active',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $locker->getStatusCode());
        $lockerId = (int) $this->json($locker)['id'];

        $registry = (string) $this->get('/admin/livrare/')->getBody();
        self::assertStringContainsString('Fan Courier', $registry);
        self::assertStringContainsString('Cargus Ship &amp; Go', $registry);
        self::assertStringContainsString('24,90', $registry);
        self::assertTrue(strpos($registry, 'Cargus Ship &amp; Go') < strpos($registry, 'Fan Courier'));

        $duplicate = $this->post('/admin/livrare/noua/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'name' => 'Alt curier',
            'code' => 'FAN-COURIER',
        ]), ['Accept' => 'application/json']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM shipping_methods WHERE code='fan-courier'")->fetchColumn());

        $editModal = (string) $this->get('/admin/livrare/' . $fanId . '/editare/modal/')->getBody();
        self::assertStringContainsString('data-admin-shipping-form', $editModal);
        self::assertStringContainsString('value="Fan Courier"', $editModal);
        self::assertStringContainsString('value="24,90"', $editModal);

        $edit = $this->post('/admin/livrare/' . $fanId . '/editare/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'name' => 'Fan Courier Standard',
            'price' => '29,90',
            'estimated_min_days' => '2',
            'estimated_max_days' => '4',
            'position' => '5',
            'is_default' => '1',
        ]), ['Accept' => 'application/json']);
        self::assertSame(200, $edit->getStatusCode());
        $edited = $this->shippingMethod($fanId);
        self::assertSame('Fan Courier Standard', $edited['name']);
        self::assertSame(2990, (int) $edited['price_bani']);
        self::assertSame(5, (int) $edited['position']);

        $deactivate = $this->post('/admin/livrare/' . $lockerId . '/stare/', [
            '_csrf' => $this->csrf,
            'status' => 'inactive',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $deactivate->getStatusCode());
        self::assertSame('inactive', $this->pdo->query('SELECT status FROM shipping_methods WHERE id=' . $lockerId)->fetchColumn());
        self::assertNull($this->pdo->query('SELECT deleted_at FROM shipping_methods WHERE id=' . $lockerId)->fetchColumn());

        $activate = $this->post('/admin/livrare/' . $lockerId . '/stare/', [
            '_csrf' => $this->csrf,
            'status' => 'active',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $activate->getStatusCode());
        self::assertSame('active', $this->pdo->query('SELECT status FROM shipping_methods WHERE id=' . $lockerId)->fetchColumn());
    }

    public function testCheckoutShowsOnlyActiveMethodsAndPreselectsTheDefault(): void
    {
        $this->pdo->exec('DELETE FROM shipping_methods');
        $defaultResponse = $this->post('/admin/livrare/noua/', array_replace($this->validPayload(), [
            '_csrf' => $this->csrf,
            'name' => 'Curier preferat',
            'code' => 'curier-preferat',
            'position' => '20',
            'is_default' => '1',
        ]), ['Accept' => 'application/json']);
        self::assertSame(200, $defaultResponse->getStatusCode());
        $defaultId = (int) $this->json($defaultResponse)['id'];
        $lockerResponse = $this->post('/admin/livrare/noua/', [
            '_csrf' => $this->csrf,
            'name' => 'Locker economic',
            'code' => 'locker-economic',
            'type' => 'locker',
            'description' => 'Ridicare din locker.',
            'price' => '12,50',
            'estimated_min_days' => '2',
            'estimated_max_days' => '3',
            'position' => '10',
            'status' => 'active',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $lockerResponse->getStatusCode());
        $lockerId = (int) $this->json($lockerResponse)['id'];
        $hiddenResponse = $this->post('/admin/livrare/noua/', [
            '_csrf' => $this->csrf,
            'name' => 'Ridicare indisponibilă',
            'code' => 'ridicare-indisponibila',
            'type' => 'pickup',
            'description' => 'Nu trebuie afișată.',
            'price' => '0',
            'estimated_min_days' => '0',
            'estimated_max_days' => '0',
            'position' => '1',
            'status' => 'inactive',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $hiddenResponse->getStatusCode());

        $variantId = (int) $this->pdo->query("SELECT v.id FROM product_variants v JOIN inventory i ON i.variant_id=v.id WHERE v.status='active' AND i.physical_quantity-i.reserved_quantity>0 ORDER BY v.id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $variantId);
        (new CartService($this->pdo))->add($variantId, 1);

        $checkout = (string) $this->get('/finalizare-comanda/')->getBody();
        self::assertStringContainsString('name="shipping_method_id"', $checkout);
        self::assertStringContainsString('value="' . $defaultId . '"', $checkout);
        self::assertStringContainsString('value="' . $lockerId . '"', $checkout);
        self::assertStringContainsString('Curier preferat', $checkout);
        self::assertStringContainsString('Locker economic', $checkout);
        self::assertStringContainsString('24,90', $checkout);
        self::assertStringContainsString('12,50', $checkout);
        self::assertStringNotContainsString('Ridicare indisponibilă', $checkout);
        self::assertTrue(strpos($checkout, 'Curier preferat') < strpos($checkout, 'Locker economic'), 'Metoda implicită trebuie afișată prima chiar dacă are un număr de ordine mai mare.');
        self::assertMatchesRegularExpression('/value="' . $defaultId . '"[^>]*checked/', $checkout);
    }

    public function testSuggestedMethodControlUsesAnAnchoredInputAndANonScrollableDialogShell(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/shipping-admin.css');
        self::assertIsString($css);
        self::assertStringContainsString('.admin-shipping-dialog__surface,.admin-shipping-dialog__content{overflow:clip}', $css);
        self::assertStringContainsString('.admin-shipping-availability>label.admin-shipping-default>input{width:1px;height:1px;min-height:1px', $css);
    }

    /** @return array<string,string> */
    private function validPayload(): array
    {
        return [
            'name' => 'Fan Courier',
            'code' => 'fan-courier',
            'type' => 'courier',
            'description' => 'Livrare la adresă, cu urmărirea coletului.',
            'price' => '24,90',
            'estimated_min_days' => '1',
            'estimated_max_days' => '2',
            'position' => '20',
            'status' => 'active',
        ];
    }

    /** @return array<string,mixed> */
    private function shippingMethod(int $id): array
    {
        $row = $this->pdo->query('SELECT * FROM shipping_methods WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function post(string $path, array $data, array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.96'])
            ->withHeader('User-Agent', 'LaMariuca-ShippingAdmin-Test/1.0');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
