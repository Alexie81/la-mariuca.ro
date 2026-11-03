<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Media\MediaService;
use App\Modules\Orders\OrderService;
use App\Modules\Reviews\ProductReviewService;
use App\Modules\Suppliers\SupplierAdminService;
use App\Modules\Content\ContentAdminService;
use App\Modules\Customers\CustomerAdminService;
use App\Modules\Newsletter\NewsletterAdminService;
use App\Modules\Promotions\PromotionAdminService;
use App\Modules\Shipping\ShippingMethodService;
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
    public function __construct(private readonly PDO $pdo, private readonly AdminDashboardService $dashboardService, private readonly AdminNotificationService $notificationService, private readonly AdminPushService $push, private readonly CatalogRepository $catalog, private readonly CatalogAdminService $catalogAdmin, private readonly CustomerAdminService $customers, private readonly NewsletterAdminService $newsletter, private readonly PromotionAdminService $promotions, private readonly ShippingMethodService $shippingMethods, private readonly SupplierAdminService $suppliers, private readonly OrderService $orders, private readonly ProductReviewService $reviews, private readonly MediaService $media, private readonly ContentAdminService $contentAdmin, private readonly Auth $auth, private readonly AdminRememberMe $rememberMe, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
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

    public function categories(ServerRequestInterface $request): ResponseInterface
    {
        $search = trim(mb_substr((string) ($request->getQueryParams()['cautare'] ?? ''), 0, 120));
        $allCategories = $this->catalogAdmin->categoriesForAdmin();
        $categories = $search === '' ? $allCategories : $this->catalogAdmin->categoriesForAdmin($search);
        return $this->view->render('admin/pages/categories.twig', [
            'categories' => $categories,
            'category_total' => count($allCategories),
            'root_total' => count(array_filter($allCategories, static fn(array $category): bool => $category['parent_id'] === null)),
            'search' => $search,
            'has_filters' => $search !== '',
            'admin_section' => 'categories',
            'admin_context' => 'Categorii',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function categoryForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $category = $id === null ? null : $this->catalog->categoryById($id);
        if ($id !== null && $category === null) {
            Flash::set('error', 'Categoria solicitată nu mai există.');
            return $this->view->redirect($this->config->path('admin/categorii/'));
        }
        return $this->view->render('admin/pages/category-form.twig', [
            'category' => $category,
            'parent_categories' => $this->catalogAdmin->categoryParentTree($id),
            'suggested_position' => $category ? (int) $category['position'] : $this->catalogAdmin->nextRootCategoryPosition(),
            'form_action' => $this->config->path($id === null ? 'admin/categorii/noua/' : 'admin/categorii/' . $id . '/editare/'),
            'admin_section' => 'categories',
            'admin_context' => $id === null ? 'Categorie nouă' : 'Editare categorie',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function categoryModalForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $category = $id === null ? null : $this->catalog->categoryById($id);
        if ($id !== null && $category === null) {
            return $this->view->render('admin/partials/product-form-error.twig', [], 404);
        }
        return $this->view->render('admin/partials/category-form-modal.twig', [
            'category' => $category,
            'parent_categories' => $this->catalogAdmin->categoryParentTree($id),
            'suggested_position' => $category ? (int) $category['position'] : $this->catalogAdmin->nextRootCategoryPosition(),
            'form_action' => $this->config->path($id === null ? 'admin/categorii/noua/' : 'admin/categorii/' . $id . '/editare/'),
        ]);
    }

    public function categoryNameAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = trim(mb_substr((string) ($query['nume'] ?? ''), 0, 190));
        $excludeId = max(0, (int) ($query['exclude_id'] ?? 0));
        $available = $name !== '' && $this->catalogAdmin->isCategoryNameAvailable($name, $excludeId > 0 ? $excludeId : null);
        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'available' => $available,
            'message' => $name === '' ? 'Scrie denumirea pe care o va vedea clientul.' : ($available ? 'Denumirea este disponibilă.' : 'Există deja o categorie cu acest nume.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public function categorySlugAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $slug = $this->catalogAdmin->normalizeCategorySlug((string) ($query['slug'] ?? ''));
        $excludeId = max(0, (int) ($query['exclude_id'] ?? 0));
        $available = $slug !== '' && $this->catalogAdmin->isCategorySlugAvailable($slug, $excludeId > 0 ? $excludeId : null);
        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'available' => $available,
            'slug' => $slug,
            'message' => $slug === '' ? 'Slugul se generează automat din nume.' : ($available ? 'Slugul este disponibil.' : 'Acest slug este deja folosit.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    public function saveCategory(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $data = (array) ($request->getParsedBody() ?? []);
            $image = $request->getUploadedFiles()['image'] ?? null;
            if ($image !== null && $image->getError() !== UPLOAD_ERR_NO_FILE) {
                $altText = trim((string) ($data['image_alt'] ?? ''));
                $data['image_media_id'] = $this->media->upload($image, $altText !== '' ? $altText : (string) ($data['name'] ?? 'Imagine categorie'));
                $data['remove_image'] = '0';
            }
            $id = $this->catalogAdmin->saveCategory($data, isset($args['id']) ? (int) $args['id'] : null);
            $savedImageId = (int) ($data['image_media_id'] ?? 0);
            if ($savedImageId > 0 && !in_array((string) ($data['remove_image'] ?? ''), ['1', 'on'], true)) {
                $this->media->updateAltText($savedImageId, trim((string) ($data['image_alt'] ?? '')) ?: (string) ($data['name'] ?? 'Imagine categorie'));
            }
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $id, 'message' => 'Categoria a fost salvată și arborele a fost actualizat.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', 'Categoria a fost salvată și arborele a fost actualizat.');
            return $this->view->redirect($this->config->path('admin/categorii/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/categorii/'));
        }
    }

    public function deleteCategory(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $name = $this->catalogAdmin->deleteCategory((int) ($args['id'] ?? 0));
            Flash::set('success', 'Categoria „' . $name . '” a fost ștearsă.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/categorii/'));
    }

    public function products(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $pagination = $this->catalogAdmin->paginateProductsForAdmin(
            max(1, (int) ($query['pagina'] ?? 1)),
            15,
            $search,
        );
        $statusLabels = ['published' => 'Publicat', 'draft' => 'Draft', 'archived' => 'Arhivat'];
        foreach ($pagination['items'] as &$product) {
            $product['status_label'] = $statusLabels[(string) $product['status']] ?? ucfirst((string) $product['status']);
        }
        unset($product);

        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);

        return $this->view->render('admin/pages/products.twig', [
            'products' => $pagination['items'],
            'pagination' => $pagination,
            'search' => $search,
            'filters_query' => $search !== '' ? http_build_query(['cautare' => $search]) : '',
            'has_filters' => $search !== '',
            'admin_section' => 'products',
            'admin_context' => 'Produse',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function customers(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $pagination = $this->customers->paginate(max(1, (int) ($query['pagina'] ?? 1)), 15, $search);
        foreach ($pagination['items'] as &$customer) {
            $this->decorateCustomer($customer);
        }
        unset($customer);
        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);

        return $this->view->render('admin/pages/customers.twig', [
            'customers' => $pagination['items'],
            'pagination' => $pagination,
            'customer_summary' => $this->customers->summary(),
            'search' => $search,
            'filters_query' => $search !== '' ? http_build_query(['cautare' => $search]) : '',
            'has_filters' => $search !== '',
            'admin_section' => 'customers',
            'admin_context' => 'Clienți',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function customerDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->customerDetailData((int) ($args['id'] ?? 0), max(1, (int) ($request->getQueryParams()['pagina_comenzi'] ?? 1)));
        if ($data === null) {
            Flash::set('error', 'Clientul solicitat nu a fost găsit.');
            return $this->view->redirect($this->config->path('admin/clienti/'));
        }

        return $this->view->render('admin/pages/customer-detail.twig', [
            ...$data,
            'admin_section' => 'customers',
            'admin_context' => 'Profil client',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function newsletterSubscribers(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $pagination = $this->newsletter->paginate(max(1, (int) ($query['pagina'] ?? 1)), 15, $search);
        foreach ($pagination['items'] as &$subscriber) {
            $this->decorateNewsletterSubscriber($subscriber);
        }
        unset($subscriber);
        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);

        return $this->view->render('admin/pages/newsletter-subscribers.twig', [
            'subscribers' => $pagination['items'],
            'pagination' => $pagination,
            'newsletter_summary' => $this->newsletter->summary(),
            'search' => $search,
            'filters_query' => $search !== '' ? http_build_query(['cautare' => $search]) : '',
            'has_filters' => $search !== '',
            'admin_section' => 'newsletter',
            'admin_context' => 'Abonați newsletter',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function newsletterSubscriberDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->newsletterSubscriberData((int) ($args['id'] ?? 0));
        if ($data === null) {
            Flash::set('error', 'Abonatul solicitat nu a fost găsit.');
            return $this->view->redirect($this->config->path('admin/abonati-newsletter/'));
        }
        return $this->view->render('admin/pages/newsletter-subscriber-detail.twig', [
            ...$data,
            'admin_section' => 'newsletter',
            'admin_context' => 'Profil abonat',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function newsletterSubscriberModal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->newsletterSubscriberData((int) ($args['id'] ?? 0));
        if ($data === null) {
            return $this->view->render('admin/partials/newsletter-subscriber-error.twig', [], 404);
        }
        return $this->view->render('admin/partials/newsletter-subscriber-modal.twig', $data);
    }

    public function promotions(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $pagination = $this->promotions->paginate(max(1, (int) ($query['pagina'] ?? 1)), 15, $search);
        foreach ($pagination['items'] as &$offer) {
            $this->decoratePromotionOffer($offer);
        }
        unset($offer);
        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);

        return $this->view->render('admin/pages/promotions.twig', [
            'offers' => $pagination['items'],
            'pagination' => $pagination,
            'summary' => $this->promotions->summary(),
            'search' => $search,
            'filters_query' => $search !== '' ? http_build_query(['cautare' => $search]) : '',
            'has_filters' => $search !== '',
            'admin_section' => 'promotions',
            'admin_context' => 'Cupoane și promoții',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function couponForm(): ResponseInterface
    {
        return $this->promotionFormResponse('coupon', false);
    }

    public function couponModal(): ResponseInterface
    {
        return $this->promotionFormResponse('coupon', true);
    }

    public function promotionForm(): ResponseInterface
    {
        return $this->promotionFormResponse('promotion', false);
    }

    public function promotionModal(): ResponseInterface
    {
        return $this->promotionFormResponse('promotion', true);
    }

    public function savePromotionCoupon(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->savePromotionOffer($request, $response, 'coupon');
    }

    public function savePromotion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->savePromotionOffer($request, $response, 'promotion');
    }

    public function deactivatePromotionOffer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->changePromotionOffer($request, $response, $args, false);
    }

    public function deletePromotionOffer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->changePromotionOffer($request, $response, $args, true);
    }

    public function editPromotionOffer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->promotionFormResponse($args['offer_type'] === 'cupon' ? 'coupon' : 'promotion', false, (int) $args['id']);
    }

    public function editPromotionOfferModal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->promotionFormResponse($args['offer_type'] === 'cupon' ? 'coupon' : 'promotion', true, (int) $args['id']);
    }

    public function updatePromotionOffer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->savePromotionOffer($request, $response, $args['offer_type'] === 'cupon' ? 'coupon' : 'promotion', (int) $args['id']);
    }

    public function confirmPromotionAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $offer = $this->promotions->findOffer($args['offer_type'] === 'cupon' ? 'coupon' : 'promotion', (int) $args['id']);
        return $this->view->render('admin/pages/promotion-confirm.twig', [
            'offer' => $offer,
            'is_delete' => $args['action'] === 'sterge',
            'form_action' => $this->config->path('admin/cupoane-promotii/' . $args['offer_type'] . '/' . $args['id'] . '/' . $args['action'] . '/'),
            'admin_section' => 'promotions',
            'admin_context' => 'Confirmă acțiunea',
        ], $offer === null ? 404 : 200);
    }

    private function changePromotionOffer(ServerRequestInterface $request, ResponseInterface $response, array $args, bool $delete): ResponseInterface
    {
        $offerType = ($args['offer_type'] ?? '') === 'cupon' ? 'coupon' : (($args['offer_type'] ?? '') === 'reducere' ? 'promotion' : '');
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $message = $delete
                ? $this->promotions->deleteOffer($offerType, (int) ($args['id'] ?? 0))
                : $this->promotions->deactivateOffer($offerType, (int) ($args['id'] ?? 0));
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('admin/cupoane-promotii/'));
    }

    public function shippingMethods(): ResponseInterface
    {
        $summary = $this->shippingMethods->summary();
        return $this->view->render('admin/pages/shipping-methods.twig', [
            'shipping_methods' => $this->shippingMethods->allForAdmin(),
            'summary' => $summary,
            'admin_section' => 'shipping',
            'admin_context' => 'Livrare',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function shippingMethodForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $method = $id === null ? null : $this->shippingMethods->find($id);
        if ($id !== null && $method === null) {
            Flash::set('error', 'Metoda de livrare solicitată nu mai există.');
            return $this->view->redirect($this->config->path('admin/livrare/'));
        }

        return $this->view->render('admin/pages/shipping-method-form.twig', [
            'shipping_method' => $method,
            'shipping_types' => $this->shippingTypeOptions(),
            'form_action' => $this->config->path($id === null ? 'admin/livrare/noua/' : 'admin/livrare/' . $id . '/editare/'),
            'admin_section' => 'shipping',
            'admin_context' => $id === null ? 'Metodă de livrare nouă' : 'Editare metodă de livrare',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function shippingMethodModal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $method = $id === null ? null : $this->shippingMethods->find($id);
        if ($id !== null && $method === null) {
            return $this->view->render('admin/partials/shipping-method-form.twig', [
                'shipping_method_not_found' => true,
                'shipping_method' => null,
                'shipping_types' => $this->shippingTypeOptions(),
                'form_action' => '',
                'is_modal' => true,
            ], 404);
        }

        return $this->view->render('admin/partials/shipping-method-form.twig', [
            'shipping_method' => $method,
            'shipping_types' => $this->shippingTypeOptions(),
            'form_action' => $this->config->path($id === null ? 'admin/livrare/noua/' : 'admin/livrare/' . $id . '/editare/'),
            'is_modal' => true,
        ]);
    }

    public function saveShippingMethod(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $id = $this->shippingMethods->save((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            $message = 'Metoda de livrare a fost salvată.';
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => true,
                    'id' => $id,
                    'message' => $message,
                    'redirect' => $this->config->path('admin/livrare/'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/livrare/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/livrare/'));
        }
    }

    public function shippingMethodStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $message = $this->shippingMethods->changeStatus((int) ($args['id'] ?? 0), (string) (((array) ($request->getParsedBody() ?? []))['status'] ?? ''));
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/livrare/'));
    }

    public function customerModal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->customerDetailData((int) ($args['id'] ?? 0), max(1, (int) ($request->getQueryParams()['pagina_comenzi'] ?? 1)));
        if ($data === null) {
            return $this->view->render('admin/partials/customer-detail-error.twig', [], 404);
        }
        return $this->view->render('admin/partials/customer-detail-modal.twig', [...$data, 'customer_modal' => true]);
    }

    public function customerState(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $customerId = (int) ($args['id'] ?? 0);
        $data = (array) ($request->getParsedBody() ?? []);
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $message = $this->customers->updateAccountStatus(
                $customerId,
                (string) ($data['action'] ?? ''),
                (string) ($data['reason'] ?? ''),
                (int) ($this->auth->user()['id'] ?? 0),
            );
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('admin/clienti/' . $customerId . '/'));
    }

    public function customerDiscountForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->customerDiscountFormData((int) ($args['id'] ?? 0));
        if ($data === null) {
            Flash::set('error', 'Clientul solicitat nu a fost găsit.');
            return $this->view->redirect($this->config->path('admin/clienti/'));
        }
        return $this->view->render('admin/pages/customer-discount-form.twig', [
            ...$data,
            'admin_section' => 'customers',
            'admin_context' => 'Reducere client',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function customerDiscountModal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = $this->customerDiscountFormData((int) ($args['id'] ?? 0));
        if ($data === null) {
            return $this->view->render('admin/partials/customer-detail-error.twig', [], 404);
        }
        return $this->view->render('admin/partials/customer-discount-form.twig', $data);
    }

    public function saveCustomerDiscount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $customerId = (int) ($args['id'] ?? 0);
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $message = $this->customers->createDiscount($customerId, (array) ($request->getParsedBody() ?? []), (int) ($this->auth->user()['id'] ?? 0));
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/clienti/' . $customerId . '/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($this->config->path('admin/clienti/' . $customerId . '/reduceri/noua/'));
        }
    }

    public function deleteCustomerDiscount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $customerId = (int) ($args['id'] ?? 0);
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $message = $this->customers->deleteDiscount($customerId, (int) ($args['discount_id'] ?? 0));
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/clienti/' . $customerId . '/'));
    }

    public function suppliers(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim(mb_substr((string) ($query['cautare'] ?? ''), 0, 120));
        $pagination = $this->suppliers->paginate(max(1, (int) ($query['pagina'] ?? 1)), 12, $search);
        $firstPage = max(1, $pagination['page'] - 2);
        $lastPage = min($pagination['total_pages'], $pagination['page'] + 2);
        $pagination['page_numbers'] = range($firstPage, $lastPage);

        return $this->view->render('admin/pages/suppliers.twig', [
            'suppliers' => $pagination['items'],
            'pagination' => $pagination,
            'search' => $search,
            'filters_query' => $search !== '' ? http_build_query(['cautare' => $search]) : '',
            'has_filters' => $search !== '',
            'admin_section' => 'suppliers',
            'admin_context' => 'Furnizori',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function supplierForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $supplier = $id === null ? null : $this->suppliers->find($id);
        if ($id !== null && $supplier === null) {
            Flash::set('error', 'Furnizorul solicitat nu mai există.');
            return $this->view->redirect($this->config->path('admin/furnizori/'));
        }

        return $this->view->render('admin/pages/supplier-form.twig', [
            'supplier' => $supplier,
            'form_action' => $this->config->path($id === null ? 'admin/furnizori/nou/' : 'admin/furnizori/' . $id . '/editare/'),
            'admin_section' => 'suppliers',
            'admin_context' => $id === null ? 'Furnizor nou' : 'Editare furnizor',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function supplierModalForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $supplier = $id === null ? null : $this->suppliers->find($id);
        if ($id !== null && $supplier === null) {
            return $this->view->render('admin/partials/product-form-error.twig', [], 404);
        }
        return $this->view->render('admin/partials/supplier-form-modal.twig', [
            'supplier' => $supplier,
            'form_action' => $this->config->path($id === null ? 'admin/furnizori/nou/' : 'admin/furnizori/' . $id . '/editare/'),
        ]);
    }

    public function saveSupplier(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $id = $this->suppliers->save((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => true,
                    'id' => $id,
                    'message' => 'Furnizorul a fost salvat.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', 'Furnizorul a fost salvat și poate fi selectat pe produse.');
            return $this->view->redirect($this->config->path('admin/furnizori/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/furnizori/'));
        }
    }

    public function deleteSupplier(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->suppliers->delete((int) ($args['id'] ?? 0));
            Flash::set('success', 'Furnizorul a fost șters. Produsele asociate au fost păstrate.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        return $this->view->redirect($this->config->path('admin/furnizori/'));
    }

    public function productForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $product = $id ? $this->catalogAdmin->productForm($id) : null;
        if ($id !== null && $product === null) {
            Flash::set('error', 'Produsul solicitat nu mai este disponibil.');
            return $this->view->redirect($this->config->path('admin/produse/'));
        }
        return $this->view->render('admin/pages/product-form.twig', [
            'product' => $product,
            'suggested_sku' => $product ? null : $this->catalogAdmin->generateProductSku(),
            'categories' => $this->catalog->allCategoryTree(true),
            'suppliers' => $this->suppliers->allForSelect(),
            'form_action' => $this->config->path($id ? 'admin/produse/' . $id . '/editare/' : 'admin/produse/nou/'),
            'admin_section' => 'products',
            'admin_context' => $id ? 'Editare produs' : 'Produs nou',
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ]);
    }

    public function productModalForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $product = $id ? $this->catalogAdmin->productForm($id) : null;
        if ($id !== null && $product === null) {
            return $this->view->render('admin/partials/product-form-error.twig', [], 404);
        }

        return $this->view->render('admin/partials/product-form-modal.twig', [
            'product' => $product,
            'suggested_sku' => $product ? null : $this->catalogAdmin->generateProductSku(),
            'categories' => $this->catalog->allCategoryTree(true),
            'suppliers' => $this->suppliers->allForSelect(),
            'form_action' => $this->config->path($id ? 'admin/produse/' . $id . '/editare/' : 'admin/produse/nou/'),
        ]);
    }

    public function productNameAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $name = trim(mb_substr((string) ($query['nume'] ?? ''), 0, 255));
        $excludeId = max(0, (int) ($query['exclude_id'] ?? 0));
        $available = $name !== '' && $this->catalogAdmin->isProductNameAvailable($name, $excludeId > 0 ? $excludeId : null);
        $message = $name === ''
            ? 'Denumirea pe care o vede clientul.'
            : ($available ? 'Acest titlu de produs este disponibil!' : 'Acest titlu există deja.');

        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'available' => $available,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function productSkuAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $sku = trim(mb_substr((string) ($query['sku'] ?? ''), 0, 100));
        $excludeVariantId = max(0, (int) ($query['exclude_variant_id'] ?? 0));
        $excludeProductId = max(0, (int) ($query['exclude_product_id'] ?? 0));
        $primary = ($query['scope'] ?? '') === 'primary';
        $available = $sku !== '' && ($primary
            ? $this->catalogAdmin->isProductPrimarySkuAvailable($sku, $excludeProductId > 0 ? $excludeProductId : null)
            : $this->catalogAdmin->isProductSkuAvailable($sku, $excludeVariantId > 0 ? $excludeVariantId : null));
        $message = $sku === ''
            ? 'SKU-ul va fi generat automat.'
            : ($available ? 'Acest SKU este disponibil!' : 'Acest SKU este deja folosit.');

        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'available' => $available,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function productSlugAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $slugInput = trim(mb_substr((string) ($query['slug'] ?? ''), 0, 255));
        $slug = $this->catalogAdmin->normalizeProductSlug($slugInput);
        $excludeId = max(0, (int) ($query['exclude_id'] ?? 0));
        $available = $slug !== '' && $this->catalogAdmin->isProductSlugAvailable($slug, $excludeId > 0 ? $excludeId : null);
        $message = $slug === ''
            ? 'Numele linkului se generează automat din titlu.'
            : ($available ? 'Acest nume de link este disponibil!' : 'Acest nume de link există deja.');

        $response->getBody()->write((string) json_encode([
            'ok' => true,
            'available' => $available,
            'slug' => $slug,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function saveProduct(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        try {
            $id = $this->catalogAdmin->saveProduct((array) ($request->getParsedBody() ?? []), isset($args['id']) ? (int) $args['id'] : null);
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => true,
                    'id' => $id,
                    'message' => 'Produsul și stocul au fost salvate.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', 'Produsul și stocul au fost salvate.');
            return $this->view->redirect($this->config->path('admin/produse/' . $id . '/editare/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($request->getHeaderLine('Referer') ?: $this->config->path('admin/produse/'));
        }
    }

    public function uploadProductDescriptionImage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $files = $request->getUploadedFiles();
            if (!isset($files['image'])) {
                throw new \RuntimeException('Alege o imagine pentru încărcare.');
            }
            $data = (array) ($request->getParsedBody() ?? []);
            $id = $this->media->upload($files['image'], (string) ($data['alt_text'] ?? ''));
            $media = $this->media->find($id);
            if ($media === null) {
                throw new \RuntimeException('Imaginea a fost încărcată, dar nu a putut fi regăsită.');
            }
            $payload = [
                'ok' => true,
                'id' => $id,
                'url' => $this->config->path((string) $media['public_path']),
                'alt' => (string) ($media['alt_text'] ?? ''),
                'width' => (int) ($media['width'] ?? 0),
                'height' => (int) ($media['height'] ?? 0),
            ];
            $status = 200;
        } catch (\Throwable $exception) {
            $payload = ['ok' => false, 'message' => $exception->getMessage()];
            $status = 422;
        }

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function deleteProduct(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->catalogAdmin->deleteProduct((int) ($args['id'] ?? 0));
            Flash::set('success', 'Produsul a fost șters din catalog. Istoricul comenzilor a fost păstrat.');
        } catch (\Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('admin/produse/'));
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
            'push_configured' => $this->push->isConfigured(),
            'push_public_key' => $this->push->publicKey(),
            'push_devices' => $this->push->devices($adminUserId),
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

    public function deleteNotification(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $adminUserId = (int) ($this->auth->user()['id'] ?? 0);
        $filter = in_array((string) ($data['filtru'] ?? ''), ['comenzi', 'plati', 'stoc', 'recenzii'], true)
            ? (string) $data['filtru']
            : 'toate';
        $bulk = (string) ($data['all'] ?? '') === '1';
        $removed = $bulk
            ? $this->notificationService->dismissAll($adminUserId, $filter)
            : ($this->notificationService->dismiss($adminUserId, (string) ($data['notification_key'] ?? '')) ? 1 : 0);
        $redirect = $this->notificationRedirect($data);

        if (str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')) {
            $feed = $this->notificationService->feed(
                $adminUserId,
                1,
                15,
                $filter,
                (string) ($data['necitite'] ?? '') === '1'
            );
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'ok' => true,
                'removed' => $removed,
                'bulk' => $bulk,
                'total' => $feed['total'],
                'unread_total' => $feed['unread_total'],
                'counts' => $feed['counts'],
                'redirect' => $redirect,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        }

        Flash::set(
            'success',
            $removed > 0
                ? ($bulk ? 'Notificările din filtrul ales au fost șterse.' : 'Notificarea a fost ștearsă din inbox.')
                : 'Notificarea nu mai există în inbox.'
        );

        return $this->view->redirect($redirect);
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
        return $this->view->redirect($this->config->path('admin/cupoane-promotii/'));
    }

    public function saveCoupon(ServerRequestInterface $request): ResponseInterface
    {
        Flash::set('success', 'Configuratorul nou reunește cupoanele și promoțiile în același registru.');
        return $this->view->redirect($this->config->path('admin/cupoane-promotii/'));
    }

    /** @param array<string,mixed>|null $formData @param array<string,string> $errors */
    private function promotionFormResponse(string $offerKind, bool $modal, ?int $offerId = null, ?array $formData = null, array $errors = [], int $status = 200): ResponseInterface
    {
        $route = $offerKind === 'coupon' ? 'cupon' : 'reducere';
        $offer = $offerId !== null ? $this->promotions->findOffer($offerKind, $offerId) : null;
        $notFound = $offerId !== null && $offer === null;
        if ($formData === null && $offer !== null) {
            $formData = $offer;
            $formData['value'] = number_format((int) $offer['value'] / 100, 2, ',', '');
            $formData['minimum'] = (int) $offer['minimum_bani'] > 0 ? number_format((int) $offer['minimum_bani'] / 100, 2, ',', '') : '';
            $formData['validity'] = $offer['starts_at'] || $offer['ends_at'] ? 'period' : 'always';
            foreach (['starts_at', 'ends_at'] as $field) {
                $formData[$field] = $offer[$field]
                    ? (new \DateTimeImmutable((string) $offer[$field]))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d\TH:i')
                    : '';
            }
        }
        $data = [
            'offer_kind' => $offerKind,
            'catalog' => $this->promotions->discountCatalog(),
            'form_action' => $this->config->path('admin/cupoane-promotii/' . $route . '/' . ($offerId !== null ? $offerId . '/editare/' : 'nou/')),
            'form_data' => $formData ?? [],
            'errors' => $errors,
            'is_modal' => $modal,
            'is_edit' => $offerId !== null,
            'offer_not_found' => $notFound,
        ];
        if ($modal) {
            return $this->view->render('admin/partials/promotion-form.twig', $data, $notFound ? 404 : $status);
        }

        return $this->view->render('admin/pages/promotion-form.twig', [
            ...$data,
            'admin_section' => 'promotions',
            'admin_context' => $offerId !== null ? 'Editează oferta' : ($offerKind === 'coupon' ? 'Cupon nou' : 'Reducere nouă'),
            'admin_unread_count' => $this->notificationService->unreadCount((int) ($this->auth->user()['id'] ?? 0)),
        ], $notFound ? 404 : $status);
    }

    private function savePromotionOffer(ServerRequestInterface $request, ResponseInterface $response, string $offerKind, ?int $offerId = null): ResponseInterface
    {
        $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        $data = (array) ($request->getParsedBody() ?? []);
        try {
            $message = $offerId !== null
                ? $this->promotions->updateOffer($offerKind, $offerId, $data)
                : ($offerKind === 'coupon'
                ? $this->promotions->createCoupon($data, (int) ($this->auth->user()['id'] ?? 0))
                : $this->promotions->createPromotion($data, (int) ($this->auth->user()['id'] ?? 0)));
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode([
                    'ok' => true,
                    'message' => $message,
                    'redirect' => $this->config->path('admin/cupoane-promotii/'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            Flash::set('success', $message);
            return $this->view->redirect($this->config->path('admin/cupoane-promotii/'));
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                $response->getBody()->write((string) json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            }
            return $this->promotionFormResponse($offerKind, false, $offerId, $data, ['_form' => $exception->getMessage()], 422);
        }
    }

    /** @param array<string,mixed> $offer */
    private function decoratePromotionOffer(array &$offer): void
    {
        $offerType = (string) ($offer['offer_type'] ?? 'promotion');
        $lifecycle = (string) ($offer['lifecycle_status'] ?? $offer['status'] ?? 'inactive');
        $offer['offer_kind'] = $offerType;
        $offer['route_type'] = $offerType === 'coupon' ? 'cupon' : 'reducere';
        $offer['show_in_topbar'] = (int) ($offer['topbar_enabled'] ?? 0) === 1;
        $offer['can_deactivate'] = $offer['status'] === 'active';
        $offer['status'] = $lifecycle;
        $offer['status_label'] = match ($lifecycle) {
            'active' => 'Activă',
            'scheduled' => 'Programată',
            'expired' => 'Expirată',
            default => 'Inactivă',
        };
        $offer['scope_label'] = match ((string) ($offer['scope'] ?? '')) {
            'product' => 'Un produs',
            'category' => 'O categorie',
            'shipping' => 'Livrare',
            default => 'Toată comanda',
        };
        $offer['value_label'] = match ((string) ($offer['value_type'] ?? '')) {
            'free' => 'Livrare gratuită',
            'percent' => rtrim(rtrim(number_format(((int) ($offer['value'] ?? 0)) / 100, 2, ',', ''), '0'), ',') . '% reducere',
            default => Money::format((int) ($offer['value'] ?? 0)) . ' reducere',
        };
        $offer['minimum_label'] = (int) ($offer['minimum_bani'] ?? 0) > 0
            ? 'Prag minim ' . Money::format((int) $offer['minimum_bani'])
            : 'Fără prag minim';

        $startsAt = trim((string) ($offer['starts_at'] ?? ''));
        $endsAt = trim((string) ($offer['ends_at'] ?? ''));
        if ($startsAt === '' && $endsAt === '') {
            $offer['validity_label'] = 'Fără termen';
            $offer['validity_detail'] = 'Până la dezactivare';
        } else {
            $offer['validity_label'] = $lifecycle === 'scheduled' ? 'Începe ' . $this->localCustomerDate($startsAt) : 'Perioadă stabilită';
            $offer['validity_detail'] = ($startsAt !== '' ? $this->localCustomerDate($startsAt) : 'Acum')
                . ' – ' . ($endsAt !== '' ? $this->localCustomerDate($endsAt) : 'fără final');
        }
    }

    /** @return array{customer:array<string,mixed>,discounts:list<array<string,mixed>>,orders:array<string,mixed>}|null */
    private function customerDetailData(int $customerId, int $orderPage): ?array
    {
        $data = $this->customers->detail($customerId, $orderPage, 4);
        if ($data === null) {
            return null;
        }

        $this->decorateCustomer($data['customer']);
        $statusLabels = [
            'new' => 'Nouă', 'confirmed' => 'Confirmată', 'preparing' => 'În pregătire',
            'shipped' => 'Expediată', 'delivered' => 'Livrată', 'cancelled' => 'Anulată',
            'returned' => 'Returnată', 'manual_review' => 'De verificat',
        ];
        $paymentLabels = [
            'pending' => 'În așteptare', 'paid' => 'Plătită', 'failed' => 'Eșuată',
            'partially_refunded' => 'Rambursată parțial', 'refunded' => 'Rambursată',
        ];
        foreach ($data['orders']['items'] as &$order) {
            $order['display_created_at'] = $this->localCustomerDate((string) ($order['created_at'] ?? ''));
            $order['status_label'] = $statusLabels[(string) ($order['status'] ?? '')] ?? ucfirst((string) ($order['status'] ?? ''));
            $order['payment_status_label'] = $paymentLabels[(string) ($order['payment_status'] ?? '')] ?? ucfirst((string) ($order['payment_status'] ?? ''));
            $order['payment_method_label'] = (string) ($order['payment_method'] ?? '') === 'stripe' ? 'Card online' : 'Ramburs';
            $names = array_values(array_filter(array_map('trim', explode(',', (string) ($order['product_names'] ?? '')))));
            $order['product_preview'] = implode(', ', array_slice($names, 0, 2));
            $order['more_products'] = max(0, count($names) - 2);
        }
        unset($order);
        foreach ($data['discounts'] as &$discount) {
            $discount['scope_label'] = match ((string) $discount['scope']) {
                'order' => 'Toată comanda', 'product' => 'Produs', 'category' => 'Categorie', 'shipping' => 'Livrare', default => 'Reducere',
            };
            $discount['value_label'] = match ((string) $discount['value_type']) {
                'free' => 'Livrare gratuită',
                'percent' => rtrim(rtrim(number_format(((int) $discount['value']) / 100, 2, ',', ''), '0'), ',') . '% reducere',
                default => Money::format((int) $discount['value']) . ' reducere',
            };
            $discount['validity_label'] = ($discount['starts_at'] ?? null) && ($discount['ends_at'] ?? null)
                ? $this->localCustomerDate((string) $discount['starts_at']) . ' – ' . $this->localCustomerDate((string) $discount['ends_at'])
                : 'Fără termen · mereu activă';
        }
        unset($discount);
        $firstPage = max(1, $data['orders']['page'] - 2);
        $lastPage = min($data['orders']['total_pages'], $data['orders']['page'] + 2);
        $data['orders']['page_numbers'] = range($firstPage, $lastPage);

        return $data;
    }

    /** @return array{subscriber:array<string,mixed>,events:list<array<string,mixed>>}|null */
    private function newsletterSubscriberData(int $subscriberId): ?array
    {
        $data = $this->newsletter->detail($subscriberId);
        if ($data === null) {
            return null;
        }
        $this->decorateNewsletterSubscriber($data['subscriber']);
        $eventLabels = [
            'subscribe' => 'Abonare inițială', 'resubscribe' => 'Reabonare', 'unsubscribe' => 'Dezabonare',
            'bounce' => 'Adresă respinsă', 'complaint' => 'Raportare spam',
        ];
        foreach ($data['events'] as &$event) {
            $event['event_label'] = $eventLabels[(string) ($event['event_type'] ?? '')] ?? 'Actualizare';
            $event['source_label'] = $this->newsletterSourceLabel((string) ($event['source'] ?? ''));
            $event['display_created_at'] = $this->localCustomerDate((string) ($event['created_at'] ?? ''));
        }
        unset($event);
        return $data;
    }

    /** @param array<string,mixed> $subscriber */
    private function decorateNewsletterSubscriber(array &$subscriber): void
    {
        $linkedName = trim((string) ($subscriber['linked_first_name'] ?? $subscriber['customer_first_name'] ?? '') . ' ' . (string) ($subscriber['linked_last_name'] ?? $subscriber['customer_last_name'] ?? ''));
        $subscriber['display_name'] = trim((string) ($subscriber['name'] ?? '')) ?: ($linkedName ?: 'Abonat newsletter');
        $parts = preg_split('/\s+/u', $subscriber['display_name'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $subscriber['initials'] = mb_strtoupper(mb_substr((string) ($parts[0] ?? 'N'), 0, 1) . mb_substr((string) ($parts[count($parts) - 1] ?? ''), 0, 1));
        $subscriber['source_label'] = $this->newsletterSourceLabel((string) ($subscriber['subscribe_source'] ?? ''));
        $subscriber['unsubscribe_source_label'] = $this->newsletterSourceLabel((string) ($subscriber['unsubscribe_source'] ?? ''));
        $subscriber['status_label'] = match ((string) ($subscriber['status'] ?? '')) {
            'subscribed' => 'Abonat activ', 'unsubscribed' => 'Dezabonat', 'bounced' => 'Adresă respinsă', 'complained' => 'Raportat spam', default => 'Stare necunoscută',
        };
        $subscriber['display_subscribed_at'] = $this->localCustomerDate((string) ($subscriber['subscribed_at'] ?? ''));
        $subscriber['display_unsubscribed_at'] = $this->localCustomerDate((string) ($subscriber['unsubscribed_at'] ?? ''), 'Nu s-a dezabonat');
        $subscriber['display_last_notified_at'] = $this->localCustomerDate((string) ($subscriber['last_notified_at'] ?? ''), 'Nicio notificare');
    }

    private function newsletterSourceLabel(string $source): string
    {
        return match ($source) {
            'footer_newsletter' => 'Formular newsletter', 'contact_form' => 'Formular contact', 'checkout' => 'Finalizare comandă',
            'admin_import' => 'Import administrativ', 'email_link' => 'Link din email', 'contact_request' => 'Cerere de contact',
            'admin' => 'Administrator', 'bounce' => 'Adresă respinsă', 'complaint' => 'Raportare spam', default => 'Nespecificată',
        };
    }

    /** @return list<array{value:string,label:string,description:string}> */
    private function shippingTypeOptions(): array
    {
        return [
            ['value' => 'courier', 'label' => 'Curier la adresă', 'description' => 'Coletul ajunge la adresa clientului.'],
            ['value' => 'locker', 'label' => 'Easybox / locker', 'description' => 'Clientul ridică pachetul dintr-un locker.'],
            ['value' => 'pickup', 'label' => 'Ridicare personală', 'description' => 'Comanda este ridicată dintr-un punct stabilit.'],
            ['value' => 'other', 'label' => 'Altă metodă', 'description' => 'O opțiune de livrare configurată manual.'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function customerDiscountFormData(int $customerId): ?array
    {
        $detail = $this->customers->detail($customerId, 1, 1);
        if ($detail === null) {
            return null;
        }
        $customer = $detail['customer'];
        $this->decorateCustomer($customer);
        return ['customer' => $customer, 'catalog' => $this->customers->discountCatalog()];
    }

    /** @param array<string,mixed> $customer */
    private function decorateCustomer(array &$customer): void
    {
        $customer['display_name'] = trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? '')) ?: 'Client fără nume';
        $customer['initials'] = mb_strtoupper(mb_substr((string) ($customer['first_name'] ?? ''), 0, 1) . mb_substr((string) ($customer['last_name'] ?? ''), 0, 1));
        $customer['initials'] = $customer['initials'] !== '' ? $customer['initials'] : 'CL';
        $customer['display_created_at'] = $this->localCustomerDate((string) ($customer['created_at'] ?? ''));
        $customer['display_last_login_at'] = $this->localCustomerDate((string) ($customer['last_login_at'] ?? ''), 'Nicio conectare înregistrată');
        $customer['display_last_order_at'] = $this->localCustomerDate((string) ($customer['last_order_at'] ?? ''), 'Nicio comandă');
        $customer['display_first_order_at'] = $this->localCustomerDate((string) ($customer['first_order_at'] ?? ''), 'Nicio comandă');
        $customer['display_blocked_at'] = $this->localCustomerDate((string) ($customer['blocked_at'] ?? ''), '—');
        $customer['account_type_label'] = (int) ($customer['uses_google'] ?? 0) === 1 ? 'Google' : 'La Măriuca';
        $customer['subscription_label'] = (int) ($customer['is_subscribed'] ?? 0) === 1 ? 'Abonat la noutăți' : 'Neabonat';
        $customer['status_label'] = match ((string) ($customer['status'] ?? '')) {
            'active' => 'Cont activ',
            'blocked' => 'Cont blocat',
            'pending' => 'În așteptare',
            default => ucfirst((string) ($customer['status'] ?? '')),
        };
    }

    private function localCustomerDate(string $value, string $empty = '—'): string
    {
        if (trim($value) === '') {
            return $empty;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Bucharest'))
                ->format('d.m.Y, H:i');
        } catch (\Throwable) {
            return $empty;
        }
    }
}
