<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($this->config->get('SESSION_NAME', 'lamariuca_session'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $this->config->path(''),
                'secure' => $this->config->bool('SESSION_SECURE'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            session_start();
        }
        return $handler->handle($request);
    }
}
