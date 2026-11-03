<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Checkout\PostalCodeController;
use App\Shared\Support\RomanianLocations;
use App\Shared\Support\RomanianPostalCodes;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RomanianPostalCodesTest extends TestCase
{
    public function testExactPostalCodeResolvesCanonicalBucharestSectorWithoutInventingStreet(): void
    {
        $lookup = RomanianPostalCodes::fromProjectRoot(dirname(__DIR__, 2));
        self::assertSame([['county' => 'București', 'city' => 'București — Sector 1', 'address' => '']], $lookup->lookup('010101'));
        self::assertSame([['county' => 'Cluj', 'city' => 'Cluj-Napoca', 'address' => '']], $lookup->lookup('400001'));
        self::assertSame([], $lookup->lookup('999999'));
    }

    public function testAmbiguousCodeReturnsAllDestinationsInsteadOfChoosingOne(): void
    {
        $matches = RomanianPostalCodes::fromProjectRoot(dirname(__DIR__, 2))->lookup('510002');
        self::assertCount(2, $matches);
        self::assertSame(['Alba Iulia', 'Micești'], (static function (array $rows): array {
            $cities = array_column($rows, 'city');
            sort($cities);
            return $cities;
        })($matches));
    }

    public function testIncompleteCodeIsRejectedRatherThanMatchedByPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RomanianPostalCodes::fromProjectRoot(dirname(__DIR__, 2))->lookup('0101');
    }

    public function testBundledDatasetOnlyOffersCanonicalLocationsAcceptedByCheckout(): void
    {
        $root = dirname(__DIR__, 2);
        $data = json_decode((string) file_get_contents($root . '/resources/data/romania-postal-codes.json'), true, 512, JSON_THROW_ON_ERROR);
        $locations = RomanianLocations::fromProjectRoot($root);
        $invalid = [];
        foreach ($data['codes'] as $code => $matches) {
            foreach ($matches as [$county, $city]) {
                if (!$locations->isValid($county, $city) || preg_match('/^[0-9]{6}$/D', (string) $code) !== 1) {
                    $invalid[] = [$code, $county, $city];
                }
            }
        }
        self::assertGreaterThan(30000, count($data['codes']));
        self::assertSame([], $invalid);
    }

    public function testEndpointHandlesNonScalarInputAndUnavailableDataGracefully(): void
    {
        $controller = new PostalCodeController(new RomanianPostalCodes('/missing/postal-dataset.json'));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/finalizare-comanda/cod-postal/');
        $badResponse = $controller->lookup($request->withQueryParams(['cod' => ['010101']]), new Response());
        self::assertSame(422, $badResponse->getStatusCode());
        self::assertSame('no-store', $badResponse->getHeaderLine('Cache-Control'));
        $unavailable = $controller->lookup($request->withQueryParams(['cod' => '010101']), new Response());
        self::assertSame(503, $unavailable->getStatusCode());
        self::assertSame([], json_decode((string) $unavailable->getBody(), true)['matches']);
    }

    public function testEndpointPreservesLeadingZeroAndListsLookupPrecision(): void
    {
        $controller = new PostalCodeController(RomanianPostalCodes::fromProjectRoot(dirname(__DIR__, 2)));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/finalizare-comanda/cod-postal/')->withQueryParams(['cod' => '010101']);
        $response = $controller->lookup($request, new Response());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('010101', $data['postal_code']);
        self::assertSame('locality', $data['precision']);
        self::assertSame('București — Sector 1', $data['matches'][0]['city']);
    }
}
