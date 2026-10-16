<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Config\Config;
use App\Shared\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth, private readonly Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->hasRole(['administrator', 'operator'])) {
            return (new Response(303))->withHeader('Location', $this->config->path('cont/autentificare/'));
        }
        return $handler->handle($request);
    }
}
