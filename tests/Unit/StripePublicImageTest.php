<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payments\StripePublicImage;
use App\Shared\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripePublicImageTest extends TestCase
{
    #[DataProvider('origins')]
    public function testTemporaryMirrorIsRestrictedToNonProductionTest(string $environment, string $mode, string $mirror, string $expected): void
    {
        $before = $_ENV;
        try {
            $_ENV['APP_ENV'] = $environment;
            $_ENV['STRIPE_MODE'] = $mode;
            $_ENV['APP_URL'] = 'https://la-mariuca.ro';
            $_ENV['APP_BASE_PATH'] = '/boutique';
            $_ENV['STRIPE_PUBLIC_MEDIA_BASE_URL'] = $mirror;
            $config = Config::load(sys_get_temp_dir() . '/nonexistent-lm-image-config');
            self::assertSame($expected, (new StripePublicImage($config))->url('/media/product.webp'));
        } finally { $_ENV = $before; }
    }

    public static function origins(): array
    {
        $mirror = 'https://raw.githubusercontent.com/Alexie81/la-mariuca.ro/master/public';
        $canonical = 'https://la-mariuca.ro/boutique/media/product.webp';
        return [
            'development test mirror' => ['development', 'test', $mirror, $mirror . '/media/product.webp'],
            'testing test mirror' => ['testing', 'test', $mirror, $mirror . '/media/product.webp'],
            'blank override uses canonical' => ['development', 'test', '', $canonical],
            'production test ignores mirror' => ['production', 'test', $mirror, $canonical],
            'production live ignores mirror' => ['production', 'live', $mirror, $canonical],
            'development live ignores mirror' => ['development', 'live', $mirror, $canonical],
        ];
    }
}
