<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class SupplierAdminTest extends DatabaseTestCase
{
    private const PASSWORD = 'Supplier-admin-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('supplier.admin@example.test',:password,'Paula','Admin','administrator','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->seed();
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'supplier.admin@example.test',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
    }

    public function testSupplierCrmAndProductAssociationFlow(): void
    {
        $emptyPage = (string) $this->get('/admin/furnizori/')->getBody();
        self::assertStringContainsString('Agenda furnizorilor este goală', $emptyPage);
        self::assertStringContainsString('Stoc și achiziții', $emptyPage);
        self::assertStringContainsString('href="/admin/furnizori/" aria-current="page"', $emptyPage);

        $form = (string) $this->get('/admin/furnizori/nou/')->getBody();
        self::assertStringContainsString('Adaugă un furnizor', $form);
        self::assertStringContainsString('name="alias"', $form);
        self::assertStringContainsString('name="website"', $form);
        $newModal = (string) $this->get('/admin/furnizori/nou/modal/')->getBody();
        self::assertStringContainsString('data-admin-supplier-form', $newModal);
        self::assertStringContainsString('Adaugă un furnizor', $newModal);
        self::assertStringContainsString('admin-supplier-form__scroll', $newModal);

        $create = $this->post('/admin/furnizori/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'alias' => 'ATELIER ROZ',
            'company_name' => 'Atelier Roz SRL',
            'tax_id' => 'RO12345678',
            'contact_name' => 'Maria Ionescu',
            'email' => 'contact@atelier-roz.test',
            'phone' => '0712 000 111',
            'website' => 'atelier-roz.test',
            'status' => 'active',
        ]);
        self::assertSame(303, $create->getStatusCode());
        $supplierId = (int) $this->pdo->query("SELECT id FROM suppliers WHERE alias='ATELIER ROZ'")->fetchColumn();
        self::assertGreaterThan(0, $supplierId);
        self::assertSame('https://atelier-roz.test', $this->pdo->query('SELECT website FROM suppliers WHERE id=' . $supplierId)->fetchColumn());

        $editModal = (string) $this->get('/admin/furnizori/' . $supplierId . '/editare/modal/')->getBody();
        self::assertStringContainsString('data-admin-supplier-form', $editModal);
        self::assertStringContainsString('Actualizează relația', $editModal);
        self::assertStringContainsString('value="ATELIER ROZ"', $editModal);

        $list = (string) $this->get('/admin/furnizori/?cautare=Maria')->getBody();
        self::assertStringContainsString('ATELIER ROZ', $list);
        self::assertStringContainsString('Atelier Roz SRL', $list);
        self::assertStringContainsString('class="admin-suppliers-table"', $list);
        self::assertStringContainsString('<th>Furnizor</th>', $list);
        self::assertStringContainsString('admin-suppliers-table__action-group', $list);
        self::assertStringContainsString('admin-suppliers-table__link', $list);
        self::assertStringContainsString('admin-suppliers-table__avatar is-active', $list);
        self::assertStringContainsString('data-admin-supplier-delete-form', $list);

        $modal = (string) $this->get('/admin/produse/nou/modal/')->getBody();
        self::assertStringContainsString('Furnizor <em>opțional</em>', $modal);
        self::assertStringContainsString('ATELIER ROZ — Atelier Roz SRL', $modal);
        self::assertStringContainsString('Brand / Producător <em>opțional</em>', $modal);
        self::assertStringContainsString('Google Merchant', $modal);

        $categoryId = (int) $this->pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
        $createProduct = $this->request('POST', '/admin/produse/nou/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Produs cu furnizor și brand',
            'slug' => 'produs-cu-furnizor-si-brand',
            'sku' => 'LM-SUPPLIER-001',
            'supplier_id' => (string) $supplierId,
            'brand' => 'Măriuca Atelier',
            'price' => '159,00',
            'stock' => '5',
            'category_ids' => [(string) $categoryId],
            'status' => 'published',
            'indexable' => 'on',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $createProduct->getStatusCode());
        $productId = (int) $this->pdo->query("SELECT id FROM products WHERE slug='produs-cu-furnizor-si-brand'")->fetchColumn();
        self::assertSame($supplierId, (int) $this->pdo->query('SELECT supplier_id FROM products WHERE id=' . $productId)->fetchColumn());
        self::assertSame('Măriuca Atelier', $this->pdo->query('SELECT brand FROM products WHERE id=' . $productId)->fetchColumn());

        $productPage = (string) $this->get('/produs/produs-cu-furnizor-si-brand/')->getBody();
        self::assertStringContainsString('"brand":{"@type":"Brand","name":"Măriuca Atelier"}', $productPage);

        $delete = $this->post('/admin/furnizori/' . $supplierId . '/sterge/', ['_csrf' => $_SESSION['_csrf']]);
        self::assertSame(303, $delete->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM suppliers WHERE id=' . $supplierId)->fetchColumn());
        self::assertNull($this->pdo->query('SELECT supplier_id FROM products WHERE id=' . $productId)->fetchColumn());
        self::assertSame('Măriuca Atelier', $this->pdo->query('SELECT brand FROM products WHERE id=' . $productId)->fetchColumn());
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    public function testSupplierVisibilityHidesPublicProductsAndPreservesAdminRecords(): void
    {
        $service=new \App\Modules\Suppliers\SupplierAdminService($this->pdo);
        $id=$service->save(['alias'=>'Vizibilitate QA']);
        $row=$this->pdo->query("SELECT id,slug,primary_category_id FROM products WHERE status='published' ORDER BY id LIMIT 1")->fetch();
        $this->pdo->exec('UPDATE products SET supplier_id='.$id.' WHERE id='.$row['id']);
        $catalog=new \App\Modules\Catalog\CatalogRepository($this->pdo);
        $count=$catalog->publishedProductCount();
        $this->post('/admin/furnizori/'.$id.'/editare/', ['_csrf'=>$_SESSION['_csrf'],'alias'=>'Vizibilitate QA','status'=>'active','products_visible_online'=>'0']);
        self::assertSame(0,(int)$service->find($id)['products_visible_online']); self::assertSame('active',$service->find($id)['status']);
        self::assertNull($catalog->productBySlug($row['slug'])); self::assertNotNull($catalog->productBySlug($row['slug'],false));
        self::assertSame(404,$this->get('/produs/'.$row['slug'].'/')->getStatusCode());
        self::assertSame($count-1,$catalog->publishedProductCount());
        foreach([$catalog->shopProducts(1,50),$catalog->searchableProducts(),$catalog->latestProducts(24),$catalog->productsForCategory((int)$row['primary_category_id'])] as $products) {
            self::assertNotContains((int)$row['id'],array_map('intval',array_column($products,'id')));
        }
        self::assertNotContains($row['slug'],array_column($catalog->sitemapEntities('product'),'slug'));
        $admin=(string)$this->get('/admin/produse/'.$row['id'].'/')->getBody(); self::assertStringContainsString('Fișa completă a produsului',$admin);
        self::assertStringContainsString('Moderare recenzii',(string)$this->get('/admin/furnizori/')->getBody());
        $modal=(string)$this->get('/admin/furnizori/'.$id.'/editare/modal/')->getBody(); self::assertStringContainsString('role="switch" name="products_visible_online" value="1" aria-label=',$modal);
        $service->save(['alias'=>'Vizibilitate QA','products_visible_online'=>'1'],$id);
        self::assertSame(200,$this->get('/produs/'.$row['slug'].'/')->getStatusCode()); self::assertSame($count,$catalog->publishedProductCount());
        self::assertContains($row['slug'],array_column($catalog->sitemapEntities('product'),'slug'));
        self::assertSame('published',$this->pdo->query('SELECT status FROM products WHERE id='.$row['id'])->fetchColumn());
    }

    /** @param array<string,mixed> $data */
    private function post(string $path, array $data): ResponseInterface
    {
        return $this->request('POST', $path, $data);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.94'])
            ->withHeader('User-Agent', 'LaMariuca-SupplierAdmin-Test/1.0');
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
