<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Admin\AdminNotificationService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class InvoiceAutomationController
{
    public function __construct(
        private readonly InvoiceAutomationService $service,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return $this->json(['ok'=>true,'settings'=>$this->service->settings()]);
        }
        return $this->page();
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $ajax = str_contains($request->getHeaderLine('Accept'), 'application/json') || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
        try {
            $settings = $this->service->save((array) ($request->getParsedBody() ?? []), (int) ($this->auth->user()['id'] ?? 0));
            return $ajax ? $this->json(['ok'=>true,'settings'=>$settings]) : $this->view->redirect($this->config->path('admin/automatizari/'));
        } catch (InvoiceConfigurationException $error) {
            $message = $error->getMessage(); $status = $error->status;
        } catch (\Throwable) {
            $message = 'Nu am putut salva setările. Verifică legătura și încearcă din nou.'; $status = 500;
        }
        return $ajax ? $this->json(['ok'=>false,'error'=>$message,'settings'=>$this->service->settings()], $status) : $this->page($message, $status);
    }

    private function page(string $error = '', int $status = 200): ResponseInterface
    {
        return $this->view->render('admin/pages/invoice-automations.twig', [
            'admin_section'=>'invoice_automations','admin_context'=>'Automatizări',
            'admin_unread_count'=>$this->notifications->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
            'settings'=>$this->service->settings(),'error'=>$error,
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
