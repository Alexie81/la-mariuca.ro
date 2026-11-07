<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class OrderTrackingController
{
    public function __construct(private readonly OrderTrackingService $tracking, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config) {}

    public function show(): ResponseInterface
    {
        return $this->page();
    }

    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        unset($_SESSION['tracked_order']);
        $body = (array) $request->getParsedBody();
        $number = is_string($body['order_number'] ?? null) ? trim($body['order_number']) : '';
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->allow('order-tracking-ip', $ip, 12, 900)) {
            return $this->page('Ai făcut mai multe încercări. Te rugăm să revii peste 15 minute.', $number, $email, 429)->withHeader('Retry-After', '900');
        }
        $orderId = $this->tracking->lookup($number, $email);
        if ($orderId === null) {
            return $this->page('Nu am găsit o comandă cu aceste date. Verifică numărul complet și adresa de email folosită la comandă.', $number, $email, 422);
        }
        return $this->grant($orderId);
    }

    /** @param array<string,string> $args */
    public function access(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->tracking->verifyLink((int) $args['id'], $args['signature'])) {
            unset($_SESSION['tracked_order']);
            return $this->page('Acest link nu mai este disponibil. Poți căuta comanda folosind numărul și emailul tău.', status: 404);
        }
        return $this->grant((int) $args['id']);
    }

    public function reset(): ResponseInterface
    {
        unset($_SESSION['tracked_order']);
        return $this->privateResponse($this->view->redirect($this->config->path('urmarire-comanda/')));
    }

    /** @param array<string,string> $args */
    public function invoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $orderId = $this->authorizedOrder();
        $document = $orderId === null ? null : $this->tracking->invoice($orderId, (int) $args['id']);
        if ($document === null) {
            return $this->privateResponse(new Response(404));
        }
        $handle = fopen($document['path'], 'rb');
        if ($handle === false) {
            return $this->privateResponse(new Response(404));
        }
        return $this->privateResponse((new Response())->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $document['name'] . '"'));
    }

    private function grant(int $id): ResponseInterface
    {
        $_SESSION['tracked_order'] = ['id'=>$id, 'expires'=>time() + 1800];
        return $this->privateResponse($this->view->redirect($this->config->path('urmarire-comanda/')));
    }

    private function authorizedOrder(): ?int
    {
        $access = $_SESSION['tracked_order'] ?? null;
        return is_array($access) && (int) ($access['expires'] ?? 0) > time() ? (int) $access['id'] : null;
    }

    private function page(string $error = '', string $number = '', string $email = '', int $status = 200): ResponseInterface
    {
        $id = $this->authorizedOrder();
        return $this->privateResponse($this->view->render('storefront/pages/order-tracking.twig', [
            'tracking_order'=>$id === null ? null : $this->tracking->details($id),
            'tracking_error'=>$error, 'tracking_number'=>mb_substr($number, 0, 100), 'tracking_email'=>mb_substr($email, 0, 254),
            'meta'=>['title'=>'Urmărire comandă · La Măriuca', 'noindex'=>true],
        ], $status));
    }

    private function privateResponse(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
