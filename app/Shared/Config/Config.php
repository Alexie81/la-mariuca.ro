<?php

declare(strict_types=1);

namespace App\Shared\Config;

use Dotenv\Dotenv;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $root): self
    {
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        $defaults = [
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://localhost:8080',
            'APP_BASE_PATH' => '',
            'APP_TIMEZONE' => 'Europe/Bucharest',
            'APP_INDEXING' => 'false',
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => 'storage/private/development.sqlite',
            'SESSION_NAME' => 'lamariuca_session',
            'SESSION_SECURE' => 'false',
            'SHIPPING_FLAT_RATE_BANI' => '1900',
            'SHIPPING_FREE_THRESHOLD_BANI' => '30000',
            'TAX_RATE_BASIS_POINTS' => '0',
            'PRICES_INCLUDE_TAX' => 'true',
            'STRIPE_ENABLED' => 'false',
            'WHATSAPP_NUMBER' => '40763715974',
            'WHATSAPP_MESSAGE' => 'Bună! Aș dori mai multe informații despre produsele La Măriuca Boutique.',
            'CONTACT_EMAIL' => 'contact@la-mariuca.ro',
            'GOOGLE_OAUTH_ENABLED' => 'false',
            'GOOGLE_CREDENTIALS_FILE' => '',
        ];

        $values = [];
        foreach ($defaults as $key => $default) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $values[$key] = is_string($value) && $value !== '' ? $value : $default;
        }

        foreach (['APP_KEY', 'TRUSTED_HOSTS', 'TRUSTED_PROXIES', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MAILER_DSN', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_CURRENCY', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_string($value)) {
                $values[$key] = $value;
            }
        }

        $credentialsFile = trim($values['GOOGLE_CREDENTIALS_FILE'] ?? '');
        if ($credentialsFile !== '' && (($values['GOOGLE_CLIENT_ID'] ?? '') === '' || ($values['GOOGLE_CLIENT_SECRET'] ?? '') === '')) {
            $isAbsolute = str_starts_with($credentialsFile, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $credentialsFile) === 1;
            $credentialsPath = $isAbsolute ? $credentialsFile : $root . '/' . ltrim($credentialsFile, '/\\');
            $credentialsJson = is_file($credentialsPath) ? file_get_contents($credentialsPath) : false;
            $credentials = is_string($credentialsJson) ? json_decode($credentialsJson, true) : null;
            $oauthClient = is_array($credentials) ? ($credentials['web'] ?? $credentials['installed'] ?? null) : null;
            if (is_array($oauthClient)) {
                if (($values['GOOGLE_CLIENT_ID'] ?? '') === '' && is_string($oauthClient['client_id'] ?? null)) {
                    $values['GOOGLE_CLIENT_ID'] = $oauthClient['client_id'];
                }
                if (($values['GOOGLE_CLIENT_SECRET'] ?? '') === '' && is_string($oauthClient['client_secret'] ?? null)) {
                    $values['GOOGLE_CLIENT_SECRET'] = $oauthClient['client_secret'];
                }
            }
        }

        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;
        return $value !== null && preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    public function rootUrl(): string
    {
        $url = rtrim($this->get('APP_URL'), '/');
        $base = trim($this->get('APP_BASE_PATH'), '/');
        return $base === '' ? $url : $url . '/' . $base;
    }

    public function path(string $path = ''): string
    {
        $base = '/' . trim($this->get('APP_BASE_PATH'), '/');
        $base = $base === '/' ? '' : $base;
        return $base . '/' . ltrim($path, '/');
    }
}
