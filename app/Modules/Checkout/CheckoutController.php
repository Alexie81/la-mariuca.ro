<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Modules\Cart\CartService;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeCheckoutService;
use App\Modules\Customers\CustomerAccountService;
use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout, private readonly CartService $cart, private readonly OrderService $orders, private readonly StripeCheckoutService $stripe, private readonly Auth $auth, private readonly CustomerAccountService $accountService, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
    {
    }

    public function show(): ResponseInterface
    {
        if (!isset($_SESSION['checkout_idempotency'])) {
            $_SESSION['checkout_idempotency'] = bin2hex(random_bytes(32));
        }
        $customer = null;
        $user = $this->auth->user();
        if ($user !== null && ($user['role'] ?? '') === 'customer') {
            try {
                $customer = $this->accountService->checkoutDefaults((int) $user['id']);
            } catch (\RuntimeException) {
                $customer = null;
            }
        }
        return $this->view->render('storefront/pages/checkout.twig', [
            'cart' => $this->cart->summary(), 'idempotency_key' => $_SESSION['checkout_idempotency'],
            'checkout_customer' => $customer,
            'stripe_enabled' => $this->config->bool('STRIPE_ENABLED') && $this->config->get('STRIPE_SECRET_KEY') !== '',
            'meta' => ['title' => 'Finalizare comandă', 'noindex' => true],
        ]);
    }

    public function place(ServerRequestInterface $request): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->allow('checkout', $ip, 10, 3600)) {
            Flash::set('error', 'Prea multe încercări. Încearcă din nou mai târziu.');
            return $this->view->redirect($this->config->path('finalizare-comanda/'));
        }
        try {
            $result = $this->checkout->place((array) ($request->getParsedBody() ?? []));
            unset($_SESSION['checkout_idempotency']);
            if ($result['payment_method'] === 'stripe') {
                try {
                    return $this->view->redirect($this->stripe->createSession($result['id'], $result['token'], $result['idempotency_key']), 303);
                } catch (\Throwable $exception) {
                    $this->stripe->markSessionFailure($result['id'], $exception->getMessage());
                    Flash::set('error', 'Sesiunea de plată nu a putut fi creată. Comanda a intrat în verificare manuală și nu va fi expediată.');
                }
            }
            return $this->view->redirect($this->config->path('comanda/' . $result['token'] . '/'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($this->config->path('finalizare-comanda/'));
        }
    }

    public function confirmation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = $this->orders->byPublicToken((string) $args['token']);
        if ($order === null) {
            return $this->view->render('storefront/pages/error.twig', ['title' => 'Comanda nu a fost găsită', 'message' => 'Linkul este invalid sau a expirat.', 'meta' => ['title' => 'Comandă indisponibilă', 'noindex' => true]], 404);
        }
        return $this->view->render('storefront/pages/order.twig', ['order' => $order, 'meta' => ['title' => 'Comanda ' . $order['order_number'], 'noindex' => true]]);
    }
}
