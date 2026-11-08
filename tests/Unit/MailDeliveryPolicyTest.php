<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Config\Config;
use App\Shared\Mail\MailDeliveryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailDeliveryPolicyTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalEnvironment = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnvironment;
    }

    #[DataProvider('deliveryModes')]
    public function testModeSelectionDoesNotDependOnTransportConnectivity(string $environment, string $mode, string $dsn, string $expected): void
    {
        self::assertSame($expected, MailDeliveryPolicy::resolve($this->config($environment, $mode, $dsn)));
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function deliveryModes(): iterable
    {
        yield 'development defaults to preview' => ['development', 'auto', 'null://null', 'preview'];
        yield 'production defaults to smtp' => ['production', 'auto', 'smtps://mail.example.test:465', 'smtp'];
        yield 'explicit preview in production' => ['production', 'preview', '', 'preview'];
        yield 'explicit smtp in development' => ['development', 'smtp', 'smtps://account:example-pass@mail.example.test:465', 'smtp'];
        yield 'local relay can omit credentials' => ['development', 'smtp', 'smtp://127.0.0.1:1025', 'smtp'];
        yield 'IPv6 relay' => ['development', 'smtp', 'smtp://[::1]:1025', 'smtp'];
        yield 'encoded credentials' => ['development', 'smtp', 'smtps://contact%40example.test:example%23pass@mail.example.test:465', 'smtp'];
        yield 'normalized mode' => ['development', ' SMTP ', 'smtp://mail.example.test:587', 'smtp'];
        yield 'testing blocks inherited smtp' => ['testing', 'smtp', 'smtps://example:example@mail.example.test:465', 'preview'];
        yield 'testing ignores invalid inherited dsn' => ['testing', 'smtp', 'invalid', 'preview'];
        yield 'testing auto is preview' => ['testing', 'auto', 'smtps://mail.example.test:465', 'preview'];
    }

    #[DataProvider('invalidDsns')]
    public function testSmtpFailsClosedWithoutLeakingCredentials(string $dsn): void
    {
        try {
            MailDeliveryPolicy::resolve($this->config('development', 'smtp', $dsn));
            self::fail('Invalid SMTP configuration must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Trimiterea SMTP necesită un MAILER_DSN smtp:// sau smtps:// valid, cu server configurat.', $exception->getMessage());
            self::assertStringNotContainsString('example-secret', $exception->getMessage());
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDsns(): iterable
    {
        yield 'empty' => [''];
        yield 'null transport' => ['null://null'];
        yield 'sendmail transport' => ['sendmail://default'];
        yield 'http transport' => ['https://user:example-secret@mail.example.test'];
        yield 'missing host' => ['smtp://'];
        yield 'bare host' => ['mail.example.test'];
        yield 'missing scheme' => ['//mail.example.test:465'];
        yield 'out of range port' => ['smtp://user:example-secret@mail.example.test:70000'];
        yield 'zero port' => ['smtp://mail.example.test:0'];
        yield 'whitespace inside host' => ['smtp://mail example.test'];
        yield 'control character' => ["smtp://user:example-secret@mail.example.test\n?x=1"];
    }

    public function testUnknownModeFailsClosedWithoutPrintingItsValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_DELIVERY_MODE trebuie să fie auto, preview sau smtp.');
        MailDeliveryPolicy::resolve($this->config('development', 'invalid-example-secret', 'smtp://127.0.0.1:1025'));
    }

    public function testSmtpScopeConfigurationIsAvailableWithoutChangingTheAppEnvironment(): void
    {
        $_ENV['MAIL_SEND_AFTER_ID'] = '42';
        $_ENV['MAIL_MESSAGE_TYPES'] = 'order_confirmation';
        $config = $this->config('development', 'smtp', 'smtp://127.0.0.1:1025');
        self::assertSame('development', $config->get('APP_ENV'));
        self::assertSame(42, $config->int('MAIL_SEND_AFTER_ID'));
        self::assertSame('order_confirmation', $config->get('MAIL_MESSAGE_TYPES'));
    }

    #[DataProvider('invalidProductionUrls')]
    public function testProductionCannotSendLocalOrMalformedLinks(string $url, string $base = ''): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Emailurile de producție necesită APP_URL cu domeniul public HTTPS');
        MailDeliveryPolicy::resolve($this->config('production', 'smtp', 'smtps://mail.example.test:465', $url, $base));
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidProductionUrls(): iterable
    {
        yield 'local default' => ['http://localhost:8080', ''];
        yield 'https localhost' => ['https://LOCALHOST.', ''];
        yield 'localhost subdomain' => ['https://shop.localhost', ''];
        yield 'loopback IPv4' => ['https://127.0.0.1:8084', ''];
        yield 'loopback IPv6' => ['https://[::1]', ''];
        yield 'short loopback IPv4' => ['https://127.1', ''];
        yield 'octal loopback IPv4' => ['https://0177.0.0.1', ''];
        yield 'mapped loopback IPv6' => ['https://[::ffff:127.0.0.1]', ''];
        yield 'mapped hex loopback IPv6' => ['https://[::ffff:7f00:1]', ''];
        yield 'private network' => ['https://192.168.1.20', ''];
        yield 'local hostname' => ['https://magazin.local', ''];
        yield 'plain http' => ['http://la-mariuca.ro', ''];
        yield 'credentials' => ['https://user:example-secret@la-mariuca.ro', ''];
        yield 'query instead of origin' => ['https://la-mariuca.ro?preview=1', ''];
        yield 'fragment instead of origin' => ['https://la-mariuca.ro#preview', ''];
        yield 'path belongs in base' => ['https://la-mariuca.ro/magazin', ''];
        yield 'bad base' => ['https://la-mariuca.ro', '/magazin?preview=1'];
        yield 'relative parent base' => ['https://la-mariuca.ro', '/../magazin'];
        yield 'external base' => ['https://la-mariuca.ro', '//localhost'];
    }

    public function testPublicDomainAndOptionalBaseWorkWhileLocalPreviewsRemainAvailable(): void
    {
        self::assertSame('smtp', MailDeliveryPolicy::resolve($this->config('production', 'auto', 'smtps://mail.example.test:465', 'https://la-mariuca.ro/', '/magazin/')));
        self::assertSame('preview', MailDeliveryPolicy::resolve($this->config('production', 'preview', '', 'http://127.0.0.1:8084')));
        self::assertSame('preview', MailDeliveryPolicy::resolve($this->config('testing', 'smtp', '', 'http://127.0.0.1:8084')));
        self::assertSame('smtp', MailDeliveryPolicy::resolve($this->config('development', 'smtp', 'smtp://127.0.0.1:1025', 'http://localhost:8080')));
    }

    private function config(string $environment, string $mode, string $dsn, string $url = 'https://la-mariuca.ro', string $base = ''): Config
    {
        $_ENV['APP_ENV'] = $environment;
        $_ENV['MAIL_DELIVERY_MODE'] = $mode;
        $_ENV['MAILER_DSN'] = $dsn;
        $_ENV['APP_URL'] = $url;
        $_ENV['APP_BASE_PATH'] = $base;
        // This directory has no .env: the policy tests never read private application configuration.
        return Config::load(__DIR__);
    }
}
