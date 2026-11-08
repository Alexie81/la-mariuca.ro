<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;

final class StripePublicImage
{
    public function __construct(private readonly Config $config)
    {
    }

    public function url(mixed $path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }
        // A temporary public mirror helps local TEST Checkout; production always uses the storefront origin.
        $configuredBase = $this->config->stripeMode() === 'test' && $this->config->get('APP_ENV') !== 'production'
            ? trim($this->config->get('STRIPE_PUBLIC_MEDIA_BASE_URL')) : '';
        $base = rtrim($configuredBase === '' ? $this->config->rootUrl() : $configuredBase, '/');
        $url = preg_match('~^https?://~i', $path) === 1 ? $path : $base . '/' . ltrim($path, '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null
            || $host === '' || !str_contains($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.test') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        return $url;
    }
}
