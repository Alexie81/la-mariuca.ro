<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Security\AdminRememberMe;
use App\Shared\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminRememberMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth, private readonly AdminRememberMe $rememberMe)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rotated = null;
        $cookie = (string) ($request->getCookieParams()[AdminRememberMe::COOKIE_NAME] ?? '');
        $isAdminPath = preg_match('~(?:^|/)admin(?:/|$)~', $request->getUri()->getPath()) === 1;
        if ($isAdminPath && $this->auth->user() === null && $cookie !== '') {
            $rotated = $this->rememberMe->restore($cookie, $request->getHeaderLine('User-Agent'));
        }
        $response = $handler->handle($request);
        if (is_string($rotated)) {
            return $this->rememberMe->withCookie($response, $rotated);
        }
        if ($rotated === false) {
            return $this->rememberMe->withoutCookie($response);
        }

        return $response;
    }
}
