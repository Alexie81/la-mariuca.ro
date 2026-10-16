<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $path = $request->getUri()->getPath();
            if (!str_ends_with($path, '/stripe/webhook')) {
                $body = (array) ($request->getParsedBody() ?? []);
                $token = $body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
                if (!$this->csrf->validate(is_string($token) ? $token : null)) {
                    $response = new Response(419);
                    $response->getBody()->write('Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
                    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
                }
            }
        }
        return $handler->handle($request);
    }
}
