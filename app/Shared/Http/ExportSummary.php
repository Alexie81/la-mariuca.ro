<?php
declare(strict_types=1);
namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/** Read-only export metadata, after the controller has authorized and selected its data. */
final class ExportSummary
{
    public static function requested(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['export_info'] ?? '') === '1';
    }

    public static function response(int $documents, int $positions, int $files, string $label = 'Documente'): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['ok'=>true, 'export'=>[
            'documents'=>$documents, 'positions'=>$positions, 'files'=>$files, 'label'=>$label,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','private, no-store');
    }
}
