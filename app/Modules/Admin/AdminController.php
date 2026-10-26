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
    public function __construct(private readonly PDO $pdo, private readonly AdminDashboardService $dashboardService, private readonly AdminNotificationService $notificationService, private readonly CatalogRepository $catalog, private readonly CatalogAdminService $catalogAdmin, private readonly OrderService $orders, private readonly ProductReviewService $reviews, private readonly MediaService $media, private readonly ContentAdminService $contentAdmin, private readonly Auth $auth, private readonly AdminRememberMe $rememberMe, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
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
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($admin['id'] ?? 0)),
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

    public function orders(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $statusLabels = [
            'new' => 'Nouă', 'confirmed' => 'Confirmată', 'preparing' => 'În pregătire',
            'shipped' => 'Expediată', 'delivered' => 'Livrată', 'cancelled' => 'Anulată',
            'returned' => 'Returnată', 'manual_review' => 'De verificat',
        ];
        $filterStatusLabels = $statusLabels;
        unset($filterStatusLabels['manual_review']);
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $status = (string) ($query['status'] ?? '');
        if (!array_key_exists($status, $filterStatusLabels)) {
            $status = '';
        }
        $pagination = $this->orders->paginateForAdmin(
            max(1, (int) ($query['pagina'] ?? 1)),
            15,
            $search,
            $status !== '' ? $status : null,
        );
        $paymentLabels = [
            'pending' => 'În așteptare', 'paid' => 'Plătită', 'failed' => 'Eșuată',
            'partially_refunded' => 'Rambursată parțial', 'refunded' => 'Rambursată',
        ];
        $zone = new \DateTimeZone('Europe/Bucharest');
        foreach ($pagination['items'] as &$order) {
            $createdAt = new \DateTimeImmutable((string) $order['created_at'], new \DateTimeZone('UTC'));
            $order['display_created_at'] = $createdAt->setTimezone($zone)->format('d.m.Y, H:i');
            $order['status_label'] = $statusLabels[(string) $order['status']] ?? ucfirst((string) $order['status']);
            $order['payment_status_label'] = $paymentLabels[(string) $order['payment_status']] ?? ucfirst((string) $order['payment_status']);
            $order['payment_method_label'] = (string) $order['payment_method'] === 'stripe' ? 'Card online' : 'Ramburs';
        }
        unset($order);

        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);
        $filterQuery = array_filter(['cautare' => $search, 'status' => $status], static fn (string $value): bool => $value !== '');

        return $this->view->render('admin/pages/orders.twig', [
            'orders' => $pagination['items'],
            'pagination' => $pagination,
            'search' => $search,
            'selected_status' => $status,
            'status_options' => $filterStatusLabels,
            'filters_query' => http_build_query($filterQuery),
            'has_filters' => $filterQuery !== [],
            'admin_section' => 'orders',
            'admin_context' => 'Comenzi',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function orderDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $order = $this->orders->findForAdmin((int) $args['id']);
        if ($order === null) {
            Flash::set('error', 'Comanda solicitată nu a fost găsită.');
            return $this->view->redirect($this->config->path('admin/comenzi/'));
        }

        $statusLabels = [
            'new' => 'Nouă', 'confirmed' => 'Confirmată', 'preparing' => 'În pregătire',
            'shipped' => 'Expediată', 'delivered' => 'Livrată', 'cancelled' => 'Anulată',
            'returned' => 'Returnată', 'manual_review' => 'De verificat',
        ];
        $paymentLabels = [
            'pending' => 'În așteptare', 'paid' => 'Plătită', 'failed' => 'Eșuată',
            'partially_refunded' => 'Rambursată parțial', 'refunded' => 'Rambursată',
        ];
        $createdAt = new \DateTimeImmutable((string) $order['created_at'], new \DateTimeZone('UTC'));
        $order['display_created_at'] = $createdAt->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y, H:i');
        $order['status_label'] = $statusLabels[(string) $order['status']] ?? ucfirst((string) $order['status']);
        $order['payment_status_label'] = $paymentLabels[(string) $order['payment_status']] ?? ucfirst((string) $order['payment_status']);

        return $this->view->render('admin/pages/order-detail.twig', [
            'order' => $order,
            'admin_section' => 'orders',
            'admin_context' => 'Comenzi',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function notifications(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();
        $adminUserId = (int) ($user['id'] ?? 0);
        $query = $request->getQueryParams();
        $filters = [
            'toate' => 'Toate',
            'comenzi' => 'Comenzi',
            'plati' => 'Plăți',
            'stoc' => 'Stoc',
            'recenzii' => 'Recenzii',
        ];
        $filter = array_key_exists((string) ($query['filtru'] ?? ''), $filters) ? (string) $query['filtru'] : 'toate';
        $unreadOnly = (string) ($query['necitite'] ?? '') === '1';
        $feed = $this->notificationService->feed($adminUserId, max(1, (int) ($query['pagina'] ?? 1)), 15, $filter, $unreadOnly);
        $bucharest = new \DateTimeZone('Europe/Bucharest');
        $today = new \DateTimeImmutable('today', $bucharest);
        foreach ($feed['items'] as &$notification) {
            $occurredAt = new \DateTimeImmutable((string) $notification['occurred_at'], new \DateTimeZone('UTC'));
            $local = $occurredAt->setTimezone($bucharest);
            $day = $local->setTime(0, 0);
            $notification['display_time'] = $local->format('H:i');
            $notification['display_date'] = $day == $today
                ? 'Astăzi'
                : ($day == $today->modify('-1 day') ? 'Ieri' : $local->format('d.m.Y'));
        }
        unset($notification);

        $firstPage = max(1, $feed['page'] - 2);
        $lastPage = min($feed['total_pages'], $feed['page'] + 2);
        $feed['page_numbers'] = range($firstPage, $lastPage);
        $filterQuery = http_build_query(array_filter([
            'filtru' => $filter !== 'toate' ? $filter : '',
            'necitite' => $unreadOnly ? '1' : '',
        ], static fn (string $value): bool => $value !== ''));

        return $this->view->render('admin/pages/notifications.twig', [
            'notifications' => $feed['items'],
            'notification_feed' => $feed,
            'notification_filters' => $filters,
            'selected_notification_filter' => $filter,
            'unread_only' => $unreadOnly,
            'notification_filter_query' => $filterQuery,
            'admin_section' => 'notifications',
            'admin_context' => 'Notificări',
            'admin_unread_count' => $feed['unread_total'],
            'admin_user' => $user,
        ]);
    }

    public function readNotification(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $adminUserId = (int) ($this->auth->user()['id'] ?? 0);
        if ((string) ($data['all'] ?? '') === '1') {
            $marked = $this->notificationService->markAllRead($adminUserId);
            Flash::set('success', $marked > 0 ? 'Toate notificările au fost marcate drept citite.' : 'Nu există notificări noi de marcat.');
        } else {
            $this->notificationService->markRead($adminUserId, (string) ($data['notification_key'] ?? ''));
        }

        return $this->view->redirect($this->notificationRedirect($data));
    }

    public function notificationPreferences(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $this->notificationService->savePreferences((int) ($this->auth->user()['id'] ?? 0), $data);
        Flash::set('success', 'Preferințele pentru notificări au fost salvate.');

        return $this->view->redirect($this->config->path('admin/notificari/'));
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

    /** @param array<string,mixed> $data */
    private function notificationRedirect(array $data): string
    {
        $filter = in_array((string) ($data['filtru'] ?? ''), ['comenzi', 'plati', 'stoc', 'recenzii'], true)
            ? (string) $data['filtru']
            : '';
        $query = http_build_query(array_filter([
            'filtru' => $filter,
            'necitite' => (string) ($data['necitite'] ?? '') === '1' ? '1' : '',
        ], static fn (string $value): bool => $value !== ''));

        return $this->config->path('admin/notificari/' . ($query !== '' ? '?' . $query : ''));
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
