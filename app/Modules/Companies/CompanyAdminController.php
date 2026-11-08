<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use App\Modules\Admin\AdminNotificationService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response;

final class CompanyAdminController
{
    public function __construct(
        private readonly CompanyAdminService $companies,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $companies = array_map($this->presentCompany(...), $this->companies->companies());
        $requested = $request->getQueryParams()['firma'] ?? '';
        $selectedId = is_scalar($requested) ? (int) $requested : 0;
        $selected = $companies[0] ?? null;
        if ($selectedId > 0) {
            $selected = null;
            foreach ($companies as $company) {
                if ((int) $company['id'] === $selectedId) {
                    $selected = $company;
                    break;
                }
            }
            if ($selected === null) {
                return $this->notFound('Firma nu mai există.');
            }
        }
        $warehouseCount = array_sum(array_map(static fn(array $company): int => (int) $company['warehouse_count'], $companies));
        return $this->view->render('admin/pages/companies.twig', $this->context() + [
            'companies'=>$companies,
            'selected_company'=>$selected,
            'warehouses'=>$selected === null ? [] : $this->companies->warehouses((int) $selected['id']),
            'company_count'=>count($companies), 'warehouse_count'=>$warehouseCount,
            'totals'=>['company_count'=>count($companies),'warehouse_count'=>$warehouseCount],
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,string> $args */
    public function companyForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->form($request, 'company', isset($args['id']) ? (int) $args['id'] : null);
    }

    /** @param array<string,string> $args */
    public function companyDeleteForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->form($request, 'delete_company', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function warehouseForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->form($request, 'warehouse', (int) $args['companyId'], isset($args['id']) ? (int) $args['id'] : null);
    }

    /** @param array<string,string> $args */
    public function warehouseDeleteForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->form($request, 'delete_warehouse', (int) $args['companyId'], (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function saveCompany(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        try {
            $file = $request->getUploadedFiles()['stamp_file'] ?? null;
            if ($file !== null && !$file instanceof UploadedFileInterface) {
                throw new CompanyValidationException(['stamp_file'=>'Alege o singură imagine pentru ștampilă.']);
            }
            $savedId = $this->companies->saveCompany($body, $id, $file);
            return $this->success($request, 'Datele firmei au fost salvate.', $this->listUrl($savedId));
        } catch (CompanyNotFoundException $exception) {
            return $this->failure($request, $exception->getMessage(), [], 404);
        } catch (CompanyValidationException $exception) {
            if ($this->wantsJson($request)) {
                return $this->failure($request, $exception->getMessage(), $exception->errors, 422);
            }
            return $this->form($request, 'company', $id, null, $body, $exception->errors, 422);
        } catch (\Throwable) {
            $error = 'Datele firmei nu au putut fi salvate. Încearcă din nou.';
            if ($this->wantsJson($request)) {
                return $this->failure($request, $error, [], 500);
            }
            return $this->form($request, 'company', $id, null, $body, ['_form'=>$error], 500);
        }
    }

    /** @param array<string,string> $args */
    public function saveWarehouse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $companyId = (int) $args['companyId'];
        $id = isset($args['id']) ? (int) $args['id'] : null;
        try {
            $this->companies->saveWarehouse($companyId, $body, $id);
            return $this->success($request, 'Gestiunea a fost salvată.', $this->listUrl($companyId) . '#gestiuni');
        } catch (CompanyNotFoundException $exception) {
            return $this->failure($request, $exception->getMessage(), [], 404);
        } catch (CompanyValidationException $exception) {
            if ($this->wantsJson($request)) {
                return $this->failure($request, $exception->getMessage(), $exception->errors, 422);
            }
            return $this->form($request, 'warehouse', $companyId, $id, $body, $exception->errors, 422);
        } catch (\Throwable) {
            $error = 'Gestiunea nu a putut fi salvată. Încearcă din nou.';
            if ($this->wantsJson($request)) {
                return $this->failure($request, $error, [], 500);
            }
            return $this->form($request, 'warehouse', $companyId, $id, $body, ['_form'=>$error], 500);
        }
    }

    /** @param array<string,string> $args */
    public function deleteCompany(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->companies->deleteCompany((int) $args['id']);
            return $this->success($request, 'Firma și gestiunile ei au fost șterse.', $this->listUrl());
        } catch (CompanyNotFoundException $exception) {
            return $this->failure($request, $exception->getMessage(), [], 404);
        } catch (\Throwable) {
            return $this->failure($request, 'Firma nu a putut fi ștearsă. Încearcă din nou.', [], 500);
        }
    }

    /** @param array<string,string> $args */
    public function deleteWarehouse(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->companies->deleteWarehouse((int) $args['companyId'], (int) $args['id']);
            return $this->success($request, 'Gestiunea a fost ștearsă.', $this->listUrl((int) $args['companyId']) . '#gestiuni');
        } catch (CompanyNotFoundException $exception) {
            return $this->failure($request, $exception->getMessage(), [], 404);
        } catch (\Throwable) {
            return $this->failure($request, 'Gestiunea nu a putut fi ștearsă. Încearcă din nou.', [], 500);
        }
    }

    /** @param array<string,string> $args */
    public function stamp(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $path = $this->companies->stampPath((int) $args['id']);
            if ($path === null) {
                return $this->notFound('Ștampila nu este disponibilă.');
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                return $this->notFound('Ștampila nu este disponibilă.');
            }
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', 'image/png')->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Disposition', 'inline; filename="stampila.png"');
        } catch (CompanyNotFoundException $exception) {
            return $this->notFound($exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed>|null $submitted
     * @param array<string,string> $errors
     */
    private function form(ServerRequestInterface $request, string $kind, ?int $companyId, ?int $warehouseId = null, ?array $submitted = null, array $errors = [], int $status = 200): ResponseInterface
    {
        try {
            $company = $companyId === null ? null : $this->presentCompany($this->companies->company($companyId));
            $warehouse = $warehouseId === null ? null : $this->companies->warehouse($companyId ?? 0, $warehouseId);
            $isWarehouse = in_array($kind, ['warehouse','delete_warehouse'], true);
            $firstCompany = count($this->companies->companies()) === 0;
            $firstWarehouse = (int) ($company['warehouse_count'] ?? 0) === 0;
            $values = $isWarehouse ? ($warehouse ?? ['name'=>'','is_default'=>$firstWarehouse]) : ($company ?? ['country'=>'România','is_default'=>$firstCompany]);
            if ($submitted !== null) {
                foreach ($submitted as $key => $value) {
                    $values[$key] = is_scalar($value) || $value === null ? $value : '';
                }
                $values['is_default'] = $submitted['is_default'] ?? false;
                $values['remove_stamp'] = $submitted['remove_stamp'] ?? false;
            }
            $base = 'admin/detaliile-firmei/';
            if ($isWarehouse) {
                $path = $base . $companyId . '/gestiuni/' . ($warehouseId === null ? 'nou/' : $warehouseId . ($kind === 'delete_warehouse' ? '/sterge/' : '/editare/'));
            } else {
                $path = $base . ($companyId === null ? 'nou/' : $companyId . ($kind === 'delete_company' ? '/sterge/' : '/editare/'));
            }
            $title = match ($kind) {
                'delete_company'=>'Ștergi această firmă?',
                'delete_warehouse'=>'Ștergi această gestiune?',
                'warehouse'=>$warehouseId === null ? 'Adaugă o gestiune' : 'Editează gestiunea',
                default=>$companyId === null ? 'Adaugă o firmă' : 'Editează datele firmei',
            };
            $modal = str_ends_with($request->getUri()->getPath(), '/modal/');
            return $this->view->render($modal ? 'admin/partials/company-form-content.twig' : 'admin/pages/company-form.twig', $this->context() + [
                'form_kind'=>$kind, 'company'=>$company, 'warehouse'=>$warehouse, 'values'=>$values, 'errors'=>$errors,
                'form_action'=>$this->config->path($path), 'back_url'=>$this->listUrl($companyId) . ($isWarehouse ? '#gestiuni' : ''),
                'form_title'=>$title, 'is_edit'=>$isWarehouse ? $warehouseId !== null : $companyId !== null,
                'first_company'=>$firstCompany, 'first_warehouse'=>$firstWarehouse, 'is_modal'=>$modal,
            ], $status)->withHeader('Cache-Control', 'no-store');
        } catch (CompanyNotFoundException $exception) {
            return $this->notFound($exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function presentCompany(array $company): array
    {
        $company['stamp_url'] = !empty($company['stamp_path']) ? $this->config->path('admin/detaliile-firmei/' . $company['id'] . '/stampila/?v=' . rawurlencode((string) $company['stamp_path'])) : null;
        $company['share_capital'] = $company['share_capital_bani'] === null ? '' : number_format((int) $company['share_capital_bani'] / 100, 2, ',', '');
        return $company;
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return ['admin_section'=>'companies','admin_context'=>'Detaliile firmei','admin_unread_count'=>$this->notifications->unreadCount((int) ($this->auth->user()['id'] ?? 0))];
    }

    private function listUrl(?int $companyId = null): string
    {
        return $this->config->path('admin/detaliile-firmei/' . ($companyId === null ? '' : '?firma=' . $companyId));
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    private function success(ServerRequestInterface $request, string $message, string $redirect): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            return $this->json(['success'=>true,'redirect_url'=>$redirect,'message'=>$message]);
        }
        Flash::set('success', $message);
        return $this->view->redirect($redirect);
    }

    /** @param array<string,string> $errors */
    private function failure(ServerRequestInterface $request, string $message, array $errors, int $status): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            return $this->json(['success'=>false,'message'=>$message,'errors'=>$errors], $status);
        }
        return $this->notFound($message, $status);
    }

    private function notFound(string $message, int $status = 404): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($message);
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
