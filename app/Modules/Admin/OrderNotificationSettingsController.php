<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Orders\OrderNotificationSettingsService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class OrderNotificationSettingsController
{
    public function __construct(
        private readonly OrderNotificationSettingsService $settings,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($this->settings->recipients(), str_ends_with($request->getUri()->getPath(), '/modal/'));
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $recipients = $body['recipients'] ?? [];
        $ajax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
        try {
            if (!is_array($recipients) && !is_string($recipients)) {
                throw new \InvalidArgumentException('Introdu adrese de email valide.');
            }
            $this->settings->save($recipients, (int) ($this->auth->user()['id'] ?? 0));
            $saved = $this->settings->recipients();
            $message = $saved === []
                ? 'Setările au fost salvate. Notificările interne prin email sunt oprite.'
                : 'Adresele au fost salvate. Comenzile noi vor ajunge la echipa ta.';
            if ($ajax) {
                return $this->json(['ok' => true, 'message' => $message, 'recipients' => $saved]);
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/comenzi/'));
        } catch (\InvalidArgumentException | \DomainException $exception) {
            $error = $exception->getMessage();
        } catch (\Throwable) {
            $error = 'Setările nu au putut fi salvate. Încearcă din nou.';
        }
        if ($ajax) {
            return $this->json(['ok' => false, 'error' => $error], 422);
        }
        $values = is_array($recipients) ? array_values(array_filter($recipients, 'is_string')) : (is_string($recipients) ? [$recipients] : []);
        return $this->render(array_slice($values, 0, 10), false, $error, 422);
    }

    /** @param list<string> $recipients */
    private function render(array $recipients, bool $modal, string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render($modal ? 'admin/partials/order-notification-settings.twig' : 'admin/pages/order-notification-settings.twig', [
            'notification_recipients' => $recipients,
            'notification_error' => $error,
            'admin_section' => 'orders',
            'admin_context' => 'Notificări de comenzi',
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
