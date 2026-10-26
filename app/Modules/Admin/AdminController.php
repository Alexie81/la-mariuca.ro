<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Media\MediaService;
use App\Modules\Orders\OrderService;
use App\Modules\Reviews\ProductReviewService;
use App\Modules\Content\ContentAdminService;
use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Security\AdminRememberMe;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use App\Shared\Support\Money;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function __construct(private readonly PDO $pdo, private readonly AdminDashboardService $dashboardService, private readonly CatalogRepository $catalog, private readonly CatalogAdminService $catalogAdmin, private readonly OrderService $orders, private readonly ProductReviewService $reviews, private readonly MediaService $media, private readonly ContentAdminService $contentAdmin, private readonly Auth $auth, private readonly AdminRememberMe $rememberMe, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
    {
    }

    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->auth->hasRole(['administrator', 'operator'])) {
            return $this->view->render('admin/pages/login.twig');
        }
        $admin = $this->auth->user();
        $overview = $this->dashboardService->overview($request->getQueryParams());
        $bucharestNow = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Bucharest'));
        $hour = (int) $bucharestNow->format('G');
        $greeting = $hour < 12 ? 'Bună dimineața' : ($hour < 18 ? 'Bună ziua' : 'Bună seara');

        return $this->view->render('admin/pages/dashboard.twig', [
            ...$overview,
            'admin_user' => $admin,
            'greeting' => $greeting,
            'today' => $bucharestNow->format('d.m.Y'),
        ]);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->hasRole(['administrator', 'operator'])) {
            return $this->view->redirect($this->config->path('admin'));
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $identity = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown') . '|' . $email;
        if (!$this->limiter->allow('admin-login', $identity, 6, 900)) {
            Flash::set('error', 'Datele nu sunt valide sau încercările sunt temporar limitate.');
            return $this->view->redirect($this->config->path('admin'));
        }
        $result = $this->auth->attemptAdmin($email, (string) ($data['password'] ?? ''));
        if ($result === 'invalid') {
            Flash::set('error', 'Datele nu sunt valide sau contul nu are acces administrativ.');
            return $this->view->redirect($this->config->path('admin'));
        }
        $remember = isset($data['remember']) && in_array((string) $data['remember'], ['1', 'on'], true);
        if ($result === 'totp') {
            $_SESSION['admin_remember_after_2fa'] = $remember;
            return $this->view->redirect($this->config->path('cont/totp/'));
        }

        unset($_SESSION['admin_remember_after_2fa']);
        Flash::set('success', 'Bine ai revenit în panoul de administrare.');
        $response = $this->view->redirect($this->config->path('admin'));
        $user = $this->auth->user();
        if ($remember && $user !== null) {
            return $this->rememberMe->withCookie($response, $this->rememberMe->issue((int) $user['id'], $request->getHeaderLine('User-Agent')));
        }
        $oldCookie = (string) ($request->getCookieParams()[AdminRememberMe::COOKIE_NAME] ?? '');
        if ($oldCookie !== '') {
            $this->rememberMe->revoke($oldCookie);
            return $this->rememberMe->withoutCookie($response);
        }

        return $response;
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $cookie = (string) ($request->getCookieParams()[AdminRememberMe::COOKIE_NAME] ?? '');
        if ($cookie !== '') {
            $this->rememberMe->revoke($cookie);
        }
        $this->auth->logout();
        Flash::set('success', 'Te-ai deconectat în siguranță.');
        return $this->rememberMe->withoutCookie($this->view->redirect($this->config->path('admin')));
    }

    public function categories(): ResponseInterface
    {
        return $this->view->render('admin/pages/categories.twig', ['categories' => $this->catalog->allCategories(true)]);
    }

    public function categoryForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        return $this->view->render('admin/pages/category-form.twig', ['category' => $id ? $this->catalog->categoryById($id) : null, 'categories' => $this->catalog->allCategories(true)]);
    }

    public function saveCategory(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $id = $this->catalogAdmin->saveCategory((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            Flash::set('success', 'Categoria a fost salvată.');
            return $this->view->redirect($this->config->path('admin/categorii/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/categorii/'));
        }
    }

    public function products(): ResponseInterface
    {
        return $this->view->render('admin/pages/products.twig', ['products' => $this->catalogAdmin->products()]);
    }

    public function productForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        return $this->view->render('admin/pages/product-form.twig', ['product' => $id ? $this->catalogAdmin->productForm($id) : null, 'categories' => $this->catalog->allCategories(true)]);
    }

    public function saveProduct(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $id = $this->catalogAdmin->saveProduct((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            Flash::set('success', 'Produsul și stocul au fost salvate.');
            return $this->view->redirect($this->config->path('admin/produse/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/produse/'));
        }
    }

    public function orders(): ResponseInterface
    {
        return $this->view->render('admin/pages/orders.twig', ['orders' => $this->orders->list()]);
    }

    public function transitionOrder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $user = $this->auth->user();
        try {
            $this->orders->transition((int) $args['id'], (string) ($data['action'] ?? ''), isset($data['awb']) ? (string) $data['awb'] : null, (int) $user['id']);
            Flash::set('success', 'Starea comenzii și stocul au fost actualizate.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/comenzi/'));
    }

    public function reviews(): ResponseInterface
    {
        return $this->view->render('admin/pages/reviews.twig', ['reviews' => $this->reviews->forModeration()]);
    }

    public function moderateReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $user = $this->auth->user();
        try {
            $this->reviews->moderate((int) $args['id'], (string) ($data['action'] ?? ''), (int) ($user['id'] ?? 0));
            Flash::set('success', 'Starea recenziei a fost actualizată.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/recenzii/'));
    }

    public function content(): ResponseInterface
    {
        return $this->view->render('admin/pages/content.twig', ['pages' => $this->pdo->query('SELECT * FROM content_pages ORDER BY title')->fetchAll(), 'sections' => $this->pdo->query('SELECT * FROM homepage_sections ORDER BY position')->fetchAll()]);
    }

    public function pageForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        return $this->view->render('admin/pages/content-form.twig', ['page' => $id ? $this->contentAdmin->page($id) : null]);
    }

    public function savePage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $id = $this->contentAdmin->savePage((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            Flash::set('success', 'Pagina a fost salvată și conținutul HTML a fost sanitizat.');
            return $this->view->redirect($this->config->path('admin/pagini/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/continut/'));
        }
    }

    public function sectionAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        try {
            $this->contentAdmin->sectionAction((int) $args['id'], (string) ($data['action'] ?? ''));
            Flash::set('success', 'Secțiunea a fost actualizată.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/continut/'));
    }

    public function media(): ResponseInterface
    {
        return $this->view->render('admin/pages/media.twig', ['media' => $this->media->all()]);
    }

    public function uploadMedia(ServerRequestInterface $request): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        try {
            if (!isset($files['image'])) {
                throw new \RuntimeException('Alege o imagine.');
            }
            $data = (array) ($request->getParsedBody() ?? []);
            $this->media->upload($files['image'], (string) ($data['alt_text'] ?? ''));
            Flash::set('success', 'Imaginea a fost validată și re-encodată.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/media/'));
    }

    public function coupons(): ResponseInterface
    {
        return $this->view->render('admin/pages/coupons.twig', ['coupons' => $this->pdo->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll()]);
    }

    public function saveCoupon(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        try {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));
            $type = ($data['type'] ?? '') === 'percent' ? 'percent' : 'fixed';
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
                throw new \RuntimeException('Codul trebuie să aibă 3–40 caractere: litere, cifre, _ sau -.');
            }
            if ($type === 'percent') {
                $percent = str_replace(',', '.', trim((string) ($data['value'] ?? '')));
                if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $percent)) {
                    throw new \RuntimeException('Procentul trebuie să fie între 0,01 și 100.');
                }
                [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');
                $value = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            } else {
                $value = Money::parse((string) ($data['value'] ?? ''));
            }
            if ($value <= 0 || ($type === 'percent' && $value > 10000)) {
                throw new \RuntimeException('Valoarea cuponului nu este validă.');
            }
            $toUtc = static function (mixed $value): ?string {
                $value = trim((string) $value);
                if ($value === '') {
                    return null;
                }
                return (new \DateTimeImmutable($value, new \DateTimeZone('Europe/Bucharest')))->setTimezone(new \DateTimeZone('UTC'))->format('c');
            };
            $this->pdo->prepare('INSERT INTO coupons (code,type,value,minimum_bani,usage_limit,per_customer_limit,starts_at,ends_at,status,created_at) VALUES (:code,:type,:value,:minimum,:usage_limit,:customer_limit,:starts,:ends,:status,:now)')->execute([
                'code' => $code, 'type' => $type, 'value' => $value,
                'minimum' => trim((string) ($data['minimum'] ?? '')) === '' ? 0 : Money::parse((string) $data['minimum']),
                'usage_limit' => trim((string) ($data['usage_limit'] ?? '')) === '' ? null : max(1, (int) $data['usage_limit']),
                'customer_limit' => trim((string) ($data['per_customer_limit'] ?? '')) === '' ? null : max(1, (int) $data['per_customer_limit']),
                'starts' => $toUtc($data['starts_at'] ?? ''), 'ends' => $toUtc($data['ends_at'] ?? ''),
                'status' => ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active', 'now' => gmdate('c'),
            ]);
            Flash::set('success', 'Cuponul a fost creat. Cupoanele nu se cumulează.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/cupoane/'));
    }
}
