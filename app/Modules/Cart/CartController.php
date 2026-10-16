<?php

declare(strict_types=1);

namespace App\Modules\Cart;

use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
        $personalization = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, 'option_') && is_scalar($value)) {
                $personalization[(string) $key] = (string) $value;
            }
        }
        try {
            $this->cart->add((int) ($data['variant_id'] ?? 0), (int) ($data['quantity'] ?? 1), $personalization);
            Flash::set('success', 'Produsul a fost adăugat în coș.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('cos/'));
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
