<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Invoicing\InvoiceRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class InvoiceProductPreviewTest extends DatabaseTestCase
{
    public function testPreviewMatchesOnlyAnExactSellableVariantAndUsesItsPhoto(): void
    {
        $this->seed();
        $repo=new InvoiceRepository($this->pdo);
        $variant=$this->pdo->query("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status='active' AND p.status='published' AND v.price_bani>0 ORDER BY v.id LIMIT 1")->fetch();
        $media=$this->pdo->query('SELECT id,public_path FROM media WHERE public_path IS NOT NULL LIMIT 1')->fetch();
        $this->pdo->prepare('UPDATE product_variants SET image_media_id=:media WHERE id=:id')->execute(['media'=>$media['id'],'id'=>$variant['id']]);
        $product=$repo->productPreview($variant['sku']);
        self::assertSame($variant['sku'],$product['sku']);
        self::assertSame($media['public_path'],$product['image_path']);
        self::assertNotEmpty($product['name']);
        self::assertNull($repo->productPreview(substr($variant['sku'],0,3)));
        self::assertNull($repo->productPreview("' OR 1=1 --"));
        $this->pdo->prepare("UPDATE products SET status='draft' WHERE id=:id")->execute(['id'=>$variant['product_id']]);
        self::assertNull($repo->productPreview($variant['sku']));
    }

    public function testHttpPreviewIsAdminOnlyAndDoesNotChangeOrders(): void
    {
        $this->seed();
        $get=function(string $sku) {
            $app=AppFactory::create($this->root);
            $request=(new ServerRequestFactory())->createServerRequest('GET','http://localhost/admin/facturi/produs/',['REMOTE_ADDR'=>'127.0.0.76'])->withQueryParams(['sku'=>$sku])->withHeader('Accept','application/json');
            $response=$app->handle($request);unset($app);gc_collect_cycles();return $response;
        };
        self::assertContains($get('LM-26-009')->getStatusCode(),[302,303,401,403]);
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('preview@example.test','disabled','Paula','QA','administrator','active','2026-01-01','2026-01-01')");
        $_SESSION['user_id']=(int)$this->pdo->lastInsertId();
        $sku=(string)$this->pdo->query("SELECT v.sku FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.status='active' AND p.status='published' AND v.price_bani>0 LIMIT 1")->fetchColumn();
        $before=$this->pdo->query('SELECT * FROM order_items ORDER BY id')->fetchAll();
        $response=$get($sku);
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('no-store',$response->getHeaderLine('Cache-Control'));
        $product=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)['product'];
        self::assertSame($sku,$product['sku']);self::assertArrayHasKey('image_url',$product);
        self::assertNull(json_decode((string)$get('MISSING-SKU')->getBody(),true)['product']);
        self::assertSame($before,$this->pdo->query('SELECT * FROM order_items ORDER BY id')->fetchAll());
        $this->pdo->prepare("UPDATE users SET role='customer' WHERE id=:id")->execute(['id'=>$_SESSION['user_id']]);
        self::assertContains($get($sku)->getStatusCode(),[302,303,401,403]);
    }
}
