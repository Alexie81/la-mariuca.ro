<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testRootUrlHasNoDuplicateSlash(): void
    {
        $_ENV['APP_URL'] = 'https://example.test/';
        $_ENV['APP_BASE_PATH'] = '';
        self::assertSame('https://example.test', Config::load(dirname(__DIR__, 2))->rootUrl());
        $_ENV['APP_BASE_PATH'] = '/magazin/';
        self::assertSame('https://example.test/magazin', Config::load(dirname(__DIR__, 2))->rootUrl());
    }
}
