<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Invoicing\AnafFiscalGateway;
use App\Modules\Invoicing\InvoiceException;
use App\Modules\Invoicing\SpvConnection;
use App\Modules\Invoicing\SpvCurlClient;
use App\Modules\Invoicing\SpvHttpClient;
use App\Modules\Invoicing\SpvTokenVault;
use App\Modules\Invoicing\SpvTransportException;
use App\Shared\Config\Config;
use LogicException;
use Tests\DatabaseTestCase;

final class SpvIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_KEY'] = 'spv-test-key-that-is-at-least-32-bytes-long';
        $_ENV['ANAF_CLIENT_ID'] = 'client-test';
        $_ENV['ANAF_CLIENT_SECRET'] = 'secret-test';
        $_ENV['ANAF_ALLOW_PRODUCTION'] = 'false';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ANAF_CLIENT_ID'], $_ENV['ANAF_CLIENT_SECRET'], $_ENV['ANAF_ALLOW_PRODUCTION']);
        parent::tearDown();
    }

    public function testOAuthExchangeStoresEncryptedTokensAndNeverExposesThemInState(): void
    {
        $http = new FakeSpvHttpClient([
            ['status' => 200, 'body' => json_encode([
                'access_token' => 'access-token-value',
                'refresh_token' => 'refresh-token-value',
                'expires_in' => 3600,
                'refresh_expires_in' => 7200,
            ], JSON_THROW_ON_ERROR)],
        ]);
        $connection = new SpvConnection($this->pdo, $this->config(), $http);

        self::assertStringStartsWith('https://logincert.anaf.ro/anaf-oauth2/v1/authorize?', $connection->authorizationUrl('csrf-state'));
        $connection->exchange('authorization-code');

        self::assertSame('access-token-value', $connection->accessToken());
        $stored = (string) $this->pdo->query('SELECT encrypted_tokens FROM spv_credentials WHERE id=1')->fetchColumn();
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('access-token-value', $stored);
        self::assertStringNotContainsString('refresh-token-value', $stored);
        self::assertArrayNotHasKey('encrypted_tokens', $connection->state());
        self::assertArrayNotHasKey('refresh_lease', $connection->state());
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://logincert.anaf.ro/anaf-oauth2/v1/token', $http->requests[0]['url']);
        self::assertStringContainsString('grant_type=authorization_code', $http->requests[0]['body']);
        self::assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%2Fadmin%2Fspv-efactura%2Fretur%2F', $http->requests[0]['body']);
    }

    public function testExpiredAccessTokenIsRefreshedOnceAndRefreshTokenIsPreserved(): void
    {
        $http = new FakeSpvHttpClient([
            ['status' => 200, 'body' => json_encode([
                'access_token' => 'short-lived-token',
                'refresh_token' => 'long-lived-refresh',
                'expires_in' => 1,
                'refresh_expires_in' => 7200,
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => json_encode([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)],
        ]);
        $connection = new SpvConnection($this->pdo, $this->config(), $http);
        $connection->exchange('authorization-code');

        self::assertSame('refreshed-token', $connection->accessToken());
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('grant_type=refresh_token', $http->requests[1]['body']);
        self::assertStringContainsString('refresh_token=long-lived-refresh', $http->requests[1]['body']);
        self::assertNull($this->pdo->query('SELECT refresh_lease FROM spv_credentials WHERE id=1')->fetchColumn());
    }

    public function testVaultRejectsTamperingAndShortApplicationKeys(): void
    {
        $vault = new SpvTokenVault($_ENV['APP_KEY']);
        $cipher = $vault->seal(['access_token' => 'secret']);
        $bytes = base64_decode($cipher, true);
        self::assertIsString($bytes);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);

        $this->expectException(InvoiceException::class);
        $vault->open(base64_encode($bytes));
    }

    public function testProductionGatewayRequiresExplicitServerFlag(): void
    {
        $http = new FakeSpvHttpClient([]);
        $connection = new SpvConnection($this->pdo, $this->config(), $http);

        $this->expectException(InvoiceException::class);
        $this->expectExceptionMessage('Transmiterea oficială este dezactivată');
        new AnafFiscalGateway($connection, $http, $this->config(), 'production');
    }

    public function testGatewayUsesEnvironmentSpecificEndpointsAndParsesProviderResponses(): void
    {
        $http = new FakeSpvHttpClient([
            ['status' => 200, 'body' => json_encode([
                'access_token' => 'gateway-token',
                'refresh_token' => 'gateway-refresh',
                'expires_in' => 3600,
                'refresh_expires_in' => 7200,
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '<header index_incarcare="4711"/>'],
            ['status' => 200, 'body' => '<header stare="ok" id_descarcare="8128"/>'],
            ['status' => 200, 'body' => "PK\x03\x04signed-archive"],
            ['status' => 200, 'body' => '{"mesaje":[{"id":"8128"}]}'],
        ]);
        $connection = new SpvConnection($this->pdo, $this->config(), $http);
        $connection->exchange('authorization-code');
        $gateway = new AnafFiscalGateway($connection, $http, $this->config(), 'test');
        $xml = '<?xml version="1.0"?><Invoice xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cac:AccountingSupplierParty><cac:Party><cac:PartyLegalEntity><cbc:CompanyID>12345678</cbc:CompanyID></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty><cac:AccountingCustomerParty><cac:Party><cac:PartyLegalEntity><cbc:CompanyID>87654321</cbc:CompanyID></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty></Invoice>';

        self::assertSame(['reference' => '4711', 'state' => 'submitted', 'response' => ['index_incarcare' => '4711', 'environment' => 'test']], $gateway->send($xml, 'local-idempotency-key'));
        self::assertSame('accepted', $gateway->status('4711')['state']);
        self::assertSame("PK\x03\x04signed-archive", $gateway->download('8128'));
        self::assertSame('8128', $gateway->messages('12345678', 7)['mesaje'][0]['id']);

        self::assertStringStartsWith('https://api.anaf.ro/test/FCTEL/rest/upload?', $http->requests[1]['url']);
        self::assertStringContainsString('standard=UBL', $http->requests[1]['url']);
        self::assertContains('Authorization: Bearer gateway-token', $http->requests[1]['headers']);
        self::assertStringStartsWith('https://api.anaf.ro/test/FCTEL/rest/stareMesaj?', $http->requests[2]['url']);
        self::assertStringStartsWith('https://api.anaf.ro/test/FCTEL/rest/descarcare?', $http->requests[3]['url']);
        self::assertStringStartsWith('https://api.anaf.ro/test/FCTEL/rest/listaMesajeFactura?', $http->requests[4]['url']);
    }

    public function testGatewayRejectsExternalEntitiesAndCurlClientRejectsForeignHosts(): void
    {
        try {
            AnafFiscalGateway::parse('<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><x>&leak;</x>');
            self::fail('DOCTYPE responses must be rejected.');
        } catch (SpvTransportException $error) {
            self::assertSame('Răspuns ANAF neacceptat.', $error->getMessage());
        }

        $this->expectException(LogicException::class);
        (new SpvCurlClient())->request('GET', 'https://example.com/anaf');
    }

    private function config(): Config
    {
        return Config::load($this->root);
    }
}

final class FakeSpvHttpClient implements SpvHttpClient
{
    /** @var list<array{status:int,body:string}> */
    private array $responses;
    /** @var list<array{method:string,url:string,headers:list<string>,body:string}> */
    public array $requests = [];

    /** @param list<array{status:int,body:string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            throw new LogicException('Unexpected HTTP request: ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }
}
