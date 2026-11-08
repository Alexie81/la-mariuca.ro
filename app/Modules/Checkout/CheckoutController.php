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
    public function __construct(private readonly CheckoutService $checkout, private readonly CartService $cart, private readonly OrderService $orders, private readonly StripeCheckoutService $stripe, private readonly Auth $auth, private readonly CustomerAccountService $accountService, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config, private readonly ShippingMethodService $shippingMethods, private readonly OrderResultPresenter $orderResult, private readonly CheckoutAttemptService $attempts)
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
        $pendingAttempt = $this->attempts->findPendingForCart($this->cart->cartId());
        $pendingPayment = null;
        if ($pendingAttempt !== null) {
            $pendingToken = $this->attempts->publicToken($pendingAttempt);
            $pendingPayment = [
                'result_path' => $this->config->path('comanda/' . rawurlencode($pendingToken) . '/'),
                'resume_path' => $this->config->path('plata/' . rawurlencode($pendingToken) . '/reincearca/'),
                'cancel_path' => $this->config->path('plata/' . rawurlencode($pendingToken) . '/anuleaza/'),
            ];
        }
        $paymentOptions = $this->checkout->paymentOptions($cart);
        $selectedPayment = (string) ($values['payment_method'] ?? '');
        if (!($paymentOptions['methods'][$selectedPayment]['available'] ?? false)) {
            $selectedPayment = $paymentOptions['preferred'];
        }
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
        $selectedFees = (int) ($paymentOptions['methods'][$selectedPayment]['fee_bani'] ?? 0);
        return $this->view->render('storefront/pages/checkout.twig', [
            'cart' => $cart, 'idempotency_key' => $_SESSION['checkout_idempotency'],
            'pending_payment_attempt' => $pendingPayment,
            'checkout_customer' => $customer,
            'checkout_values' => $values,
            'checkout_has_old_input' => $hasOldInput,
            'shipping_methods' => $shippingMethods,
            'default_shipping_method_id' => (int) ($shippingMethods[0]['id'] ?? 0),
            'selected_shipping_method_id' => (int) ($selectedMethod['id'] ?? 0),
            'checkout_totals' => [
                'shipping_bani' => (int) ($selectedMethod['shipping_bani'] ?? 0),
                'shipping_discount_bani' => (int) ($selectedMethod['shipping_discount_bani'] ?? 0),
                'total_bani' => (int) ($selectedMethod['total_bani'] ?? $cart['total_bani']) + $selectedFees,
                'payment_fee_bani' => $selectedFees,
                'shipping_name' => (string) ($selectedMethod['name'] ?? ''),
            ],
            'payment_options' => $paymentOptions,
            'selected_payment_method' => $selectedPayment,
            'stripe_test_mode' => $this->config->get('STRIPE_MODE', 'test') === 'test',
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
                    if (isset($result['attempt_id'])) {
                        return $this->view->redirect($this->stripe->createAttemptSession($result['attempt_id'], $result['token'], $result['idempotency_key']), 303);
                    }
                    return $this->view->redirect($this->stripe->createSession($result['id'], $result['token'], $result['idempotency_key']), 303);
                } catch (\Throwable $exception) {
                    if (isset($result['attempt_id'])) {
                        $this->stripe->markAttemptSessionFailure($result['attempt_id']);
                    } else {
                        $this->stripe->markSessionFailure($result['id'], $exception->getMessage());
                    }
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
        $token = (string) $args['token'];
        $order = $this->orders->byPublicToken($token);
        $attempt = null;
        if ($order === null) {
            $attempt = $this->attempts->byToken($token);
            if ($attempt !== null) {
                // A paid webhook may commit between the first lookup and the attempt lookup.
                $order = $this->orders->byPublicToken($token);
                if ($order === null) { $order = $this->attempts->snapshot($attempt); }
                else { $attempt = null; }
            }
        }
        if ($order === null) {
            return $this->view->render('storefront/pages/error.twig', ['title' => 'Comanda nu a fost găsită', 'message' => 'Linkul este invalid sau a expirat.', 'meta' => ['title' => 'Comandă indisponibilă', 'noindex' => true]], 404)
                ->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }
        $query = $request->getQueryParams();
        $returnStatus = is_string($query['plata'] ?? null) ? $query['plata'] : '';
        return $this->view->render('storefront/pages/order.twig', [
            'order' => $order,
            'is_payment_attempt' => $attempt !== null,
            'order_result' => $this->orderResult->present($order, $returnStatus, $token, $attempt !== null),
            'payment_attempt_actions' => $attempt !== null && $attempt['status'] !== 'completed' ? [
                'resume_path' => $this->config->path('plata/' . rawurlencode($token) . '/reincearca/'),
                'cancel_path' => $attempt['status'] === 'pending' ? $this->config->path('plata/' . rawurlencode($token) . '/anuleaza/') : null,
            ] : null,
            'meta' => ['title' => $attempt !== null ? 'Starea plății' : 'Comanda ' . $order['order_number'], 'noindex' => true],
        ])->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function resumePayment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['token'];
        $attempt = $this->attempts->byToken($token);
        if ($attempt === null) { return $this->confirmation($request, $response, $args); }
        try {
            if ($attempt['status'] === 'pending') {
                return $this->view->redirect($this->stripe->createAttemptSession((int) $attempt['id'], $token, (string) $attempt['idempotency_key']), 303);
            }
            if (in_array($attempt['status'], ['failed', 'expired'], true)) {
                if ($this->cart->cartId() !== (int) $attempt['cart_id']) {
                    Flash::set('error', 'Deschide plata în browserul în care ai pregătit coșul, pentru a relua aceleași produse.');
                } else {
                    $this->rememberAttemptInput($attempt);
                    return $this->view->redirect($this->config->path('finalizare-comanda/'), 303);
                }
            }
        } catch (\Throwable) {
            Flash::set('error', 'Plata nu poate fi reluată momentan. Verifică starea ei sau contactează-ne.');
        }
        return $this->view->redirect($this->config->path('comanda/' . rawurlencode($token) . '/'), 303);
    }

    public function cancelPayment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) $args['token'];
        $attempt = $this->attempts->byToken($token);
        if ($attempt === null) { return $this->confirmation($request, $response, $args); }
        try {
            if ($attempt['status'] === 'completed') {
                return $this->view->redirect($this->config->path('comanda/' . rawurlencode($token) . '/'), 303);
            }
            $this->stripe->cancelAttemptSession((int) $attempt['id'], $token);
            $this->rememberAttemptInput($attempt);
            return $this->view->redirect($this->config->path('finalizare-comanda/'), 303);
        } catch (\Throwable) {
            Flash::set('error', 'Verificăm starea plății înainte de a elibera coșul. Nu iniția o altă plată până la confirmare.');
            return $this->view->redirect($this->config->path('comanda/' . rawurlencode($token) . '/?plata=verificare'), 303);
        }
    }

    /** @param array<string,mixed> $attempt */
    private function rememberAttemptInput(array $attempt): void
    {
        $snapshot = $this->attempts->snapshot($attempt);
        $address = is_array($snapshot['shipping_address'] ?? null) ? $snapshot['shipping_address'] : [];
        $this->rememberInput($snapshot + $address);
        unset($_SESSION['checkout_idempotency']);
    }
}
