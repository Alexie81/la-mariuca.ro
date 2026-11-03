<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class PromotionAdminTest extends DatabaseTestCase
{
    private const ADMIN_PASSWORD = 'Promotions-admin-2026!';
    private int $adminId;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $now = gmdate('c');
        $admin = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('promotions.admin@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $admin->execute(['password' => password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'promotions.admin@example.test',
            'password' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
        $this->csrf = (string) $_SESSION['_csrf'];
    }

    public function testRegistryRequiresAdminAndRendersNavigationFallbackAndBothForms(): void
    {
        $adminSession = $_SESSION;
        $_SESSION = [];
        $anonymous = $this->get('/admin/cupoane-promotii/');
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertSame('/admin', $anonymous->getHeaderLine('Location'));
        $_SESSION = $adminSession;

        $registry = $this->get('/admin/cupoane-promotii/');
        self::assertSame(200, $registry->getStatusCode());
        $body = (string) $registry->getBody();
        self::assertStringContainsString('<h1 id="admin-promotions-title">Cupoane și promoții</h1>', $body);
        self::assertStringContainsString('href="/admin/cupoane-promotii/" aria-current="page"', $body);
        self::assertStringContainsString('data-admin-promotion-dialog', $body);
        self::assertStringContainsString('Adaugă cupon', $body);
        self::assertStringContainsString('Adaugă reducere', $body);
        self::assertStringContainsString('Registrul este pregătit pentru prima ofertă', $body);
        self::assertStringContainsString('assets/css/promotion-admin.css', $body);

        $legacyRegistry = $this->get('/admin/cupoane/');
        self::assertSame(303, $legacyRegistry->getStatusCode());
        self::assertSame('/admin/cupoane-promotii/', $legacyRegistry->getHeaderLine('Location'));

        $customers = strpos($body, 'href="/admin/clienti/"');
        $newsletter = strpos($body, 'href="/admin/abonati-newsletter/"');
        $promotions = strpos($body, 'href="/admin/cupoane-promotii/"');
        $stock = strpos($body, 'Stoc și achiziții');
        self::assertIsInt($customers);
        self::assertIsInt($newsletter);
        self::assertIsInt($promotions);
        self::assertIsInt($stock);
        self::assertTrue($customers < $newsletter && $newsletter < $promotions && $promotions < $stock);

        $couponPage = $this->get('/admin/cupoane-promotii/cupon/nou/');
        self::assertSame(200, $couponPage->getStatusCode());
        self::assertStringContainsString('data-offer-kind="coupon"', (string) $couponPage->getBody());
        self::assertStringContainsString('name="code"', (string) $couponPage->getBody());
        self::assertStringContainsString('name="_csrf"', (string) $couponPage->getBody());
        $couponModal = $this->get('/admin/cupoane-promotii/cupon/nou/modal/');
        self::assertSame(200, $couponModal->getStatusCode());
        self::assertStringContainsString('data-admin-promotion-form', (string) $couponModal->getBody());
        self::assertStringContainsString('data-admin-promotion-code-preview', (string) $couponModal->getBody());
        self::assertStringContainsString('nu trebuie repetat', (string) $couponModal->getBody());
        self::assertStringNotContainsString('<html', (string) $couponModal->getBody());

        $promotionPage = $this->get('/admin/cupoane-promotii/reducere/nou/');
        self::assertSame(200, $promotionPage->getStatusCode());
        self::assertStringContainsString('data-offer-kind="promotion"', (string) $promotionPage->getBody());
        self::assertStringNotContainsString('name="code"', (string) $promotionPage->getBody());
        $promotionModal = $this->get('/admin/cupoane-promotii/reducere/nou/modal/');
        self::assertSame(200, $promotionModal->getStatusCode());
        self::assertStringContainsString('Creează o reducere', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-product-search', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-topbar-toggle', (string) $promotionModal->getBody());
        self::assertStringContainsString('name="minimum"', (string) $promotionModal->getBody());
        self::assertStringContainsString('name="minimum_mode"', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-preset="shipping"', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-minimum-preview', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-message-suggestion', (string) $promotionModal->getBody());
        self::assertStringContainsString('data-admin-promotion-use-message', (string) $promotionModal->getBody());
        self::assertStringContainsString('Rămâne activă până când alegi să o oprești.', (string) $promotionModal->getBody());

        $storefront = (string) $this->get('/')->getBody();
        self::assertStringNotContainsString('class="site-announcement ', $storefront);
        self::assertStringContainsString('Livrare rapidă în toată România', $storefront);
    }

    public function testCouponPostNormalizesCodeRejectsDuplicatePersistsPeriodAndRendersPublicTopbar(): void
    {
        $bucharest = new \DateTimeZone('Europe/Bucharest');
        $start = (new \DateTimeImmutable('now', $bucharest))->modify('-30 minutes');
        $end = $start->modify('+3 days');
        $payload = [
            '_csrf' => $this->csrf,
            'name' => 'Bun venit — cincisprezece procente',
            'code' => 'bunvenit15',
            'scope' => 'order',
            'value_type' => 'percent',
            'value' => '15',
            'minimum' => '100,00',
            'usage_limit' => '50',
            'per_customer_limit' => '1',
            'validity' => 'period',
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $end->format('Y-m-d\TH:i'),
            'topbar_enabled' => '1',
            'topbar_message' => 'Primești 15% cu codul BUNVENIT15',
        ];
        $created = $this->post('/admin/cupoane-promotii/cupon/nou/', $payload, ['Accept' => 'application/json']);
        self::assertSame(200, $created->getStatusCode());
        $json = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertSame('/admin/cupoane-promotii/', $json['redirect']);

        $coupon = $this->pdo->query("SELECT * FROM coupons WHERE code='BUNVENIT15'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($coupon);
        self::assertSame('BUNVENIT15', $coupon['code']);
        self::assertSame('order', $coupon['scope']);
        self::assertSame('percent', $coupon['type']);
        self::assertSame('percent', $coupon['value_type']);
        self::assertSame(1500, (int) $coupon['value']);
        self::assertSame(10000, (int) $coupon['minimum_bani']);
        self::assertSame(50, (int) $coupon['usage_limit']);
        self::assertSame(1, (int) $coupon['per_customer_limit']);
        self::assertSame($start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:00P'), $coupon['starts_at']);
        self::assertSame($end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:00P'), $coupon['ends_at']);
        self::assertSame(1, (int) $coupon['topbar_enabled']);
        self::assertSame($this->adminId, (int) $coupon['created_by_user_id']);

        $registry = (string) $this->get('/admin/cupoane-promotii/?cautare=bunvenit15')->getBody();
        self::assertStringContainsString('BUNVENIT15', $registry);
        self::assertStringContainsString('15% reducere', $registry);
        self::assertStringContainsString('Prag minim 100,00 Lei', $registry);

        $storefront = (string) $this->get('/')->getBody();
        self::assertStringContainsString('class="site-announcement site-announcement--coupon"', $storefront);
        self::assertStringContainsString('Primești 15% cu codul BUNVENIT15', $storefront);
        self::assertStringContainsString('<strong>BUNVENIT15</strong>', $storefront);
        self::assertStringNotContainsString('Livrare rapidă în toată România', $storefront);

        $this->pdo->prepare('UPDATE coupons SET usage_limit=1 WHERE id=:id')->execute(['id' => $coupon['id']]);
        $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,order_id,email,status,created_at,updated_at) VALUES (:coupon,NULL,'limita@example.test','confirmed',:now,:now)")
            ->execute(['coupon' => $coupon['id'], 'now' => gmdate('c')]);
        $storefrontAfterLimit = (string) $this->get('/')->getBody();
        self::assertStringNotContainsString('Primești 15% cu codul BUNVENIT15', $storefrontAfterLimit);
        self::assertStringContainsString('Livrare rapidă în toată România', $storefrontAfterLimit);

        $duplicate = $this->post('/admin/cupoane-promotii/cupon/nou/', array_replace($payload, ['code' => 'BUNVENIT15']), ['Accept' => 'application/json']);
        self::assertSame(422, $duplicate->getStatusCode());
        $duplicateJson = json_decode((string) $duplicate->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($duplicateJson['ok']);
        self::assertStringContainsString('Există deja un cupon', $duplicateJson['message']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM coupons WHERE code='BUNVENIT15'")->fetchColumn());
    }

    public function testPromotionScopesSemanticSearchTopbarEligibilityAndDeactivation(): void
    {
        $product = $this->pdo->query("SELECT p.id,p.name,p.primary_category_id FROM products p JOIN product_variants v ON v.product_id=p.id WHERE p.status='published' AND v.status='active' ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($product);
        $categoryId = (int) $product['primary_category_id'];

        $productResponse = $this->post('/admin/cupoane-promotii/reducere/nou/', [
            '_csrf' => $this->csrf,
            'name' => 'Ținută vedetă pentru test',
            'scope' => 'product',
            'target_id' => (string) $product['id'],
            'value_type' => 'fixed',
            'value' => '25,00',
            'validity' => 'always',
        ]);
        self::assertSame(303, $productResponse->getStatusCode());
        $categoryResponse = $this->post('/admin/cupoane-promotii/reducere/nou/', [
            '_csrf' => $this->csrf,
            'name' => 'Categorie pentru întreaga ramură',
            'scope' => 'category',
            'target_id' => (string) $categoryId,
            'value_type' => 'percent',
            'value' => '12,5',
            'validity' => 'always',
        ]);
        self::assertSame(303, $categoryResponse->getStatusCode());
        $shippingResponse = $this->post('/admin/cupoane-promotii/reducere/nou/', [
            '_csrf' => $this->csrf,
            'name' => 'Livrare gratuită activă',
            'scope' => 'shipping',
            'value_type' => 'free',
            'value' => '',
            'minimum_mode' => 'amount',
            'minimum' => '300,00',
            'validity' => 'always',
            'show_in_topbar' => '1',
            'topbar_message' => 'Livrarea este din partea noastră astăzi',
        ]);
        self::assertSame(303, $shippingResponse->getStatusCode());

        $productPromotion = $this->pdo->query("SELECT * FROM promotions WHERE name='Ținută vedetă pentru test'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($productPromotion);
        self::assertSame('product', $productPromotion['scope']);
        self::assertSame((int) $product['id'], (int) $productPromotion['target_id']);
        self::assertSame(2500, (int) $productPromotion['value']);
        $categoryPromotion = $this->pdo->query("SELECT * FROM promotions WHERE name='Categorie pentru întreaga ramură'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($categoryPromotion);
        self::assertSame('category', $categoryPromotion['scope']);
        self::assertSame($categoryId, (int) $categoryPromotion['target_id']);
        self::assertSame(1250, (int) $categoryPromotion['value']);
        $shippingPromotion = $this->pdo->query("SELECT * FROM promotions WHERE name='Livrare gratuită activă'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($shippingPromotion);
        self::assertSame('shipping', $shippingPromotion['scope']);
        self::assertNull($shippingPromotion['target_id']);
        self::assertSame('free', $shippingPromotion['value_type']);
        self::assertSame(30000, (int) $shippingPromotion['minimum_bani']);

        $missingThreshold = $this->post('/admin/cupoane-promotii/reducere/nou/', [
            '_csrf' => $this->csrf,
            'name' => 'Prag lipsă pentru test',
            'scope' => 'shipping',
            'value_type' => 'free',
            'minimum_mode' => 'amount',
            'minimum' => '',
            'validity' => 'always',
        ], ['Accept' => 'application/json']);
        self::assertSame(422, $missingThreshold->getStatusCode());
        $missingThresholdJson = json_decode((string) $missingThreshold->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($missingThresholdJson['ok']);
        self::assertStringContainsString('valoarea minimă', $missingThresholdJson['message']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM promotions WHERE name='Prag lipsă pentru test'")->fetchColumn());

        $invalidThresholdMode = $this->post('/admin/cupoane-promotii/reducere/nou/', [
            '_csrf' => $this->csrf,
            'name' => 'Mod prag invalid pentru test',
            'scope' => 'shipping',
            'value_type' => 'free',
            'minimum_mode' => 'necunoscut',
            'minimum' => '',
            'validity' => 'always',
        ], ['Accept' => 'application/json']);
        self::assertSame(422, $invalidThresholdMode->getStatusCode());
        $invalidThresholdJson = json_decode((string) $invalidThresholdMode->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($invalidThresholdJson['ok']);
        self::assertStringContainsString('condiție de activare validă', $invalidThresholdJson['message']);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->insertPromotion('Ofertă viitoare exclusă', 'order', 'percent', 900, $now->modify('+2 days')->format('c'), $now->modify('+4 days')->format('c'), 'active', 'Mesaj viitor care nu trebuie afișat', 900);
        $this->insertPromotion('Ofertă expirată exclusă', 'order', 'percent', 800, $now->modify('-4 days')->format('c'), $now->modify('-2 days')->format('c'), 'active', 'Mesaj expirat care nu trebuie afișat', 800);
        $this->insertPromotion('Ofertă inactivă exclusă', 'order', 'percent', 700, null, null, 'inactive', 'Mesaj inactiv care nu trebuie afișat', 1000);
        unset($_SESSION['_flash']);

        $semantic = (string) $this->get('/admin/cupoane-promotii/?cautare=livrare%20activa')->getBody();
        self::assertStringContainsString('Livrare gratuită activă', $semantic);
        self::assertStringContainsString('Prag minim 300,00 Lei', $semantic);
        self::assertStringNotContainsString('Ținută vedetă pentru test', $semantic);
        $accentless = (string) $this->get('/admin/cupoane-promotii/?cautare=tinuta%20produs')->getBody();
        self::assertStringContainsString('Ținută vedetă pentru test', $accentless);
        $categorySearch = (string) $this->get('/admin/cupoane-promotii/?cautare=categorie%20ramura')->getBody();
        self::assertStringContainsString('Categorie pentru întreaga ramură', $categorySearch);
        self::assertStringNotContainsString('Ținută vedetă pentru test', $categorySearch);

        $storefront = (string) $this->get('/')->getBody();
        self::assertStringContainsString('class="site-announcement site-announcement--promotion"', $storefront);
        self::assertStringContainsString('Livrarea este din partea noastră astăzi', $storefront);
        self::assertStringNotContainsString('Mesaj viitor care nu trebuie afișat', $storefront);
        self::assertStringNotContainsString('Mesaj expirat care nu trebuie afișat', $storefront);
        self::assertStringNotContainsString('Mesaj inactiv care nu trebuie afișat', $storefront);

        $deactivated = $this->post('/admin/cupoane-promotii/reducere/' . $productPromotion['id'] . '/dezactiveaza/', ['_csrf' => $this->csrf]);
        self::assertSame(303, $deactivated->getStatusCode());
        self::assertSame('inactive', $this->pdo->query('SELECT status FROM promotions WHERE id=' . (int) $productPromotion['id'])->fetchColumn());
        $inactiveSearch = (string) $this->get('/admin/cupoane-promotii/?cautare=inactiva%20produs')->getBody();
        self::assertStringContainsString('Ținută vedetă pentru test', $inactiveSearch);
    }

    public function testCouponEditPrefillsValuesAndPreservesIdentityAndRedemptions(): void
    {
        $data = ['_csrf' => $this->csrf, 'code' => 'EDIT25', 'name' => 'Cupon pentru editare', 'scope' => 'order', 'value_type' => 'fixed', 'value' => '25,50', 'minimum' => '300', 'usage_limit' => '10', 'per_customer_limit' => '2', 'validity' => 'period', 'starts_at' => '2030-01-10T10:00', 'ends_at' => '2030-01-20T18:00', 'topbar_enabled' => '1', 'topbar_message' => 'Oferta pentru editare', 'topbar_priority' => '17'];
        self::assertSame(303, $this->post('/admin/cupoane-promotii/cupon/nou/', $data)->getStatusCode());
        $coupon = $this->pdo->query("SELECT * FROM coupons WHERE code='EDIT25'")->fetch(PDO::FETCH_ASSOC);
        $id = (int) $coupon['id'];
        $base = '/admin/cupoane-promotii/cupon/' . $id . '/';
        $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,order_id,email,status,created_at,updated_at) VALUES (:coupon,NULL,'edit@example.test','confirmed',:now,:now)")->execute(['coupon' => $id, 'now' => gmdate('c')]);
        $modal = $this->get($base . 'editare/modal/');
        self::assertSame(200, $modal->getStatusCode());
        $markup = (string) $modal->getBody();
        self::assertStringContainsString('Editează cuponul', $markup);
        foreach (['25,50', '300,00', '2030-01-10T10:00', '2030-01-20T18:00', 'EDIT25', '17'] as $value) {
            self::assertStringContainsString('value="' . $value . '"', $markup);
        }
        self::assertStringContainsString('action="' . $base . 'editare/"', $markup);
        $edited = [...$data, 'value' => '12.50', 'value_type' => 'percent', 'status' => 'inactive'];
        self::assertSame(200, $this->post($base . 'editare/', $edited, ['Accept' => 'application/json'])->getStatusCode());
        $saved = $this->pdo->query('SELECT * FROM coupons WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1250, (int) $saved['value']);
        self::assertSame('percent', $saved['type']);
        self::assertSame('inactive', $saved['status']);
        self::assertSame($coupon['created_at'], $saved['created_at']);
        self::assertSame($coupon['created_by_user_id'], $saved['created_by_user_id']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $id)->fetchColumn());
        self::assertSame(303, $this->post('/admin/cupoane-promotii/cupon/nou/', [...$data, 'code' => 'OTHER25'])->getStatusCode());
        self::assertSame(422, $this->post($base . 'editare/', [...$edited, 'code' => 'OTHER25'], ['Accept' => 'application/json'])->getStatusCode());
        self::assertSame('EDIT25', $this->pdo->query('SELECT code FROM coupons WHERE id=' . $id)->fetchColumn());
        $invalid = $this->post($base . 'editare/', [...$edited, 'value' => '101']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('value="101"', (string) $invalid->getBody());
        self::assertSame(1250, (int) $this->pdo->query('SELECT value FROM coupons WHERE id=' . $id)->fetchColumn());
    }

    public function testPromotionEditSupportsScopeThresholdReactivationAndValidation(): void
    {
        $id = $this->insertPromotion('Oferta de actualizat', 'shipping', 'free', 0, null, null, 'inactive', 'Anunț inițial', 5);
        $base = '/admin/cupoane-promotii/reducere/' . $id . '/';
        $modal = (string) $this->get($base . 'editare/modal/')->getBody();
        self::assertStringContainsString('Editează reducerea', $modal);
        self::assertStringContainsString('value="inactive" selected', $modal);
        self::assertStringContainsString('name="value_type" value="free" checked', $modal);
        $productId = (int) $this->pdo->query("SELECT id FROM products WHERE status='published' ORDER BY id LIMIT 1")->fetchColumn();
        $data = ['_csrf' => $this->csrf, 'name' => 'Oferta de actualizat', 'scope' => 'product', 'target_id' => $productId, 'value_type' => 'fixed', 'value' => '35,50', 'minimum_mode' => 'amount', 'minimum' => '300', 'validity' => 'always', 'status' => 'active', 'topbar_enabled' => '1', 'topbar_message' => 'Anunț actualizat', 'topbar_priority' => '5'];
        self::assertSame(200, $this->post($base . 'editare/', $data, ['Accept' => 'application/json'])->getStatusCode());
        $saved = $this->pdo->query('SELECT * FROM promotions WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('active', $saved['status']);
        self::assertSame('product', $saved['scope']);
        self::assertSame($productId, (int) $saved['target_id']);
        self::assertSame(3550, (int) $saved['value']);
        self::assertSame(30000, (int) $saved['minimum_bani']);
        self::assertStringContainsString('Anunț actualizat', (string) $this->get('/')->getBody());
        self::assertSame(422, $this->post($base . 'editare/', [...$data, 'target_id' => 999999], ['Accept' => 'application/json'])->getStatusCode());
        self::assertSame($productId, (int) $this->pdo->query('SELECT target_id FROM promotions WHERE id=' . $id)->fetchColumn());
        self::assertSame(404, $this->get('/admin/cupoane-promotii/reducere/999999/editare/modal/')->getStatusCode());
    }

    public function testConfirmationsDoNotMutateAndDeletionRetainsHistoryWithAdminAndCsrfProtection(): void
    {
        $promotionId = $this->insertPromotion('Ofertă de șters', 'shipping', 'free', 0, null, null, 'active', 'Mesaj de eliminat', 1000);
        $couponData = ['_csrf' => $this->csrf, 'code' => 'DELETE10', 'name' => 'Cupon de șters', 'scope' => 'order', 'value_type' => 'percent', 'value' => '10', 'validity' => 'always'];
        self::assertSame(303, $this->post('/admin/cupoane-promotii/cupon/nou/', $couponData)->getStatusCode());
        $couponId = (int) $this->pdo->query("SELECT id FROM coupons WHERE code='DELETE10'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,order_id,email,status,created_at,updated_at) VALUES (:coupon,NULL,'history@example.test','confirmed',:now,:now)")->execute(['coupon' => $couponId, 'now' => gmdate('c')]);
        foreach (['cupon' => $couponId, 'reducere' => $promotionId] as $type => $id) {
            $table = $type === 'cupon' ? 'coupons' : 'promotions';
            $base = '/admin/cupoane-promotii/' . $type . '/' . $id . '/';
            foreach (['dezactiveaza', 'sterge'] as $action) {
                $confirm = $this->get($base . $action . '/confirmare/');
                self::assertSame(200, $confirm->getStatusCode());
                self::assertStringContainsString('action="' . $base . $action . '/"', (string) $confirm->getBody());
                self::assertSame('active', $this->pdo->query('SELECT status FROM ' . $table . ' WHERE id=' . $id)->fetchColumn());
                self::assertSame(419, $this->post($base . $action . '/', ['_csrf' => 'invalid'])->getStatusCode());
            }
            self::assertSame(419, $this->post($base . 'editare/', ['_csrf' => 'invalid'])->getStatusCode());
            $session = $_SESSION;
            $_SESSION = [];
            self::assertSame(303, $this->get($base . 'editare/')->getStatusCode());
            self::assertSame(303, $this->get($base . 'sterge/confirmare/')->getStatusCode());
            // A valid anonymous CSRF token still cannot authorize an admin mutation.
            $this->get('/admin');
            self::assertSame(303, $this->post($base . 'sterge/', ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
            $_SESSION = $session;
            self::assertSame(303, $this->post($base . 'sterge/', ['_csrf' => $this->csrf])->getStatusCode());
            $deleted = $this->pdo->query('SELECT * FROM ' . $table . ' WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
            self::assertNotEmpty($deleted['deleted_at']);
            self::assertSame('inactive', $deleted['status']);
            self::assertSame(0, (int) $deleted['topbar_enabled']);
            self::assertSame(404, $this->get($base . 'editare/')->getStatusCode());
            self::assertSame(404, $this->get($base . 'sterge/confirmare/')->getStatusCode());
            self::assertSame(422, $this->post($base . 'editare/', $couponData, ['Accept' => 'application/json'])->getStatusCode());
            self::assertSame(422, $this->post($base . 'sterge/', ['_csrf' => $this->csrf], ['Accept' => 'application/json'])->getStatusCode());
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $couponId)->fetchColumn());
        unset($_SESSION['_flash']);
        $registry = (string) $this->get('/admin/cupoane-promotii/')->getBody();
        self::assertStringNotContainsString('Cupon de șters', $registry);
        self::assertStringNotContainsString('Ofertă de șters', $registry);
        self::assertStringNotContainsString('Mesaj de eliminat', (string) $this->get('/')->getBody());
    }

    public function testDirectReactivationPreservesRulesAndRequiresAdminAndCsrf(): void
    {
        $promotionId = $this->insertPromotion('Reducere oprită', 'shipping', 'free', 0, null, null, 'inactive', 'Livrare pentru reactivare', 10);
        self::assertSame(303, $this->post('/admin/cupoane-promotii/cupon/nou/', [
            '_csrf' => $this->csrf, 'code' => 'REACT10', 'name' => 'Cupon oprit', 'scope' => 'order', 'value_type' => 'percent', 'value' => '10',
            'minimum' => '300', 'usage_limit' => '5', 'per_customer_limit' => '2', 'validity' => 'always', 'status' => 'inactive',
        ])->getStatusCode());
        $couponId = (int) $this->pdo->query("SELECT id FROM coupons WHERE code='REACT10'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO coupon_redemptions (coupon_id,order_id,email,status,created_at,updated_at) VALUES (:coupon,NULL,'reactivate@example.test','confirmed',:now,:now)")->execute(['coupon' => $couponId, 'now' => gmdate('c')]);
        foreach (['cupon' => [$couponId, 'Cupon oprit'], 'reducere' => [$promotionId, 'Reducere oprită']] as $type => [$id, $name]) {
            $table = $type === 'cupon' ? 'coupons' : 'promotions';
            $base = '/admin/cupoane-promotii/' . $type . '/' . $id . '/';
            $original = $this->pdo->query('SELECT * FROM ' . $table . ' WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
            self::assertStringContainsString('aria-label="Reactivează ' . $name . '"', (string) $this->get('/admin/cupoane-promotii/')->getBody());
            $confirm = $this->get($base . 'reactiveaza/confirmare/');
            self::assertSame(200, $confirm->getStatusCode());
            self::assertStringContainsString('Da, reactivează', (string) $confirm->getBody());
            self::assertStringContainsString('action="' . $base . 'reactiveaza/"', (string) $confirm->getBody());
            self::assertSame('inactive', $this->pdo->query('SELECT status FROM ' . $table . ' WHERE id=' . $id)->fetchColumn());
            self::assertSame(419, $this->post($base . 'reactiveaza/', ['_csrf' => 'invalid'])->getStatusCode());
            $session = $_SESSION;
            $_SESSION = [];
            $this->get('/admin');
            self::assertSame(303, $this->get($base . 'reactiveaza/confirmare/')->getStatusCode());
            self::assertSame(303, $this->post($base . 'reactiveaza/', ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
            $_SESSION = $session;
            self::assertSame(200, $this->post($base . 'reactiveaza/', ['_csrf' => $this->csrf], ['Accept' => 'application/json'])->getStatusCode());
            $saved = $this->pdo->query('SELECT * FROM ' . $table . ' WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('active', $saved['status']);
            unset($original['status'], $original['updated_at'], $saved['status'], $saved['updated_at']);
            self::assertSame($original, $saved);
            $registry = (string) $this->get('/admin/cupoane-promotii/')->getBody();
            self::assertStringNotContainsString('aria-label="Reactivează ' . $name . '"', $registry);
            self::assertStringContainsString('aria-label="Dezactivează ' . $name . '"', $registry);
            self::assertSame(422, $this->post($base . 'reactiveaza/', ['_csrf' => $this->csrf], ['Accept' => 'application/json'])->getStatusCode());
            self::assertSame(303, $this->post($base . 'dezactiveaza/', ['_csrf' => $this->csrf])->getStatusCode());
            self::assertSame(303, $this->post($base . 'reactiveaza/', ['_csrf' => $this->csrf])->getStatusCode());
            self::assertSame(303, $this->post($base . 'sterge/', ['_csrf' => $this->csrf])->getStatusCode());
            self::assertSame(422, $this->post($base . 'reactiveaza/', ['_csrf' => $this->csrf], ['Accept' => 'application/json'])->getStatusCode());
            self::assertSame('inactive', $this->pdo->query('SELECT status FROM ' . $table . ' WHERE id=' . $id)->fetchColumn());
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=' . $couponId)->fetchColumn());
        foreach (['-2 days' => '-1 day', '+1 day' => '+2 days'] as $start => $end) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $endsAt = $now->modify($end)->format('c');
            $id = $this->insertPromotion('Perioadă păstrată', 'order', 'percent', 1000, $now->modify($start)->format('c'), $endsAt, 'inactive', 'Mesaj în afara perioadei', 1000);
            self::assertSame(303, $this->post('/admin/cupoane-promotii/reducere/' . $id . '/reactiveaza/', ['_csrf' => $this->csrf])->getStatusCode());
            self::assertSame($endsAt, $this->pdo->query('SELECT ends_at FROM promotions WHERE id=' . $id)->fetchColumn());
            unset($_SESSION['_flash']);
            self::assertStringNotContainsString('Mesaj în afara perioadei', (string) $this->get('/')->getBody());
        }
    }

    private function insertPromotion(
        string $name,
        string $scope,
        string $valueType,
        int $value,
        ?string $startsAt,
        ?string $endsAt,
        string $status,
        string $message,
        int $priority,
    ): int {
        $now = gmdate('c');
        $statement = $this->pdo->prepare('INSERT INTO promotions (name,scope,value_type,value,target_id,starts_at,ends_at,status,topbar_enabled,topbar_message,topbar_priority,created_by_user_id,created_at,updated_at,deleted_at) VALUES (:name,:scope,:value_type,:value,NULL,:starts,:ends,:status,1,:message,:priority,:admin,:now,:now,NULL)');
        $statement->execute([
            'name' => $name,
            'scope' => $scope,
            'value_type' => $valueType,
            'value' => $value,
            'starts' => $startsAt,
            'ends' => $endsAt,
            'status' => $status,
            'message' => $message,
            'priority' => $priority,
            'admin' => $this->adminId,
            'now' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
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
            ->withHeader('User-Agent', 'LaMariuca-PromotionAdmin-Test/1.0');
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
