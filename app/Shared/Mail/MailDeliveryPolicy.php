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
}
