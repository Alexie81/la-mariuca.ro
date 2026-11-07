<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Config\Config;
use Psr\Http\Message\ServerRequestInterface;

/** Remembers only an internal order ID, never a client-provided redirect URL. */
final class AdminOrderDestination
{
    private const SESSION_KEY = 'admin_order_destination';
    private const LIFETIME_SECONDS = 900;

    public function __construct(private readonly Config $config) {}

    public function remember(ServerRequestInterface $request): void
    {
        if ($request->getMethod() !== 'GET') { return; }
        $prefix = $this->config->path('admin/comenzi/');
        if (preg_match('~^' . preg_quote($prefix, '~') . '([1-9][0-9]*)/$~D', $request->getUri()->getPath(), $matches) !== 1) {
            return;
        }
        $orderId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) { return; }
        $_SESSION[self::SESSION_KEY] = [
            'order_id' => $orderId,
            'base_path' => $this->config->path(),
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];
    }

    public function consume(Auth $auth): ?string
    {
        if (!$auth->hasRole(['administrator', 'operator'])) { return null; }
        $destination = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($destination) || !is_int($destination['order_id'] ?? null) || $destination['order_id'] < 1
            || !is_int($destination['expires_at'] ?? null)
            || $destination['expires_at'] <= time() || $destination['expires_at'] > time() + self::LIFETIME_SECONDS
            || ($destination['base_path'] ?? null) !== $this->config->path()) {
            return null;
        }
        return $this->config->path('admin/comenzi/' . $destination['order_id'] . '/');
    }
}
