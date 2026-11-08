<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Payments\StripeCatalogGateway;
use App\Modules\Payments\StripeCatalogQueue;
use App\Modules\Payments\StripeCatalogSync;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class StripeCatalogSyncTest extends DatabaseTestCase
{
    private array $environment;
    private CatalogAdminService $catalog;
    private CatalogGatewayFake $api;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        parent::setUp();
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_catalog_fake';
        $_ENV['STRIPE_CATALOG_ENABLED'] = 'true';
        $this->catalog = new CatalogAdminService($this->pdo);
        $this->categoryId = $this->catalog->saveCategory(['name' => 'Test catalog', 'description' => 'Categorie pentru verificare.']);
        $this->api = new CatalogGatewayFake();
    }

    protected function tearDown(): void
    {
        unset($this->catalog, $this->api);
        gc_collect_cycles();
        parent::tearDown();
        $_ENV = $this->environment;
    }

    public function testProductCreateEditPricesAndDeletionSynchronizeWithoutDeletingHistory(): void
    {
        $id = $this->catalog->saveProduct($this->data());
        self::assertSame(1, (int) $this->pdo->query('SELECT revision FROM stripe_catalog_changes')->fetchColumn());
        self::assertSame(1, $this->sync()->process()['synced']);
        $remote = (string) $this->pdo->query('SELECT stripe_product_id FROM stripe_catalog_products')->fetchColumn();
        self::assertSame('Rochiță test', $this->api->products[$remote]['name']);
        self::assertTrue($this->api->products[$remote]['active']);
        self::assertSame('', $this->api->products[$remote]['images']); // localhost images never escape
        $firstPrice = $this->api->products[$remote]['default_price'];
        self::assertSame(12990, $this->api->prices[$firstPrice]['unit_amount']);
        self::assertSame('ron', $this->api->prices[$firstPrice]['currency']);
        self::assertSame(0, $this->sync()->process()['synced']);

        $this->catalog->saveProduct($this->data('Rochiță nouă', '139,50'), $id);
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertSame('Rochiță nouă', $this->api->products[$remote]['name']);
        $newPrice = $this->api->products[$remote]['default_price'];
        self::assertNotSame($firstPrice, $newPrice);
        self::assertFalse($this->api->prices[$firstPrice]['active']);
        self::assertTrue($this->api->prices[$newPrice]['active']);
        self::assertSame(13950, $this->api->prices[$newPrice]['unit_amount']);

        $this->catalog->deleteProduct($id);
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertFalse($this->api->products[$remote]['active']);
        self::assertFalse($this->api->prices[$newPrice]['active']);
        self::assertCount(1, $this->api->products);
        self::assertCount(2, $this->api->prices);
    }

    public function testFailedApiCallRetainsJobAndSanitizesErrorThenRetries(): void
    {
        $this->catalog->saveProduct($this->data());
        $this->api->fail = true;
        self::assertSame(1, $this->sync()->process()['failed']);
        $row = $this->pdo->query('SELECT * FROM stripe_catalog_products')->fetch();
        self::assertSame(0, (int) $row['synced_revision']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['lease_token']);
        self::assertStringNotContainsString('sk_test', $row['last_error']);
        self::assertSame(0, $this->sync()->process()['synced']);
        $this->api->fail = false;
        $this->pdo->exec("UPDATE stripe_catalog_products SET next_attempt_at='2000-01-01T00:00:00+00:00'");
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertNull($this->pdo->query('SELECT last_error FROM stripe_catalog_products')->fetchColumn());
    }

    public function testChangesArrivingDuringSyncAreNotLostAndLeaseExcludesSecondWorker(): void
    {
        $id = $this->catalog->saveProduct($this->data());
        $this->api->onSave = function () use ($id): void {
            self::assertSame(0, $this->sync()->process()['synced']);
            $this->pdo->prepare("UPDATE products SET name='Schimbat în timpul sincronizării' WHERE id=?")->execute([$id]);
            (new StripeCatalogQueue($this->pdo))->enqueue($id);
        };
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertSame(1, (int) $this->pdo->query('SELECT synced_revision FROM stripe_catalog_products')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT revision FROM stripe_catalog_changes')->fetchColumn());
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertSame('Schimbat în timpul sincronizării', current($this->api->products)['name']);
    }

    public function testDifferentAccountAndModeHaveSeparateMappingsAndKeyRotationReusesThem(): void
    {
        $this->catalog->saveProduct($this->data());
        self::assertSame(1, $this->sync()->process()['synced']);
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_rotated_fake';
        self::assertSame(0, $this->sync()->process()['synced']);
        $this->api->account = 'acct_Second';
        self::assertSame(1, $this->sync()->process()['synced']);
        $_ENV['STRIPE_MODE'] = 'live';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_live_fake';
        $_ENV['APP_ENV'] = 'production';
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM stripe_catalog_products')->fetchColumn());
    }

    public function testQueuedChangeRollsBackAndDisabledSyncNeverCallsApi(): void
    {
        $this->pdo->beginTransaction();
        (new StripeCatalogQueue($this->pdo))->enqueue(400);
        $this->pdo->rollBack();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM stripe_catalog_changes')->fetchColumn());
        $this->catalog->saveProduct($this->data());
        $_ENV['STRIPE_CATALOG_ENABLED'] = 'false';
        self::assertTrue($this->sync()->process()['disabled']);
        self::assertSame(0, $this->api->calls);
    }

    public function testRemotePriceCanBeRecoveredAfterCrashWithoutDuplicatingAndReactivated(): void
    {
        $id = $this->catalog->saveProduct($this->data());
        self::assertSame(1, $this->sync()->process()['synced']);
        $firstPrice = array_key_first($this->api->prices);
        // Simulate API success followed by loss of the local price write before the job was acknowledged.
        $this->pdo->exec('DELETE FROM stripe_catalog_prices');
        $this->pdo->exec('UPDATE stripe_catalog_products SET synced_revision=0');
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertCount(1, $this->api->prices);
        self::assertSame($firstPrice, $this->pdo->query('SELECT stripe_price_id FROM stripe_catalog_prices')->fetchColumn());
        $this->catalog->deleteProduct($id);
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertFalse($this->api->prices[$firstPrice]['active']);
        $this->catalog->saveProduct($this->data(), $id);
        self::assertSame(1, $this->sync()->process()['synced']);
        self::assertCount(1, $this->api->prices);
        self::assertTrue($this->api->prices[$firstPrice]['active']);
    }

    public function testSyncRefusesToRunWhileDatabaseTransactionIsOpen(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->sync()->process();
            self::fail('The network sync must not run inside a transaction.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('outside a database transaction', $error->getMessage());
            self::assertSame(0, $this->api->calls);
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function sync(): StripeCatalogSync
    {
        return new StripeCatalogSync($this->pdo, Config::load($this->root), $this->api);
    }

    private function data(string $name = 'Rochiță test', string $price = '129,90'): array
    {
        return ['name' => $name, 'category_id' => $this->categoryId, 'primary_sku' => 'LM-CATALOG-QA', 'price' => $price, 'stock' => 3, 'status' => 'published'];
    }
}

final class CatalogGatewayFake implements StripeCatalogGateway
{
    public string $account = 'acct_First';
    public array $products = [];
    public array $prices = [];
    public bool $fail = false;
    public int $calls = 0;
    public ?\Closure $onSave = null;
    public function accountId(): string { ++$this->calls; return $this->account; }
    public function saveProduct(string $id, array $data, string $idempotencyKey): void
    {
        ++$this->calls;
        if ($this->fail) { throw new \RuntimeException('Sensitive sk_test_do_not_log'); }
        $this->products[$id] = array_replace($this->products[$id] ?? [], $data);
        $hook = $this->onSave;
        $this->onSave = null;
        if ($hook) { $hook(); }
    }
    public function createPrice(array $data, string $idempotencyKey): string
    {
        ++$this->calls;
        $id = 'price_fake_' . (count($this->prices) + 1);
        $this->prices[$id] = $data + ['active' => true];
        return $id;
    }
    public function findPrice(string $lookupKey): ?string
    {
        foreach ($this->prices as $id => $price) { if ($price['lookup_key'] === $lookupKey) { return $id; } }
        return null;
    }
    public function setPriceActive(string $id, bool $active): void { $this->prices[$id]['active'] = $active; }
}
