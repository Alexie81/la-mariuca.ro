<?php

declare(strict_types=1);

namespace App\Modules\Checkout;

use App\Modules\Cart\CartService;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeCheckoutService;
use App\Modules\Customers\CustomerAccountService;
use App\Modules\Shipping\ShippingMethodService;
use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout, private readonly CartService $cart, private readonly OrderService $orders, private readonly StripeCheckoutService $stripe, private readonly Auth $auth, private readonly CustomerAccountService $accountService, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config, private readonly ShippingMethodService $shippingMethods, private readonly OrderResultPresenter $orderResult)
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
        $oldInput = $_SESSION['checkout_old_input'] ?? null;
        unset($_SESSION['checkout_old_input']);
        $hasOldInput = is_array($oldInput);
        $values = $hasOldInput ? $oldInput : [];
        $cart = $this->cart->summary();
        $shippingMethods = $this->shippingMethods->activeForCheckout();
        foreach ($shippingMethods as &$shippingMethod) {
            $quote = $this->checkout->quoteShipping($cart, $shippingMethod);
            unset($quote['shipping_offer']);
            $shippingMethod += $quote;
        }
        unset($shippingMethod);
        $selectedMethod = $shippingMethods[0] ?? null;
        foreach ($shippingMethods as $shippingMethod) {
            if ((int) $shippingMethod['id'] === (int) ($values['shipping_method_id'] ?? 0)) {
                $selectedMethod = $shippingMethod;
                break;
            }
        }
        return $this->view->render('storefront/pages/checkout.twig', [
            'cart' => $cart, 'idempotency_key' => $_SESSION['checkout_idempotency'],
            'checkout_customer' => $customer,
            'checkout_values' => $values,
            'checkout_has_old_input' => $hasOldInput,
            'shipping_methods' => $shippingMethods,
            'default_shipping_method_id' => (int) ($shippingMethods[0]['id'] ?? 0),
            'selected_shipping_method_id' => (int) ($selectedMethod['id'] ?? 0),
            'checkout_totals' => [
                'shipping_bani' => (int) ($selectedMethod['shipping_bani'] ?? 0),
                'shipping_discount_bani' => (int) ($selectedMethod['shipping_discount_bani'] ?? 0),
                'total_bani' => (int) ($selectedMethod['total_bani'] ?? $cart['total_bani']),
                'shipping_name' => (string) ($selectedMethod['name'] ?? ''),
            ],
            'stripe_enabled' => $this->config->bool('STRIPE_ENABLED') && $this->config->get('STRIPE_SECRET_KEY') !== '',
            'meta' => ['title' => 'Finalizare comandă', 'noindex' => true],
        ]);
    }

    public function place(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        if (($data['checkout_action'] ?? null) === 'recalculate') {
            $this->rememberInput($data);
            return $this->view->redirect($this->config->path('finalizare-comanda/'));
        }
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->allow('checkout', $ip, 10, 3600)) {
            $this->rememberInput($data);
            Flash::set('error', 'Prea multe încercări. Încearcă din nou mai târziu.');
            return $this->view->redirect($this->config->path('finalizare-comanda/'));
        }
        try {
            $result = $this->checkout->place($data);
            unset($_SESSION['checkout_idempotency'], $_SESSION['checkout_old_input']);
            if ($result['payment_method'] === 'stripe') {
                try {
                    return $this->view->redirect($this->stripe->createSession($result['id'], $result['token'], $result['idempotency_key']), 303);
                } catch (\Throwable $exception) {
                    $this->stripe->markSessionFailure($result['id'], $exception->getMessage());
                }
            }
            return $this->view->redirect($this->config->path('comanda/' . $result['token'] . '/'));
        } catch (\Throwable $exception) {
            $this->rememberInput($data);
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($this->config->path('finalizare-comanda/'));
        }
    }

    /** @param array<string,mixed> $data */
    private function rememberInput(array $data): void
    {
        $values = [];
        foreach (['email', 'first_name', 'last_name', 'phone', 'address', 'city', 'county', 'postal_code', 'shipping_method_id', 'payment_method', 'saved_address_id'] as $name) {
            $values[$name] = is_scalar($data[$name] ?? null) ? mb_substr((string) $data[$name], 0, 2000) : '';
        }
        $values['terms'] = isset($data['terms']) ? '1' : '';
        $values['marketing'] = isset($data['marketing']) ? '1' : '';
        $_SESSION['checkout_old_input'] = $values;
    }

    public function saveAddress(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null || ($user['role'] ?? '') !== 'customer') {
            return $this->json(['ok' => false, 'message' => 'Autentifică-te în contul de client pentru a salva adresa.'], 401);
        }
        $userId = (int) $user['id'];
        if (!$this->limiter->allow('checkout-save-address', (string) $userId, 20, 3600)) {
            return $this->json(['ok' => false, 'message' => 'Ai încercat să salvezi prea multe adrese. Încearcă din nou mai târziu.'], 429);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $addressData = [];
        foreach (['label', 'first_name', 'last_name', 'phone', 'address', 'city', 'county', 'postal_code'] as $name) {
            $addressData[$name] = is_scalar($data[$name] ?? null) ? mb_substr((string) $data[$name], 0, 2000) : '';
        }
        if (trim($addressData['label']) === '') {
            $addressData['label'] = 'Adresă de livrare';
        }
        if (in_array($data['is_default'] ?? '', ['1', 'on', true], true)) {
            $addressData['is_default'] = '1';
        }
        try {
            $id = $this->accountService->saveAddress($userId, $addressData);
            $addresses = [];
            $savedAddress = null;
            foreach ($this->accountService->checkoutDefaults($userId)['addresses'] as $address) {
                $safeAddress = [
                    'id' => (int) $address['id'],
                    'label' => (string) $address['label'],
                    'is_default' => (int) $address['is_default'] === 1,
                    'details' => $address['details'],
                ];
                $addresses[] = $safeAddress;
                if ((int) $address['id'] === $id) {
                    $savedAddress = $safeAddress;
                }
            }
            return $this->json(['ok' => true, 'message' => 'Adresa a fost salvată în contul tău.', 'address' => $savedAddress, 'addresses' => $addresses], 201);
        } catch (\RuntimeException $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public function confirmation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = $this->orders->byPublicToken((string) $args['token']);
        if ($order === null) {
            return $this->view->render('storefront/pages/error.twig', ['title' => 'Comanda nu a fost găsită', 'message' => 'Linkul este invalid sau a expirat.', 'meta' => ['title' => 'Comandă indisponibilă', 'noindex' => true]], 404)
                ->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }
        $query = $request->getQueryParams();
        $returnStatus = is_string($query['plata'] ?? null) ? $query['plata'] : '';
        return $this->view->render('storefront/pages/order.twig', [
            'order' => $order,
            'order_result' => $this->orderResult->present($order, $returnStatus, (string) $args['token']),
            'meta' => ['title' => 'Comanda ' . $order['order_number'], 'noindex' => true],
        ])->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
