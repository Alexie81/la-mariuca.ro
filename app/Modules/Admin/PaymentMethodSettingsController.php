<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Payments\PaymentMethodSettingsService;
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

    private function render(string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('admin/pages/payment-methods.twig', [
            'payment_methods' => $this->settings->settings(),
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
