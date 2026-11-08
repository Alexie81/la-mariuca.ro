<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Shared\Config\Config;
use RuntimeException;

/** Selects delivery deliberately, without connecting to a transport or exposing its credentials. */
final class MailDeliveryPolicy
{
    /** @return 'preview'|'smtp' */
    public static function resolve(Config $config): string
    {
        $mode = strtolower(trim($config->get('MAIL_DELIVERY_MODE', 'auto')));
        if (!in_array($mode, ['auto', 'preview', 'smtp'], true)) {
            throw new RuntimeException('MAIL_DELIVERY_MODE trebuie să fie auto, preview sau smtp.');
        }

        $environment = strtolower(trim($config->get('APP_ENV', 'development')));
        // PHPUnit may inherit the private local SMTP configuration. Tests must never send mail.
        if ($environment === 'testing') {
            return 'preview';
        }

        $resolved = $mode === 'auto' ? ($environment === 'production' ? 'smtp' : 'preview') : $mode;
        if ($resolved === 'preview') {
            return 'preview';
        }

        if ($environment === 'production') {
            self::assertPublicEmailLinks($config);
        }

        $dsn = trim($config->get('MAILER_DSN'));
        try {
            $parts = parse_url($dsn);
        } catch (\ValueError) {
            $parts = false;
        }
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['smtp', 'smtps'], true)
            || $host === ''
            || preg_match('/[\x00-\x20\x7f]/', $dsn) === 1
            || ($port !== null && ($port < 1 || $port > 65535))) {
            // Never interpolate the DSN or parser diagnostics: either may contain credentials.
            throw new RuntimeException('Trimiterea SMTP necesită un MAILER_DSN smtp:// sau smtps:// valid, cu server configurat.');
        }

        return 'smtp';
    }

    /** Refuse production delivery before claiming messages if their CTAs would be local or malformed. */
    private static function assertPublicEmailLinks(Config $config): void
    {
        $url = $config->get('APP_URL');
        try { $parts = parse_url($url); } catch (\ValueError) { $parts = false; }
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $host = rtrim($host, '.');
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        $packed = $ip === false ? false : inet_pton($ip);
        if (is_string($packed) && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        $localHost = $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || (!str_contains($host, '.') && $ip === false)
            || ($ip === false && preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/i', $host) === 1)
            || ($ip !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
        $base = $config->get('APP_BASE_PATH');
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || $host === '' || $localHost
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\x00-\x20\x7f\\\\?#]/', $url . $base) === 1
            || str_contains($base, '://') || str_starts_with($base, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $base) === 1) {
            // Do not echo configuration values: an accidentally pasted URL may contain credentials.
            throw new RuntimeException('Emailurile de producție necesită APP_URL cu domeniul public HTTPS al magazinului, fără localhost, și APP_BASE_PATH valid.');
        }
    }
}
