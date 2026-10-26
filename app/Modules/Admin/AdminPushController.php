<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class AdminPushController
{
    public function __construct(private readonly AdminPushService $push, private readonly Auth $auth, private readonly View $view, private readonly Config $config)
    {
    }

    public function manifest(): ResponseInterface
    {
        return $this->json([
            'id' => $this->config->path('admin'),
            'name' => 'Administrare La Măriuca Boutique',
            'short_name' => 'La Măriuca Admin',
            'description' => 'Panoul de administrare La Măriuca Boutique',
            'start_url' => $this->config->path('admin'),
            'scope' => $this->config->path(''),
            'display' => 'standalone',
            'background_color' => '#fffaf7',
            'theme_color' => '#d75d68',
            'icons' => [
                ['src' => $this->config->path('assets/images/favicon-lm-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $this->config->path('assets/images/apple-touch-icon-lm.png'), 'sizes' => '180x180', 'type' => 'image/png'],
            ],
        ])->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $subscription = is_array($body['subscription'] ?? null) ? $body['subscription'] : $body;
            $device = is_array($body['device'] ?? null) ? $body['device'] : [];
            $adminId = $this->adminId();
            $id = $this->push->subscribe($adminId, $subscription, $request->getHeaderLine('User-Agent'), $device);
            return $this->json([
                'ok' => true,
                'subscription_id' => $id,
                'device' => $this->push->device($adminId, $id),
                'message' => 'Dispozitivul este conectat.',
            ]);
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->push->unsubscribe($this->adminId(), (string) ($body['endpoint'] ?? ''));
        return $this->json(['ok' => true, 'message' => 'Notificările au fost dezactivate pe acest dispozitiv.']);
    }

    public function test(): ResponseInterface
    {
        if (!$this->push->isConfigured()) {
            return $this->json(['ok' => false, 'message' => 'Web Push nu este configurat pe acest mediu.'], 422);
        }
        $this->push->queueTest($this->adminId());
        $result = $this->push->processPending(30);
        $ok = $result['sent'] > 0;
        return $this->json(['ok' => $ok, 'message' => $ok ? 'Notificarea de test a fost trimisă.' : 'Nu am găsit un dispozitiv activ sau serviciul push nu a răspuns.', 'delivery' => $result], $ok ? 200 : 422);
    }

    public function removeDevice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->push->removeDevice($this->adminId(), (int) ($args['id'] ?? 0));
        Flash::set('success', 'Dispozitivul a fost eliminat din notificările push.');
        return $this->view->redirect($this->config->path('admin/notificari/'));
    }

    private function adminId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
