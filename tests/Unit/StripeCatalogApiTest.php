<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payments\StripeCatalogApi;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Stripe\Product;
use Stripe\StripeClient;

final class StripeCatalogApiTest extends TestCase
{
    public function testCreationOmitsEmptyImagesButUpdateCanClearImages(): void
    {
        $client = $this->getMockBuilder(StripeClient::class)->setConstructorArgs(['sk_test_fake'])->onlyMethods(['request'])->getMock();
        $exists = false;
        $client->expects(self::exactly(4))->method('request')->willReturnCallback(function ($method, $path, $params, $options) use (&$exists) {
            if ($method === 'get' && !$exists) {
                throw InvalidRequestException::factory('Missing', 404, null, null, null, 'resource_missing');
            }
            if ($method === 'post' && $path === '/v1/products') {
                self::assertArrayNotHasKey('images', $params);
                self::assertSame('lm_test', $params['id']);
                self::assertSame('test-create', $options['idempotency_key']);
                $exists = true;
            } elseif ($method === 'post') {
                self::assertSame('/v1/products/lm_test', $path);
                self::assertSame('', $params['images']);
                self::assertSame('test-update', $options['idempotency_key']);
            }
            return Product::constructFrom(['id' => 'lm_test']);
        });
        $api = new StripeCatalogApi($client);
        $api->saveProduct('lm_test', ['name' => 'Rochiță', 'images' => ''], 'test');
        $api->saveProduct('lm_test', ['name' => 'Rochiță', 'images' => ''], 'test');
    }
}
