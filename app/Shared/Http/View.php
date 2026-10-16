<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use Twig\Environment;

final class View
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($this->twig->render($template, $data));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function redirect(string $url, int $status = 303): ResponseInterface
    {
        return (new Response($status))->withHeader('Location', $url);
    }
}
