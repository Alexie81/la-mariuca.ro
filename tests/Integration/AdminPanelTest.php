<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Shared\Security\AdminRememberMe;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class AdminPanelTest extends DatabaseTestCase
{
    private const PASSWORD = 'Admin-password-2026!';
    private const USER_AGENT = 'LaMariuca-AdminPanel-Test/1.0';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('admin.panel@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $customer = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('client.panel@example.test',:password,'Ana','Client','customer','active',:now,:now,:now)");
        $customer->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
    }

    public function testAdminEntryRendersDedicatedResponsiveLogin(): void
    {
        $body = (string) $this->get('/admin')->getBody();

        self::assertStringContainsString('<body class="admin-login-body">', $body);
        self::assertStringContainsString('action="/admin"', $body);
        self::assertStringContainsString('name="remember"', $body);
        self::assertStringContainsString('Ține-mă minte', $body);
        self::assertStringContainsString('assets/js/admin.js', $body);
        self::assertStringNotContainsString('admin/produse/', $body);
    }

    public function testCustomerCredentialsCannotEnterAdmin(): void
    {
        $this->get('/admin');
        $response = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'client.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.81');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testAdminLoginDashboardAndRememberMeRotation(): void
    {
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
            'remember' => '1',
        ], '127.0.0.82');

        self::assertSame(303, $login->getStatusCode());
        self::assertSame('/admin', $login->getHeaderLine('Location'));
        $cookie = $this->rememberCookie($login);
        self::assertNotSame('', $cookie);
        self::assertStringContainsString('HttpOnly', implode(';', $login->getHeader('Set-Cookie')));
        self::assertStringContainsString('SameSite=Strict', implode(';', $login->getHeader('Set-Cookie')));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_remember_tokens')->fetchColumn());

        $_SESSION = [];
        $dashboard = $this->request('GET', '/admin', [], '127.0.0.82', [AdminRememberMe::COOKIE_NAME => $cookie]);
        $body = (string) $dashboard->getBody();

        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<body class="admin-body">', $body);
        self::assertStringContainsString('Prezentare <em>generală</em>', $body);
        self::assertStringContainsString('Săptămâna curentă', $body);
        self::assertStringContainsString('Vânzări nete', $body);
        self::assertStringContainsString('Comenzi recente', $body);
        self::assertStringContainsString('data-count-up=', $body);
        self::assertStringContainsString('data-money-count-up=', $body);
        self::assertStringContainsString('data-chart-bar', $body);
        self::assertStringContainsString('--bar-scale:0', $body);
        self::assertStringContainsString('data-chart-bar class="is-zero"', $body);
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringContainsString('Produse și clienți', $body);
        self::assertStringContainsString('href="/admin/produse/"', $body);
        self::assertStringContainsString('Catalog, preț și stoc', $body);
        self::assertArrayHasKey('user_id', $_SESSION);
        self::assertNotSame($cookie, $this->rememberCookie($dashboard), 'Tokenul persistent trebuie rotit la restaurarea sesiunii.');
    }

    public function testProductsRegistryUsesInventorySearchAndActiveNavigation(): void
    {
        $this->seed();
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.86');
        self::assertSame(303, $login->getStatusCode());

        $this->pdo->exec("UPDATE product_variants SET price_bani=10710,compare_price_bani=11900,discount_type='percent',discount_value=1000 WHERE sku='LM-NB-001'");
        $body = (string) $this->get('/admin/produse/')->getBody();
        self::assertStringContainsString('Registrul catalogului', $body);
        self::assertStringContainsString('Stoc online', $body);
        self::assertStringContainsString('Stoc efectiv (Conta)', $body);
        self::assertStringContainsString('href="/admin/produse/" aria-current="page"', $body);
        self::assertStringContainsString('LM-NB-001', $body);
        self::assertStringContainsString('107,10 Lei', $body);
        self::assertStringContainsString('119,00 Lei', $body);
        self::assertStringContainsString('data-discount-type="percent"', $body);
        self::assertStringContainsString('10%', $body);
        self::assertStringContainsString('admin-products-table__compare-price', $body);
        self::assertStringContainsString('8 buc.', $body);
        self::assertStringContainsString('/sterge/', $body);
        self::assertStringContainsString('data-admin-product-delete-dialog', $body);
        self::assertStringContainsString('data-admin-product-editor-dialog', $body);
        self::assertStringContainsString('data-admin-product-discard-layer', $body);
        self::assertStringContainsString('data-admin-product-discard-stay', $body);
        self::assertStringContainsString('data-admin-product-discard-leave', $body);
        self::assertStringContainsString('data-admin-product-editor-trigger', $body);
        self::assertStringContainsString('/editare/modal/', $body);
        self::assertStringNotContainsString('1 variantă', $body);

        $search = (string) $this->get('/admin/produse/?cautare=LM-SH-002')->getBody();
        self::assertStringContainsString('Ghete „Explorator”', $search);
        self::assertStringNotContainsString('Set nou-născut „Primul dar”', $search);
        self::assertStringContainsString('1 produs relevant', $search);

        $form = (string) $this->get('/admin/produse/nou/')->getBody();
        self::assertStringContainsString('href="/admin/produse/" aria-current="page"', $form);
        self::assertStringContainsString('Produs nou', $form);

        $newModal = $this->get('/admin/produse/nou/modal/');
        self::assertSame(200, $newModal->getStatusCode());
        self::assertStringContainsString('Adaugă o poveste nouă', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-product-form', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-name-input', (string) $newModal->getBody());
        self::assertStringContainsString('/admin/produse/verifica-nume/', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-sku-input', (string) $newModal->getBody());
        self::assertStringContainsString('/admin/produse/verifica-sku/', (string) $newModal->getBody());
        self::assertStringContainsString('name="variants[0][sku]" value=""', (string) $newModal->getBody());
        self::assertStringContainsString('placeholder="Se generează automat"', (string) $newModal->getBody());
        self::assertStringContainsString('Opțiunile produsului', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-options-editor', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-variants-editor', (string) $newModal->getBody());
        self::assertStringContainsString('Slug (numele linkului)', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-slug-input', (string) $newModal->getBody());
        self::assertStringContainsString('/admin/produse/verifica-slug/', (string) $newModal->getBody());
        self::assertStringContainsString('placeholder="0,00"', (string) $newModal->getBody());
        self::assertStringContainsString('<option value="published" selected>Publicat</option>', (string) $newModal->getBody());
        self::assertStringContainsString('Categorii <b>*</b>', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-product-save-status-label>Up to date</span>', (string) $newModal->getBody());
        self::assertStringNotContainsString('Categoria principală', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-category-picker', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-category-search', (string) $newModal->getBody());
        self::assertStringContainsString('data-admin-category-done aria-label="Închide selectorul de categorii"', (string) $newModal->getBody());
        self::assertStringNotContainsString('data-admin-category-done>Gata', (string) $newModal->getBody());
        self::assertStringContainsString('name="category_ids[]"', (string) $newModal->getBody());
        self::assertStringNotContainsString('Subcategorie · nivel', (string) $newModal->getBody());
        self::assertStringContainsString('name="short_description" rows="3" maxlength="200"', (string) $newModal->getBody());
        self::assertStringContainsString('data-product-short-description-count>0</strong>/200 caractere', (string) $newModal->getBody());

        $categoryIds = array_map('intval', $this->pdo->query('SELECT id FROM categories ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(2, $categoryIds);
        $categoryId = $categoryIds[0];
        $secondaryCategoryId = $categoryIds[1];
        $automaticMetaDescription = 'O descriere scurtă completată automat în prezentarea Google, păstrată integral chiar și atunci când depășește recomandarea de o sută șaizeci de caractere, fără nicio tăiere.';
        self::assertGreaterThan(160, mb_strlen($automaticMetaDescription));
        $created = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs creat din modal',
            'short_description' => $automaticMetaDescription,
            'sku' => 'LM-MODAL-001',
            'price' => '149,90',
            'stock' => '4',
            'category_ids' => [(string) $categoryId, (string) $secondaryCategoryId],
            'status' => 'draft',
            'featured' => 'on',
            'indexable' => 'on',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $created->getHeaderLine('Content-Type'));
        $createdPayload = json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($createdPayload);
        self::assertTrue((bool) $createdPayload['ok']);
        $createdId = (int) $createdPayload['id'];
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku='LM-MODAL-001'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT p.featured FROM products p JOIN product_variants v ON v.product_id=p.id WHERE v.sku='LM-MODAL-001'")->fetchColumn());
        self::assertSame($categoryId, (int) $this->pdo->query('SELECT primary_category_id FROM products WHERE id=' . $createdId)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM product_categories WHERE product_id=' . $createdId)->fetchColumn());
        $createdSeo = $this->pdo->query('SELECT name,short_description,seo_title,meta_description FROM products WHERE id=' . $createdId)->fetch();
        self::assertIsArray($createdSeo);
        self::assertSame($createdSeo['name'], $createdSeo['seo_title']);
        self::assertSame($automaticMetaDescription, $createdSeo['short_description']);
        self::assertSame($automaticMetaDescription, $createdSeo['meta_description']);
        $createdEditModal = (string) $this->get('/admin/produse/' . $createdId . '/editare/modal/')->getBody();
        self::assertMatchesRegularExpression('/name="category_ids\[\]" value="' . $categoryId . '" checked/', $createdEditModal);
        self::assertMatchesRegularExpression('/name="category_ids\[\]" value="' . $secondaryCategoryId . '" checked/', $createdEditModal);
        $createdVariantId = (int) $this->pdo->query("SELECT id FROM product_variants WHERE sku='LM-MODAL-001'")->fetchColumn();

        $duplicateAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=PRODUS%20CREAT%20DIN%20MODAL')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicateAvailability['available']);
        self::assertSame('Acest titlu există deja.', $duplicateAvailability['message']);
        $ownAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=Produs%20creat%20din%20modal&exclude_id=' . $createdId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $ownAvailability['available']);
        $newAvailability = json_decode((string) $this->get('/admin/produse/verifica-nume/?nume=Titlu%20complet%20nou')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $newAvailability['available']);

        $duplicateSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=lm-modal-001')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicateSkuAvailability['available']);
        self::assertSame('Acest SKU este deja folosit.', $duplicateSkuAvailability['message']);
        $ownSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=LM-MODAL-001&exclude_variant_id=' . $createdVariantId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $ownSkuAvailability['available']);
        $newSkuAvailability = json_decode((string) $this->get('/admin/produse/verifica-sku/?sku=LM-MODAL-LIBER')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $newSkuAvailability['available']);

        $duplicateSlugAvailability = json_decode((string) $this->get('/admin/produse/verifica-slug/?slug=PRODUS%20CREAT%20DIN%20MODAL')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicateSlugAvailability['available']);
        self::assertSame('produs-creat-din-modal', $duplicateSlugAvailability['slug']);
        self::assertSame('Acest nume de link există deja.', $duplicateSlugAvailability['message']);
        $ownSlugAvailability = json_decode((string) $this->get('/admin/produse/verifica-slug/?slug=produs-creat-din-modal&exclude_id=' . $createdId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $ownSlugAvailability['available']);
        $newSlugAvailability = json_decode((string) $this->get('/admin/produse/verifica-slug/?slug=Rochiță%20Nouă%20Maria')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) $newSlugAvailability['available']);
        self::assertSame('rochita-noua-maria', $newSlugAvailability['slug']);

        $duplicateSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => '  PRODUS creat din modal  ',
            'sku' => 'LM-MODAL-DUPLICAT',
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicateSave->getStatusCode());
        $duplicatePayload = json_decode((string) $duplicateSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $duplicatePayload['ok']);
        self::assertStringContainsString('Acest titlu există deja', (string) $duplicatePayload['message']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku='LM-MODAL-DUPLICAT'")->fetchColumn());

        $longDescriptionSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu descriere prea lungă',
            'sku' => 'LM-DESCRIERE-LUNGA',
            'short_description' => str_repeat('ă', 201),
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $longDescriptionSave->getStatusCode());
        $longDescriptionPayload = json_decode((string) $longDescriptionSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) $longDescriptionPayload['ok']);
        self::assertStringContainsString('maximum 200 de caractere', (string) $longDescriptionPayload['message']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE sku='LM-DESCRIERE-LUNGA'")->fetchColumn());

        $duplicateSkuSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu SKU duplicat',
            'sku' => 'lm-modal-001',
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicateSkuSave->getStatusCode());
        $duplicateSkuPayload = json_decode((string) $duplicateSkuSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Acest SKU există deja', (string) $duplicateSkuPayload['message']);

        $duplicateSlugSave = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu link duplicat',
            'slug' => 'PRODUS CREAT DIN MODAL',
            'sku' => 'LM-MODAL-SLUG-DUPLICAT',
            'price' => '129,00',
            'stock' => '2',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicateSlugSave->getStatusCode());
        $duplicateSlugPayload = json_decode((string) $duplicateSlugSave->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Acest nume de link există deja', (string) $duplicateSlugPayload['message']);

        $autoSkuCreated = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu SKU automat',
            'short_description' => 'Descriere scurtă pentru produsul cu valori SEO manuale.',
            'seo_title' => 'Titlu Google scris manual',
            'meta_description' => 'Descriere Google scrisă manual.',
            'slug' => '',
            'sku' => '',
            'price' => '119,00',
            'stock' => '1',
            'category_id' => (string) $categoryId,
            'status' => 'draft',
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(200, $autoSkuCreated->getStatusCode());
        $autoSkuPayload = json_decode((string) $autoSkuCreated->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $autoSku = (string) $this->pdo->query('SELECT sku FROM product_variants WHERE product_id=' . (int) $autoSkuPayload['id'])->fetchColumn();
        self::assertMatchesRegularExpression('/^LM-\d{2}-\d{3,}$/', $autoSku);
        self::assertSame('produs-cu-sku-automat', (string) $this->pdo->query('SELECT slug FROM products WHERE id=' . (int) $autoSkuPayload['id'])->fetchColumn());
        $manualSeo = $this->pdo->query('SELECT seo_title,meta_description FROM products WHERE id=' . (int) $autoSkuPayload['id'])->fetch();
        self::assertIsArray($manualSeo);
        self::assertSame('Titlu Google scris manual', $manualSeo['seo_title']);
        self::assertSame('Descriere Google scrisă manual.', $manualSeo['meta_description']);

        $variantCreated = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs ghidat cu variante',
            'category_ids' => [(string) $categoryId],
            'status' => 'published',
            'option_groups' => [
                ['name' => 'Mărime', 'values' => ['3-4 ani', '4-5 ani']],
                ['name' => 'Culoare', 'values' => ['Roșu', 'Albastru']],
            ],
            'variants' => [
                [
                    'options_json' => json_encode(['Mărime' => '3-4 ani', 'Culoare' => 'Roșu'], JSON_UNESCAPED_UNICODE),
                    'sku' => '', 'ean' => '5940000000001', 'full_price' => '150,00', 'stock' => '4',
                    'stock_unlimited' => '0', 'track_in_conta' => '1', 'discount_enabled' => '1',
                    'discount_type' => 'percent', 'discount_value' => '10',
                ],
                [
                    'options_json' => json_encode(['Mărime' => '3-4 ani', 'Culoare' => 'Albastru'], JSON_UNESCAPED_UNICODE),
                    'sku' => '', 'ean' => '5940000000002', 'full_price' => '150,00', 'stock' => '0',
                    'stock_unlimited' => '1', 'track_in_conta' => '0', 'discount_enabled' => '1',
                    'discount_type' => 'fixed', 'discount_value' => '20,00',
                ],
                [
                    'options_json' => json_encode(['Mărime' => '4-5 ani', 'Culoare' => 'Roșu'], JSON_UNESCAPED_UNICODE),
                    'sku' => 'LM-GHIDAT-ROSU', 'ean' => '', 'full_price' => '155,00', 'stock' => '3',
                    'stock_unlimited' => '0', 'track_in_conta' => '1', 'discount_enabled' => '0',
                ],
                [
                    'options_json' => json_encode(['Mărime' => '4-5 ani', 'Culoare' => 'Albastru'], JSON_UNESCAPED_UNICODE),
                    'sku' => '', 'ean' => '', 'full_price' => '160,00', 'stock' => '2',
                    'stock_unlimited' => '0', 'track_in_conta' => '1', 'discount_enabled' => '0',
                ],
            ],
        ], '127.0.0.86', [], ['Accept' => 'application/json']);
        self::assertSame(200, $variantCreated->getStatusCode(), (string) $variantCreated->getBody());
        $variantPayload = json_decode((string) $variantCreated->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $variantProductId = (int) $variantPayload['id'];
        $savedVariants = $this->pdo->query('SELECT * FROM product_variants WHERE product_id=' . $variantProductId . " AND status='active' ORDER BY is_default DESC,id")->fetchAll();
        self::assertCount(4, $savedVariants);
        self::assertSame(13500, (int) $savedVariants[0]['price_bani']);
        self::assertSame(15000, (int) $savedVariants[0]['compare_price_bani']);
        self::assertSame('percent', $savedVariants[0]['discount_type']);
        self::assertSame(1000, (int) $savedVariants[0]['discount_value']);
        self::assertSame(13000, (int) $savedVariants[1]['price_bani']);
        self::assertSame('fixed', $savedVariants[1]['discount_type']);
        self::assertSame(2000, (int) $savedVariants[1]['discount_value']);
        self::assertSame(1, (int) $savedVariants[1]['stock_unlimited']);
        self::assertSame(0, (int) $savedVariants[1]['track_in_conta']);
        self::assertSame(4, count(array_unique(array_column($savedVariants, 'sku'))));
        $variantEditModal = (string) $this->get('/admin/produse/' . $variantProductId . '/editare/modal/')->getBody();
        self::assertStringContainsString('2 alegeri configurate', $variantEditModal);
        self::assertStringContainsString('4 combinații', $variantEditModal);
        self::assertStringContainsString('Sumă fixă (lei)', $variantEditModal);
        self::assertStringContainsString('Preț final', $variantEditModal);

        $product = $this->pdo->query("SELECT p.id,p.name FROM products p JOIN product_variants v ON v.product_id=p.id WHERE v.sku='LM-SH-002' LIMIT 1")->fetch();
        self::assertIsArray($product);
        $editModal = $this->get('/admin/produse/' . $product['id'] . '/editare/modal/');
        self::assertSame(200, $editModal->getStatusCode());
        self::assertStringContainsString('Finisează produsul', (string) $editModal->getBody());
        self::assertStringContainsString('LM-SH-002', (string) $editModal->getBody());
        self::assertStringContainsString('data-variant-id=', (string) $editModal->getBody());
        self::assertStringContainsString('data-product-slug-input', (string) $editModal->getBody());
        self::assertStringContainsString('data-slug-autofill="false"', (string) $editModal->getBody());
        $deleted = $this->post('/admin/produse/' . $product['id'] . '/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
        ], '127.0.0.86');
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/produse/', $deleted->getHeaderLine('Location'));
        self::assertSame('archived', $this->pdo->query('SELECT status FROM products WHERE id=' . (int) $product['id'])->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id=" . (int) $product['id'] . " AND status='active'")->fetchColumn());
        self::assertStringNotContainsString('/admin/produse/' . $product['id'] . '/editare/', (string) $this->get('/admin/produse/')->getBody());
    }

    public function testAdminLogoutRevokesRememberToken(): void
    {
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
            'remember' => '1',
        ], '127.0.0.83');
        $cookie = $this->rememberCookie($login);

        $logout = $this->request('POST', '/admin/deconectare/', ['_csrf' => $_SESSION['_csrf']], '127.0.0.83', [AdminRememberMe::COOKIE_NAME => $cookie]);

        self::assertSame(303, $logout->getStatusCode());
        self::assertSame('/admin', $logout->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_remember_tokens')->fetchColumn());
        self::assertStringContainsString('Max-Age=0', implode(';', $logout->getHeader('Set-Cookie')));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testPrimaryCommerceDataSurvivesVariantTransitions(): void
    {
        $this->seed();
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.89');
        self::assertSame(303, $login->getStatusCode());

        $categoryId = (int) $this->pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
        $created = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu identitate comercială stabilă',
            'primary_sku' => 'LM-PARINTE-STABIL',
            'category_ids' => [(string) $categoryId],
            'status' => 'published',
            'track_in_conta' => '0',
            'variants' => [[
                'options_json' => '{}',
                'sku' => '',
                'ean' => 'EAN liber / furnizor 2026',
                'full_price' => '159,00',
                'stock' => '12',
                'stock_unlimited' => '0',
                'discount_enabled' => '0',
            ]],
        ], '127.0.0.89', [], ['Accept' => 'application/json']);
        self::assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $productId = (int) json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];

        $product = $this->pdo->query('SELECT primary_sku,track_in_conta FROM products WHERE id=' . $productId)->fetch();
        self::assertSame('LM-PARINTE-STABIL', $product['primary_sku']);
        self::assertSame(0, (int) $product['track_in_conta']);
        $simpleVariant = $this->pdo->query("SELECT id,sku,ean,track_in_conta FROM product_variants WHERE product_id={$productId} AND status='active'")->fetch();
        self::assertSame('LM-PARINTE-STABIL', $simpleVariant['sku']);
        self::assertSame('EAN liber / furnizor 2026', $simpleVariant['ean']);
        self::assertSame(0, (int) $simpleVariant['track_in_conta']);

        $withOptions = $this->request('POST', '/admin/produse/' . $productId . '/editare/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu identitate comercială stabilă',
            'slug' => 'produs-cu-identitate-comerciala-stabila',
            'primary_sku' => 'LM-PARINTE-STABIL',
            'category_ids' => [(string) $categoryId],
            'status' => 'published',
            'track_in_conta' => '1',
            'option_groups' => [['name' => 'Mărime', 'values' => ['Mică', 'Mare']]],
            'variants' => [
                [
                    'id' => (string) $simpleVariant['id'],
                    'options_json' => json_encode(['Mărime' => 'Mică'], JSON_UNESCAPED_UNICODE),
                    // Simulează produsul vechi al cărui SKU implicit a devenit SKU principal.
                    'sku' => 'LM-PARINTE-STABIL', 'ean' => '', 'full_price' => '159,00', 'stock' => '5',
                    'stock_unlimited' => '0', 'discount_enabled' => '0',
                ],
                [
                    'options_json' => json_encode(['Mărime' => 'Mare'], JSON_UNESCAPED_UNICODE),
                    'sku' => '', 'ean' => '', 'full_price' => '169,00', 'stock' => '4',
                    'stock_unlimited' => '0', 'discount_enabled' => '0',
                ],
            ],
        ], '127.0.0.89', [], ['Accept' => 'application/json']);
        self::assertSame(200, $withOptions->getStatusCode(), (string) $withOptions->getBody());
        self::assertSame(1, (int) $this->pdo->query('SELECT track_in_conta FROM products WHERE id=' . $productId)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id={$productId} AND status='active'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT SUM(track_in_conta) FROM product_variants WHERE product_id={$productId} AND status='active'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id={$productId} AND status='active' AND LOWER(sku)=LOWER('LM-PARINTE-STABIL')")->fetchColumn());

        $activeVariantId = (int) $this->pdo->query("SELECT id FROM product_variants WHERE product_id={$productId} AND status='active' ORDER BY is_default DESC,id LIMIT 1")->fetchColumn();
        $withoutOptions = $this->request('POST', '/admin/produse/' . $productId . '/editare/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu identitate comercială stabilă',
            'slug' => 'produs-cu-identitate-comerciala-stabila',
            'primary_sku' => 'LM-PARINTE-STABIL',
            'category_ids' => [(string) $categoryId],
            'status' => 'published',
            'track_in_conta' => '0',
            'variants' => [[
                'id' => (string) $activeVariantId,
                'options_json' => '{}',
                'sku' => '', 'ean' => 'cod fără limită numerică', 'full_price' => '159,00', 'stock' => '9',
                'stock_unlimited' => '0', 'discount_enabled' => '0',
            ]],
        ], '127.0.0.89', [], ['Accept' => 'application/json']);
        self::assertSame(200, $withoutOptions->getStatusCode(), (string) $withoutOptions->getBody());
        $restored = $this->pdo->query("SELECT sku,ean,track_in_conta FROM product_variants WHERE product_id={$productId} AND status='active'")->fetch();
        self::assertSame('LM-PARINTE-STABIL', $restored['sku']);
        self::assertSame('cod fără limită numerică', $restored['ean']);
        self::assertSame(0, (int) $restored['track_in_conta']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id={$productId} AND status='active'")->fetchColumn());

        $modal = (string) $this->get('/admin/produse/' . $productId . '/editare/modal/')->getBody();
        self::assertMatchesRegularExpression('/name="primary_sku" value="LM-PARINTE-STABIL"/', $modal);
        self::assertMatchesRegularExpression('/name="track_in_conta" value="1"(?! checked)/', $modal);
        self::assertMatchesRegularExpression('/data-product-parent-track(?![^>]*hidden)/', $modal);
        self::assertStringContainsString('Urmărește SKU-ul principal în Conta', $modal);
        self::assertStringContainsString('Folosește SKU-ul acestei combinații în Conta', $modal);
        self::assertStringContainsString('Stocul nu se scade de două ori.', $modal);
        self::assertStringNotContainsString('Între 8 și 14 cifre', $modal);
        self::assertLessThan(
            strpos($modal, 'data-product-variants-notice'),
            strpos($modal, 'admin-product-commerce__overview'),
            'Starea și selecția „Ales cu drag” trebuie să apară imediat sub antetul secțiunii comerciale.'
        );
        self::assertStringContainsString('data-product-global-discount', $modal);
        self::assertStringContainsString('Aplică tuturor variantelor', $modal);
        self::assertTrue(
            strpos($modal, 'data-product-variants-editor') < strpos($modal, 'data-product-global-discount')
            && strpos($modal, 'data-product-global-discount') < strpos($modal, 'product-section-story'),
            'Reducerea generală trebuie să apară imediat după secțiunea „Preț, stoc și stare”.'
        );
    }

    public function testOrdersRegistryIsPaginatedAndShowsOperationalColumns(): void
    {
        $this->seedOrders(16);
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.84');
        self::assertSame(303, $login->getStatusCode());

        $firstPage = (string) $this->get('/admin/comenzi/')->getBody();
        self::assertStringContainsString('Registrul comenzilor', $firstPage);
        self::assertStringContainsString('LM-TEST-016', $firstPage);
        self::assertStringContainsString('client16@example.test', $firstPage);
        self::assertStringContainsString('0712 000 016', $firstPage);
        self::assertStringContainsString('Card online', $firstPage);
        self::assertStringContainsString('Plătită', $firstPage);
        self::assertStringContainsString('Livrată', $firstPage);
        self::assertStringContainsString('admin/comenzi/?pagina=2', $firstPage);
        self::assertStringContainsString('href="/admin/comenzi/" aria-current="page"', $firstPage);
        self::assertStringNotContainsString('<option value="manual_review"', $firstPage);
        self::assertStringNotContainsString('LM-TEST-001</a>', $firstPage);

        $secondPage = (string) $this->get('/admin/comenzi/?pagina=2')->getBody();
        self::assertStringContainsString('LM-TEST-001', $secondPage);
        self::assertStringNotContainsString('LM-TEST-016</a>', $secondPage);
        self::assertStringContainsString('aria-current="page">2</a>', $secondPage);

        $semanticPayment = (string) $this->get('/admin/comenzi/?cautare=platita')->getBody();
        self::assertStringContainsString('LM-TEST-016', $semanticPayment);
        self::assertStringNotContainsString('LM-TEST-015</a>', $semanticPayment);

        $productSearch = (string) $this->get('/admin/comenzi/?cautare=Cuburi%20Poveste')->getBody();
        self::assertStringContainsString('LM-TEST-016', $productSearch);
        self::assertStringContainsString('1 rezultat relevant', $productSearch);

        $statusFilter = (string) $this->get('/admin/comenzi/?status=delivered')->getBody();
        self::assertStringContainsString('LM-TEST-016', $statusFilter);
        self::assertStringNotContainsString('LM-TEST-015</a>', $statusFilter);

        $searchPagination = (string) $this->get('/admin/comenzi/?cautare=client')->getBody();
        self::assertStringContainsString('pagina=2&amp;cautare=client', $searchPagination);
    }

    public function testNotificationCenterSupportsFiltersReadsAndPreferences(): void
    {
        $this->seedOrders(2);
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'admin.panel@example.test',
            'password' => self::PASSWORD,
        ], '127.0.0.85');
        self::assertSame(303, $login->getStatusCode());

        $page = (string) $this->get('/admin/notificari/')->getBody();
        self::assertStringContainsString('Notificări <em>care contează</em>', $page);
        self::assertStringContainsString('Comandă nouă LM-TEST-002', $page);
        self::assertStringContainsString('Plată confirmată · LM-TEST-002', $page);
        self::assertStringContainsString('href="/admin/notificari/" aria-current="page"', $page);
        self::assertStringContainsString('Marchează citite', $page);
        self::assertStringContainsString('Șterge toate', $page);
        self::assertStringContainsString('aria-label="Șterge notificarea"', $page);
        self::assertStringContainsString('name="comenzi"', $page);

        $adminId = (int) $this->pdo->query("SELECT id FROM users WHERE email = 'admin.panel@example.test'")->fetchColumn();
        $orderId = (int) $this->pdo->query("SELECT id FROM orders WHERE order_number = 'LM-TEST-002'")->fetchColumn();
        $read = $this->post('/admin/notificari/citeste/', [
            '_csrf' => $_SESSION['_csrf'],
            'notification_key' => 'order:' . $orderId . ':created',
            'filtru' => 'comenzi',
            'necitite' => '1',
        ], '127.0.0.85');

        self::assertSame(303, $read->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=comenzi&necitite=1', $read->getHeaderLine('Location'));
        $readCount = $this->pdo->query(
            'SELECT COUNT(*) FROM admin_notification_reads WHERE admin_user_id = ' . $adminId
        )->fetchColumn();
        self::assertSame(1, (int) $readCount);

        $deleted = $this->post('/admin/notificari/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
            'notification_key' => 'order:' . $orderId . ':created',
            'filtru' => 'comenzi',
        ], '127.0.0.85');
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=comenzi', $deleted->getHeaderLine('Location'));
        $dismissedCount = $this->pdo->query(
            'SELECT COUNT(*) FROM admin_notification_dismissals WHERE admin_user_id = ' . $adminId
        )->fetchColumn();
        self::assertSame(1, (int) $dismissedCount);
        $ordersAfterDelete = (string) $this->get('/admin/notificari/?filtru=comenzi')->getBody();
        self::assertStringNotContainsString('Comandă nouă LM-TEST-002', $ordersAfterDelete);

        $deletePayments = $this->post('/admin/notificari/sterge/', [
            '_csrf' => $_SESSION['_csrf'],
            'all' => '1',
            'filtru' => 'plati',
        ], '127.0.0.85');
        self::assertSame(303, $deletePayments->getStatusCode());
        self::assertSame('/admin/notificari/?filtru=plati', $deletePayments->getHeaderLine('Location'));
        $paymentsAfterDelete = (string) $this->get('/admin/notificari/?filtru=plati')->getBody();
        self::assertStringNotContainsString('Plată confirmată · LM-TEST-002', $paymentsAfterDelete);
        self::assertStringContainsString('Totul este la zi', $paymentsAfterDelete);

        $preferences = $this->post('/admin/notificari/preferinte/', [
            '_csrf' => $_SESSION['_csrf'],
            'comenzi' => '1',
            'stoc' => '1',
        ], '127.0.0.85');
        self::assertSame(303, $preferences->getStatusCode());

        $saved = $this->pdo->query(
            'SELECT orders_enabled, payments_enabled, stock_enabled, reviews_enabled '
            . 'FROM admin_notification_preferences WHERE admin_user_id = ' . $adminId
        )->fetch();
        self::assertSame(1, (int) $saved['orders_enabled']);
        self::assertSame(0, (int) $saved['payments_enabled']);
        self::assertSame(1, (int) $saved['stock_enabled']);
        self::assertSame(0, (int) $saved['reviews_enabled']);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, mixed> $data */
    private function post(string $path, array $data, string $ip): ResponseInterface
    {
        return $this->request('POST', $path, $data, $ip);
    }

    /** @param array<string, mixed> $data @param array<string, string> $cookies @param array<string, string> $headers */
    private function request(string $method, string $path, array $data = [], string $ip = '127.0.0.80', array $cookies = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withCookieParams($cookies);
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

    private function rememberCookie(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/(?:^|;\s*)' . preg_quote(AdminRememberMe::COOKIE_NAME, '/') . '=([^;]*)/', $header, $matches) === 1) {
                return rawurldecode($matches[1]);
            }
        }

        return '';
    }

    private function seedOrders(int $count): void
    {
        $cart = $this->pdo->prepare(
            'INSERT INTO carts (token,user_id,status,coupon_code,created_at,updated_at,expires_at) '
            . "VALUES (:token,NULL,'converted',NULL,:created,:created,:expires)"
        );
        $order = $this->pdo->prepare(
            'INSERT INTO orders (order_number,public_token_hash,idempotency_key,cart_id,user_id,email,first_name,last_name,phone,customer_type,company_json,shipping_address_json,billing_address_json,subtotal_bani,discount_bani,shipping_bani,tax_bani,total_bani,currency,status,payment_status,payment_method,shipping_method,internal_notes,terms_accepted_at,marketing_consent,created_at,updated_at) '
            . "VALUES (:number,:token_hash,:idempotency,:cart,NULL,:email,:first_name,:last_name,:phone,'person','{}',:shipping,:billing,:subtotal,0,0,0,:total,'RON',:status,:payment_status,:payment_method,'manual','',:terms,0,:created,:created)"
        );
        $orderItem = $this->pdo->prepare(
            'INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_summary,sku,quantity,unit_price_bani,personalization_bani,line_total_bani,tax_bani,personalization_json) '
            . "VALUES (:order,NULL,NULL,:product_name,:variant_summary,:sku,1,:price,0,:price,0,'{}')"
        );
        $base = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));

        for ($index = 1; $index <= $count; $index++) {
            $created = $base->modify('+' . $index . ' minutes')->format('c');
            $expires = $base->modify('+30 days')->format('c');
            $cart->execute([
                'token' => 'admin-orders-cart-' . $index,
                'created' => $created,
                'expires' => $expires,
            ]);
            $cartId = (int) $this->pdo->lastInsertId();
            $isLatest = $index === $count;
            $address = json_encode([
                'address' => 'Strada Test ' . $index,
                'city' => 'București — Sector 1',
                'county' => 'București',
                'postal_code' => '010101',
            ], JSON_THROW_ON_ERROR);
            $order->execute([
                'number' => 'LM-TEST-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'token_hash' => hash('sha256', 'admin-order-token-' . $index),
                'idempotency' => 'admin-order-idempotency-' . $index,
                'cart' => $cartId,
                'email' => 'client' . $index . '@example.test',
                'first_name' => 'Client',
                'last_name' => (string) $index,
                'phone' => '0712 000 ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'shipping' => $address,
                'billing' => $address,
                'subtotal' => 10000 + $index,
                'total' => 10000 + $index,
                'status' => $isLatest ? 'delivered' : 'new',
                'payment_status' => $isLatest ? 'paid' : 'pending',
                'payment_method' => $isLatest ? 'stripe' : 'cod',
                'terms' => $created,
                'created' => $created,
            ]);
            if ($isLatest) {
                $orderItem->execute([
                    'order' => (int) $this->pdo->lastInsertId(),
                    'product_name' => 'Cuburi moi Poveste',
                    'variant_summary' => 'Culoare: Roz',
                    'sku' => 'CUBURI-POVESTE-ROZ',
                    'price' => 10000 + $index,
                ]);
            }
        }
    }
}
