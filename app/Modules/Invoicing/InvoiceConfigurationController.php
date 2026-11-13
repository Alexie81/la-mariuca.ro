<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Admin\AdminNotificationService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class InvoiceConfigurationController
{
    private const PATH = 'admin/configurator-factura/';

    public function __construct(
        private readonly InvoiceConfigurationService $service,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page();
    }

    /** @param array<string,string> $args */
    public function form(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try { return $this->renderForm($request, $args); }
        catch (InvoiceConfigurationException $error) { return $this->page($error->getMessage(), $error->status); }
    }

    /** @param array<string,string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $action = $args['action'] ?? 'nou';
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        try {
            switch ($action) {
                case 'setari': $this->service->saveDefaults($input, $actor); $message = 'Setările pentru facturile noi au fost salvate.'; break;
                case 'sterge': $this->service->delete((int) $args['id'], $input, $actor); $message = 'Seria a fost eliminată. Facturile emise rămân neschimbate.'; break;
                case 'folosire': $this->service->changeUse((int) $args['id'], $input, $actor); $message = 'Seria a fost aleasă pentru următoarea factură.'; break;
                default: $this->service->saveSeries($input, isset($args['id']) ? (int) $args['id'] : null, $actor); $message = 'Seria a fost salvată.';
            }
            if ($this->ajax($request)) {
                return $this->json(['ok'=>true,'message'=>$message,'html'=>(string) $this->view->render('admin/partials/invoice-configuration-content.twig', $this->context())->getBody()]);
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path(self::PATH));
        } catch (InvoiceConfigurationException $error) {
            $message = $error->getMessage(); $status = $error->status;
        } catch (\Throwable) {
            $message = 'Nu am putut salva modificarea. Datele completate sunt păstrate. Încearcă din nou.'; $status = 500;
        }
        if ($this->ajax($request)) { return $this->json(['ok'=>false,'error'=>$message], $status); }
        if ($action === 'setari' || $status === 404) { return $this->page($message, $status, $input); }
        return $this->renderForm($request, $args, $input, $message, $status);
    }

    /** @param array<string,string> $args @param array<string,mixed>|null $input */
    private function renderForm(ServerRequestInterface $request, array $args, ?array $input = null, string $error = '', int $status = 200): ResponseInterface
    {
        $item = isset($args['id']) ? $this->service->series((int) $args['id']) : null;
        $action = $args['action'] ?? 'nou';
        $context = $this->context();
        $active = $item !== null && (int) $item['id'] === (int) $context['settings']['active_series_id'];
        $values = $input ?? ['code'=>$item['code'] ?? '', 'label'=>$item['label'] ?? '', 'in_use'=>$active ? '1' : '0', 'replacement_id'=>''];
        foreach (['code','label','replacement_id','in_use'] as $field) { $values[$field] = is_scalar($values[$field] ?? null) ? (string) $values[$field] : ''; }
        $context += [
            'item'=>$item,'action'=>$action,'values'=>$values,'error'=>$error,'is_active'=>$active,
            'form_title'=>match ($action) { 'sterge'=>'Ștergi această serie?', 'folosire'=>$active ? 'Alege seria înlocuitoare' : 'Folosești această serie?', 'editare'=>'Editează seria', default=>'Adaugă o serie' },
            'form_action'=>$this->config->path(self::PATH . 'serii/' . ($item === null ? 'nou/' : $item['id'] . '/' . $action . '/')),
            'form_revision'=>is_scalar($input['revision'] ?? null) ? (string) $input['revision'] : $context['settings']['revision'],
        ];
        return $this->view->render($this->ajax($request) ? 'admin/partials/invoice-series-form.twig' : 'admin/pages/invoice-series-form.twig', $context, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed>|null $input */
    private function page(string $error = '', int $status = 200, ?array $input = null): ResponseInterface
    {
        $context = $this->context();
        if ($input !== null) {
            foreach (['due_days','default_notes','revision'] as $field) {
                if (is_scalar($input[$field] ?? null)) { $context['settings'][$field] = (string) $input[$field]; }
            }
        }
        return $this->view->render('admin/pages/invoice-configuration.twig', $context + ['error'=>$error], $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return $this->service->state() + [
            'admin_section'=>'invoice_configuration','admin_context'=>'Configurator factură',
            'admin_unread_count'=>$this->notifications->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
            'back_url'=>$this->config->path(self::PATH),
        ];
    }

    private function ajax(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest' || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
