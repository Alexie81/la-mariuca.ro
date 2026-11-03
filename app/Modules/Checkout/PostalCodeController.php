<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Shared\Support\RomanianPostalCodes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PostalCodeController
{
    public function __construct(private readonly RomanianPostalCodes $postalCodes)
    {
    }

    public function lookup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $postalCode = is_string($query['cod'] ?? null) ? trim($query['cod']) : '';
        try {
            $payload = [
                'postal_code' => $postalCode,
                'matches' => $this->postalCodes->lookup($postalCode),
                'source' => 'GeoNames', 'source_url' => 'https://www.geonames.org/',
                'precision' => 'locality',
            ];
            $status = 200;
        } catch (\InvalidArgumentException $exception) {
            $payload = ['error' => $exception->getMessage(), 'matches' => []];
            $status = 422;
        } catch (\RuntimeException $exception) {
            $payload = ['error' => $exception->getMessage(), 'matches' => []];
            $status = 503;
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', $status === 200 ? 'public, max-age=86400' : 'no-store');
    }
}
