<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeConfigTest extends TestCase
{
    private array $environment;
    private array $server;
    private string $root;
    private array $paths = [];

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $this->root = sys_get_temp_dir() . '/lamariuca-stripe-config-' . bin2hex(random_bytes(8));
        foreach (['', '/storage', '/storage/private', '/storage/private-other', '/public'] as $directory) {
            mkdir($this->root . $directory);
        }
        // This root has no .env and never loads the developer's real credentials.
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['STRIPE_MODE'] = 'test';
        $_ENV['STRIPE_SECRET_KEY'] = '';
        $_ENV['STRIPE_PUBLISHABLE_KEY'] = '';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = '';
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = '';
        $_ENV['GOOGLE_CREDENTIALS_FILE'] = '';
        $_ENV['WEB_PUSH_VAPID_FILE'] = '';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        $_SERVER = $this->server;
        foreach ($this->paths as $path) { unlink($path); }
        foreach (['/storage/private', '/storage/private-other', '/public', '/storage', ''] as $directory) {
            rmdir($this->root . $directory);
        }
    }

    #[DataProvider('keyModes')]
    public function testKeyMustMatchExplicitModeAndLiveRequiresProduction(string $environment, string $mode, string $key, bool $expected): void
    {
        $_ENV['APP_ENV'] = $environment;
        $_ENV['STRIPE_MODE'] = $mode;
        $_ENV['STRIPE_SECRET_KEY'] = $key;
        self::assertSame($expected, Config::load($this->root)->stripeConfigured());
    }

    public static function keyModes(): array
    {
        return [
            'test secret in development' => ['development', 'test', 'sk_test_placeholder', true],
            'test restricted key' => ['testing', 'test', 'rk_test_placeholder', true],
            'test can run in production explicitly' => ['production', 'test', 'sk_test_placeholder', true],
            'live secret in production' => ['production', 'live', 'sk_live_placeholder', true],
            'live restricted in production' => ['production', 'live', 'rk_live_placeholder', true],
            'live blocked in development' => ['development', 'live', 'sk_live_placeholder', false],
            'live blocked in testing' => ['testing', 'live', 'sk_live_placeholder', false],
            'live key blocked in test mode' => ['production', 'test', 'sk_live_placeholder', false],
            'test key blocked in live mode' => ['production', 'live', 'sk_test_placeholder', false],
            'publishable key is not secret' => ['testing', 'test', 'pk_test_placeholder', false],
            'missing key' => ['testing', 'test', '', false],
            'unknown mode' => ['production', 'sandbox', 'sk_test_placeholder', false],
            'mistyped mode' => ['production', 'LIVE', 'sk_live_placeholder', false],
            'random string' => ['testing', 'test', 'placeholder', false],
        ];
    }

    public function testPrivateRelativeWebhookSecretIsLoadedAndTrimmed(): void
    {
        $this->write('/storage/private/stripe-webhook.secret', "whsec_fixture_only\n");
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = 'storage/private/stripe-webhook.secret';
        self::assertSame('whsec_fixture_only', Config::load($this->root)->get('STRIPE_WEBHOOK_SECRET'));
    }

    public function testExplicitWebhookSecretHasPriorityOverFile(): void
    {
        $this->write('/storage/private/stripe-webhook.secret', 'whsec_file');
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = 'storage/private/stripe-webhook.secret';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_explicit';
        self::assertSame('whsec_explicit', Config::load($this->root)->get('STRIPE_WEBHOOK_SECRET'));
    }

    #[DataProvider('outsidePaths')]
    public function testWebhookSecretCannotBeLoadedOutsidePrivateDirectory(string $location, string $configured): void
    {
        $this->write($location, 'whsec_outside_must_not_load');
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = $configured;
        self::assertSame('', Config::load($this->root)->get('STRIPE_WEBHOOK_SECRET'));
    }

    public static function outsidePaths(): array
    {
        return [
            'public file' => ['/public/secret', 'public/secret'],
            'root file' => ['/secret', 'secret'],
            'traversal outside private' => ['/public/secret', 'storage/private/../../public/secret'],
            'private prefix sibling is not private' => ['/storage/private-other/secret', 'storage/private-other/secret'],
            'private prefix sibling via traversal' => ['/storage/private-other/secret', 'storage/private/../private-other/secret'],
        ];
    }

    public function testMissingOrMalformedSecretFileDoesNotConfigureWebhook(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = 'storage/private/missing.secret';
        self::assertSame('', Config::load($this->root)->get('STRIPE_WEBHOOK_SECRET'));
        $this->write('/storage/private/malformed.secret', 'not_a_webhook_secret');
        $_ENV['STRIPE_WEBHOOK_SECRET_FILE'] = 'storage/private/malformed.secret';
        self::assertSame('', Config::load($this->root)->get('STRIPE_WEBHOOK_SECRET'));
    }

    private function write(string $relative, string $value): void
    {
        $path = $this->root . $relative;
        file_put_contents($path, $value);
        $this->paths[] = $path;
    }
}
