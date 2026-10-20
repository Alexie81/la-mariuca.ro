<?php

declare(strict_types=1);

namespace App\Modules\Cart;

use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class CartController
{
    public function __construct(private readonly CartService $cart, private readonly View $view, private readonly Config $config)
    {
    }

    public function show(): ResponseInterface
    {
        return $this->view->render('storefront/pages/cart.twig', ['cart' => $this->cart->summary(), 'meta' => ['title' => 'Coșul meu', 'noindex' => true]]);
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $wantsJson = $this->wantsJson($request);
        $personalization = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, 'option_') && is_scalar($value)) {
                $personalization[(string) $key] = (string) $value;
            }
        }
        try {
            $variantId = (int) ($data['variant_id'] ?? 0);
            $toggle = (string) ($data['toggle_cart'] ?? '') === '1';
            $state = 'added';
            if ($toggle) {
                $state = $this->cart->toggle($variantId, (int) ($data['quantity'] ?? 1), $personalization);
            } else {
                $this->cart->add($variantId, (int) ($data['quantity'] ?? 1), $personalization);
            }
            $message = $state === 'removed' ? 'Produsul a fost scos din coș.' : 'Produsul a fost adăugat în coș.';
            if ($wantsJson) {
                $summary = $this->cart->summary();
                return $this->json([
                    'ok' => true,
                    'message' => $message,
                    'state' => $state,
                    'variant_id' => $variantId,
                    'cart_count' => $summary['count'],
                    'cart_total_bani' => $summary['total_bani'],
                ]);
            }
            Flash::set('success', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
            }
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('cos/'));
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        try {
            $this->cart->update((int) ($data['item_id'] ?? 0), (int) ($data['quantity'] ?? 0));
            Flash::set('success', 'Coșul a fost actualizat.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('cos/'));
    }

    public function coupon(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        try {
            $this->cart->applyCoupon((string) ($data['coupon_code'] ?? ''));
            Flash::set('success', trim((string) ($data['coupon_code'] ?? '')) === '' ? 'Cuponul a fost eliminat.' : 'Cuponul a fost aplicat.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('cos/'));
    }
}
