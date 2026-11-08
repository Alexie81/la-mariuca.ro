<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Payments\PaymentMethodSettingsService;
use App\Modules\Payments\PaymentFeeService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class PaymentMethodSettingsController
{
    public function __construct(
        private readonly PaymentMethodSettingsService $settings,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
        private readonly PaymentFeeService $fees,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render();
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $ajax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
        try {
            $cod = $this->checkbox($body['cod_enabled'] ?? '0');
            $stripe = $this->checkbox($body['stripe_enabled'] ?? '0');
            $this->settings->save($cod, $stripe, (int) ($this->auth->user()['id'] ?? 0));
            $message = 'Metodele de plată au fost salvate.';
            if ($ajax) { return $this->json(['ok' => true, 'message' => $message, 'payment_methods' => $this->settings->settings()]); }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/metode-plata/'));
        } catch (\InvalidArgumentException | \DomainException $exception) {
            $error = $exception->getMessage();
        } catch (\Throwable) {
            $error = 'Metodele de plată nu au putut fi salvate. Încearcă din nou.';
        }
        if ($ajax) { return $this->json(['ok' => false, 'error' => $error], 422); }
        return $this->render($error, 422);
    }

    private function checkbox(mixed $value): bool
    {
        if (!in_array($value, ['0', '1', 0, 1, false, true], true)) {
            throw new \InvalidArgumentException('Verifică opțiunile metodelor de plată și încearcă din nou.');
        }
        return in_array($value, ['1', 1, true], true);
    }

    public function feeForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderFee($request, $args);
    }

    public function saveFee(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            if (($args['action'] ?? '') === 'sterge') {
                $this->fees->delete((int) ($args['id'] ?? 0), (int) ($this->auth->user()['id'] ?? 0));
                $message = 'Costul a fost eliminat.';
            } else {
                $this->fees->save($body, (int) ($this->auth->user()['id'] ?? 0), isset($args['id']) ? (int) $args['id'] : null);
                $message = 'Costul a fost salvat.';
            }
            if ($this->ajax($request)) {
                return $this->json(['ok' => true, 'message' => $message, 'html' => (string) $this->view->render('admin/partials/payment-fees-list.twig', ['payment_fees' => $this->fees->all()])->getBody()]);
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/metode-plata/'));
        } catch (\InvalidArgumentException | \DomainException $error) {
            $message = $error->getMessage();
        } catch (\Throwable) {
            $message = 'Modificarea nu a putut fi salvată. Încearcă din nou.';
        }
        if ($this->ajax($request)) { return $this->json(['ok' => false, 'error' => $message], 422); }
        return $this->renderFee($request, $args, $message, $body);
    }

    private function ajax(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function renderFee(ServerRequestInterface $request, array $args, string $error = '', array $values = []): ResponseInterface
    {
        $fee = null;
        if (isset($args['id'])) {
            foreach ($this->fees->all() as $row) { if ((int) $row['id'] === (int) $args['id']) { $fee = $row; break; } }
            if ($fee === null) { return new Response(404); }
        }
        $delete = ($args['action'] ?? '') === 'sterge';
        $query = $request->getQueryParams();
        $method = $fee['payment_method'] ?? (($query['metoda'] ?? '') === 'stripe' ? 'stripe' : 'cod');
        if ($values !== []) {
            foreach (['payment_method', 'name', 'price'] as $field) {
                $values[$field] = is_scalar($values[$field] ?? null) ? mb_substr((string) $values[$field], 0, 200) : '';
            }
            if (!in_array($values['payment_method'], ['cod', 'stripe'], true)) { $values['payment_method'] = $method; }
        }
        $action = 'admin/metode-plata/costuri/' . ($fee === null ? 'nou/' : (int) $fee['id'] . '/' . ($delete ? 'sterge/' : 'editare/'));
        return $this->view->render($this->ajax($request) ? 'admin/partials/payment-fee-form.twig' : 'admin/pages/payment-fee-form.twig', [
            'admin_section' => 'payment_methods', 'admin_context' => 'Costuri operaționale',
            'fee' => $fee, 'is_delete' => $delete, 'error' => $error,
            'values' => $values ?: ['payment_method' => $method, 'name' => $fee['name'] ?? '', 'price' => isset($fee['amount_bani']) ? number_format((int) $fee['amount_bani'] / 100, 2, ',', '') : ''],
            'form_action' => $this->config->path($action), 'back_url' => $this->config->path('admin/metode-plata/'),
            'form_title' => $delete ? 'Elimini acest cost?' : ($fee === null ? 'Adaugă un cost' : 'Editează costul'),
        ], $error === '' ? 200 : 422)->withHeader('Cache-Control', 'no-store');
    }

    private function render(string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('admin/pages/payment-methods.twig', [
            'payment_methods' => $this->settings->settings(),
            'payment_fees' => $this->fees->all(),
            'payment_error' => $error,
            'admin_section' => 'payment_methods',
            'admin_context' => 'Metode de plată',
            'admin_unread_count' => $this->notifications->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ], $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
