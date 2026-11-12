<?php

declare(strict_types=1);

namespace App;

use App\Modules\Admin\AdminController;
use App\Modules\Admin\AdminDashboardService;
use App\Modules\Admin\AdminNotificationService;
use App\Modules\Admin\AdminPushController;
use App\Modules\Admin\AdminPushService;
use App\Modules\Admin\OrderNotificationSettingsController;
use App\Modules\Admin\PaymentMethodSettingsController;
use App\Modules\Cart\CartController;
use App\Modules\Cart\CartService;
use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Catalog\CatalogSearchService;
use App\Modules\Catalog\CategoryPathRepository;
use App\Modules\Catalog\ProductRecommendationService;
use App\Modules\Catalog\StorefrontController;
use App\Modules\Checkout\CheckoutController;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Checkout\OrderResultPresenter;
use App\Modules\Checkout\PostalCodeController;
use App\Modules\Companies\CompanyAdminController;
use App\Modules\Companies\CompanyAdminService;
use App\Modules\Companies\CompanyRepository;
use App\Modules\Companies\CompanyStampStorage;
use App\Modules\Content\ContentRepository;
use App\Modules\Content\ContactWhatsAppService;
use App\Modules\Content\ContentAdminService;
use App\Modules\Customers\AuthController;
use App\Modules\Customers\CustomerAccountRepository;
use App\Modules\Customers\CustomerAccountService;
use App\Modules\Customers\CustomerAdminService;
use App\Modules\Customers\GoogleOAuthService;
use App\Modules\Customers\TwoFactorController;
use App\Modules\Media\MediaService;
use App\Modules\Newsletter\NewsletterAdminService;
use App\Modules\Newsletter\NewsletterSubscriptionService;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\InternalOrderNotificationService;
use App\Modules\Orders\OrderNotificationSettingsService;
use App\Modules\Payments\StripeWebhookController;
use App\Modules\Payments\StripeCheckoutService;
use App\Modules\Payments\CategoryPaymentPolicy;
use App\Modules\Payments\PaymentMethodSettingsService;
use App\Modules\Promotions\PromotionAdminService;
use App\Modules\Reviews\ProductReviewRepository;
use App\Modules\Reviews\ProductReviewService;
use App\Modules\Shipping\ShippingMethodService;
use App\Modules\Suppliers\SupplierAdminService;
use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Http\AdminMiddleware;
use App\Shared\Http\AdminRememberMiddleware;
use App\Shared\Http\CsrfMiddleware;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\SecurityHeadersMiddleware;
use App\Shared\Http\SessionMiddleware;
use App\Shared\Http\View;
use App\Shared\Security\AdminRememberMe;
use App\Shared\Security\Auth;
use App\Shared\Security\Csrf;
use App\Shared\Security\ProductDescriptionSanitizer;
use App\Shared\Security\TotpService;
use App\Shared\Support\Flash;
use App\Shared\Support\RomanianLocations;
use App\Shared\Support\RomanianPostalCodes;
use App\Shared\Support\Money;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

final class AppFactory
{
    public static function create(string $root): App
    {
        $config = Config::load($root);
        date_default_timezone_set($config->get('APP_TIMEZONE', 'Europe/Bucharest'));
        $pdo = ConnectionFactory::create($config, $root);
        $csrf = new Csrf();
        $auth = new Auth($pdo);
        $adminRememberMe = new AdminRememberMe($pdo, $config);
        $catalog = new CatalogRepository($pdo);
        $catalogSearch = new CatalogSearchService($catalog);
        $categoryPaths = new CategoryPathRepository($pdo);
        $push = new AdminPushService($pdo, $config);
        $reviews = new ProductReviewService(new ProductReviewRepository($pdo), $push);
        $cart = new CartService($pdo);
        $contactWhatsApp = new ContactWhatsAppService($config);
        $newsletterSubscriptions = new NewsletterSubscriptionService($pdo);
        $promotions = new PromotionAdminService($pdo);
        $shippingMethods = new ShippingMethodService($pdo);
        $productDescriptions = new ProductDescriptionSanitizer();

        $twig = new Environment(new FilesystemLoader($root . '/resources/views'), [
            'cache' => false,
            'debug' => $config->bool('APP_DEBUG'),
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
        $twig->addFunction(new TwigFunction('url', static fn(string $path = ''): string => $config->path($path)));
        $twig->addFunction(new TwigFunction('absolute_url', static fn(string $path = ''): string => $config->rootUrl() . '/' . ltrim($path, '/')));
        $twig->addFunction(new TwigFunction('whatsapp_url', static function () use ($config): string {
            $number = preg_replace('/\D+/', '', $config->get('WHATSAPP_NUMBER')) ?? '';
            $message = $config->get('WHATSAPP_MESSAGE', 'Bună! Aș dori mai multe informații despre produsele La Măriuca Boutique.');
            return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
        }));
        $twig->addFunction(new TwigFunction('contact_phone_url', static fn(): string => $contactWhatsApp->phoneUrl()));
        $twig->addFunction(new TwigFunction('contact_phone_display', static fn(): string => $contactWhatsApp->phoneDisplay()));
        $twig->addFunction(new TwigFunction('contact_email', static fn(): string => $contactWhatsApp->contactEmail()));
        $twig->addFunction(new TwigFunction('money', static fn(int|string|null $bani): string => Money::format((int) $bani)));
        $twig->addFunction(new TwigFunction('percent_basis_points', static function (int|string|null $basisPoints): string {
            $value = (int) $basisPoints;
            $whole = intdiv($value, 100);
            $fraction = $value % 100;
            return $fraction === 0 ? (string) $whole : $whole . ',' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => $csrf->token()));
        $twig->addFunction(new TwigFunction('flash_messages', static fn(): array => Flash::pull()));
        $twig->addFunction(new TwigFunction('current_user', static fn(): ?array => $auth->user()));
        $twig->addFunction(new TwigFunction('navigation_categories', static fn(): array => $categoryPaths->addPathsToTree($catalog->navigationCategories())));
        $twig->addFunction(new TwigFunction('cart_summary', static fn(): array => $cart->summary()));
        $twig->addGlobal('active_promotion_banner', $promotions->activeTopbarOffer());
        $cartVariantIds = null;
        $twig->addFunction(new TwigFunction('is_variant_in_cart', static function (int|string|null $variantId) use ($cart, &$cartVariantIds): bool {
            if ((int) $variantId <= 0) {
                return false;
            }
            if ($cartVariantIds === null) {
                $cartVariantIds = [];
                foreach ($cart->summary()['items'] as $item) {
                    $cartVariantIds[(int) $item['variant_id']] = true;
                }
            }
            return isset($cartVariantIds[(int) $variantId]);
        }));
        $twig->addFunction(new TwigFunction('favorite_count', static fn(): int => count(array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))))));
        $twig->addFunction(new TwigFunction('is_favorite', static fn(int|string $productId): bool => in_array((int) $productId, array_unique(array_map('intval', (array) ($_SESSION['favorites'] ?? []))), true)));
        $twig->addFilter(new TwigFilter('json_decode', static fn(string $value): array => json_decode($value, true) ?: []));
        $twig->addFilter(new TwigFilter('decimal_ro', static function (int|string|null $value): string {
            $text=(string) $value;
            if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D',$text,$parts)) { return $text; }
            $whole=preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[2]);
            $fraction=rtrim($parts[3] ?? '', '0');
            return $parts[1].$whole.($fraction===''?'':','.$fraction);
        }));
        $twig->addFilter(new TwigFilter(
            'product_description_html',
            static fn(?string $value): string => $productDescriptions->sanitize((string) $value),
            ['is_safe' => ['html']]
        ));
        $view = new View($twig);
        $rateLimiter = new RateLimiter($pdo);
        $content = new ContentRepository($pdo);
        $orders = new OrderService($pdo);
        $internalOrderNotifications = new InternalOrderNotificationService($pdo);
        $tracking = new \App\Modules\Orders\OrderTrackingController(new \App\Modules\Orders\OrderTrackingService(new \App\Modules\Orders\OrderTrackingRepository($pdo), $orders, $config, $root, $push), $rateLimiter, $view, $config);

        $storefront = new StorefrontController($catalog, $catalogSearch, $categoryPaths, new ProductRecommendationService($catalog), $content, $contactWhatsApp, $newsletterSubscriptions, $reviews, $rateLimiter, $auth, $view, $config);
        $cartController = new CartController($cart, $view, $config);
        $romanianLocations = RomanianLocations::fromProjectRoot($root);
        $postalCodeController = new PostalCodeController(RomanianPostalCodes::fromProjectRoot($root));
        $customerAccount = new CustomerAccountService(new CustomerAccountRepository($pdo), $romanianLocations);
        $paymentMethodSettings = new PaymentMethodSettingsService($pdo, $config);
        $checkoutController = new CheckoutController(new CheckoutService($pdo, $cart, $config, $romanianLocations, $push, $shippingMethods, new CategoryPaymentPolicy($pdo, $config, $paymentMethodSettings), $internalOrderNotifications), $cart, $orders, new StripeCheckoutService($pdo, $config, $paymentMethodSettings), $auth, $customerAccount, $rateLimiter, $view, $config, $shippingMethods, new OrderResultPresenter($config), new \App\Modules\Checkout\CheckoutAttemptService($pdo, $config, $push));
        $authController = new AuthController($pdo, $auth, new GoogleOAuthService($pdo, $config), $customerAccount, $rateLimiter, $view, $config);
        $twoFactorController = new TwoFactorController($pdo, $auth, new TotpService($config), $adminRememberMe, $rateLimiter, $view, $config);
        $adminController = new AdminController($pdo, new AdminDashboardService($pdo), new AdminNotificationService($pdo), $push, $catalog, new CatalogAdminService($pdo, $productDescriptions), new CustomerAdminService($pdo), new NewsletterAdminService($pdo), $promotions, $shippingMethods, new SupplierAdminService($pdo), $orders, $reviews, new MediaService($pdo, $root), new ContentAdminService($pdo), $auth, $adminRememberMe, $rateLimiter, $view, $config, new \App\Modules\Orders\OrderCancellationService(new \App\Modules\Orders\OrderTrackingRepository($pdo), $orders));
        $adminPushController = new AdminPushController($push, $auth, $view, $config);
        $orderNotificationSettingsController = new OrderNotificationSettingsController(new OrderNotificationSettingsService($pdo), new AdminNotificationService($pdo), $auth, $view, $config);
        $paymentMethodSettingsController = new PaymentMethodSettingsController($paymentMethodSettings, new AdminNotificationService($pdo), $auth, $view, $config, new \App\Modules\Payments\PaymentFeeService($pdo));
        $companyAdminController = new CompanyAdminController(new CompanyAdminService(new CompanyRepository($pdo), new CompanyStampStorage($root)), new AdminNotificationService($pdo), $auth, $view, $config, new \App\Modules\Companies\ReceiptLocationService(new CompanyRepository($pdo)));
        $nirController = new \App\Modules\Inventory\NirAdminController(
            new \App\Modules\Inventory\NirService($pdo), new \App\Modules\Inventory\NirDocumentStorage($root),
            new \App\Modules\Inventory\NirImportService(), new \App\Modules\Inventory\NirExportService($root),
            new \App\Modules\Inventory\NirExchangeRateService($root), new SupplierAdminService($pdo),
            new AdminNotificationService($pdo), $auth, $view, $config,
        );

        $app = SlimAppFactory::create();
        $basePath = '/' . trim($config->get('APP_BASE_PATH'), '/');
        if ($basePath !== '/') {
            $app->setBasePath($basePath);
        }

        $app->get('/', [$storefront, 'home']);
        $app->get('/categorie/{path:.+}/', [$storefront, 'category']);
        $app->get('/colectie/{slug}/', [$storefront, 'collection']);
        $app->get('/produs/{slug}/', [$storefront, 'product']);
        $app->post('/produs/{slug}/recenzie/', [$storefront, 'submitReview']);
        $app->get('/magazin/', [$storefront, 'shop']);
        $app->get('/cautare/', [$storefront, 'search']);
        $app->get('/cautare/sugestii/', [$storefront, 'searchSuggestions']);
        $app->get('/favorite/', [$storefront, 'favorites']);
        $app->post('/favorite/', [$storefront, 'toggleFavorite']);
        $app->get('/cos/', [$cartController, 'show']);
        $app->post('/cos/adauga/', [$cartController, 'add']);
        $app->post('/cos/actualizeaza/', [$cartController, 'update']);
        $app->post('/cos/cupon/', [$cartController, 'coupon']);
        $app->get('/finalizare-comanda/', [$checkoutController, 'show']);
        $app->get('/finalizare-comanda/cod-postal/', [$postalCodeController, 'lookup']);
        $app->post('/finalizare-comanda/', [$checkoutController, 'place']);
        $app->post('/finalizare-comanda/adresa/', [$checkoutController, 'saveAddress']);
        $app->get('/comanda/{token}/', [$checkoutController, 'confirmation']);
        $app->post('/plata/{token}/reincearca/', [$checkoutController, 'resumePayment']);
        $app->post('/plata/{token}/anuleaza/', [$checkoutController, 'cancelPayment']);
        $app->get('/urmarire-comanda/', [$tracking, 'show']);
        $app->post('/urmarire-comanda/', [$tracking, 'lookup']);
        $app->post('/urmarire-comanda/alta/', [$tracking, 'reset']);
        $app->get('/urmarire-comanda/solicitare-anulare/', [$tracking, 'cancellationForm']);
        $app->post('/urmarire-comanda/solicitare-anulare/', [$tracking, 'requestCancellation']);
        $app->get('/urmarire-comanda/acces/{id:[0-9]+}/{signature}/', [$tracking, 'access']);
        $app->get('/urmarire-comanda/factura/{id:[0-9]+}/', [$tracking, 'invoice']);
        $app->post('/contact/whatsapp/', [$storefront, 'contactWhatsApp']);
        $app->post('/newsletter/abonare/', [$storefront, 'newsletterSubscribe']);
        $app->get('/pagina/{slug}/', [$storefront, 'page']);
        $app->get('/robots.txt', [$storefront, 'robots']);
        $app->get('/sitemap.xml', [$storefront, 'sitemap']);
        $app->post('/stripe/webhook', new StripeWebhookController($pdo, $config, $push, $internalOrderNotifications));
        $app->get('/admin-manifest.webmanifest', [$adminPushController, 'manifest']);

        $app->get('/cont/autentificare/', [$authController, 'loginForm']);
        $app->post('/cont/autentificare/', [$authController, 'login']);
        $app->get('/cont/google/', [$authController, 'googleStart']);
        $app->get('/cont/google/retur/', [$authController, 'googleCallback']);
        $app->get('/cont/totp/', [$twoFactorController, 'challengeForm']);
        $app->post('/cont/totp/', [$twoFactorController, 'challenge']);
        $app->get('/cont/inregistrare/', [$authController, 'registerForm']);
        $app->post('/cont/inregistrare/', [$authController, 'register']);
        $app->get('/cont/', [$authController, 'account']);
        $app->post('/cont/detalii/', [$authController, 'updateProfile']);
        $app->post('/cont/adrese/', [$authController, 'saveAddress']);
        $app->post('/cont/adrese/{id:[0-9]+}/implicita/', [$authController, 'setDefaultAddress']);
        $app->post('/cont/adrese/{id:[0-9]+}/sterge/', [$authController, 'deleteAddress']);
        $app->post('/cont/deconectare/', [$authController, 'logout']);

        $app->get('/admin', [$adminController, 'dashboard']);
        $app->get('/admin/', [$adminController, 'dashboard']);
        $app->post('/admin', [$adminController, 'login']);
        $app->post('/admin/', [$adminController, 'login']);
        $app->post('/admin/deconectare/', [$adminController, 'logout']);

        $app->group('/admin', function (RouteCollectorProxy $group) use ($adminController, $adminPushController, $twoFactorController, $orderNotificationSettingsController, $paymentMethodSettingsController, $companyAdminController, $nirController): void {
            $group->get('/categorii/', [$adminController, 'categories']);
            $group->get('/categorii/verifica-nume/', [$adminController, 'categoryNameAvailability']);
            $group->get('/categorii/verifica-slug/', [$adminController, 'categorySlugAvailability']);
            $group->get('/categorii/noua/', [$adminController, 'categoryForm']);
            $group->get('/categorii/noua/modal/', [$adminController, 'categoryModalForm']);
            $group->post('/categorii/noua/', [$adminController, 'saveCategory']);
            $group->get('/categorii/{id:[0-9]+}/editare/', [$adminController, 'categoryForm']);
            $group->get('/categorii/{id:[0-9]+}/editare/modal/', [$adminController, 'categoryModalForm']);
            $group->post('/categorii/{id:[0-9]+}/editare/', [$adminController, 'saveCategory']);
            $group->post('/categorii/{id:[0-9]+}/sterge/', [$adminController, 'deleteCategory']);
            $group->get('/produse/', [$adminController, 'products']);
            $group->get('/produse/verifica-nume/', [$adminController, 'productNameAvailability']);
            $group->get('/produse/verifica-sku/', [$adminController, 'productSkuAvailability']);
            $group->get('/produse/verifica-slug/', [$adminController, 'productSlugAvailability']);
            $group->post('/produse/descriere-imagine/', [$adminController, 'uploadProductDescriptionImage']);
            $group->post('/produse/galerie-imagine/', [$adminController, 'uploadProductDescriptionImage']);
            $group->get('/produse/nou/', [$adminController, 'productForm']);
            $group->get('/produse/nou/modal/', [$adminController, 'productModalForm']);
            $group->post('/produse/nou/', [$adminController, 'saveProduct']);
            $group->get('/produse/{id:[0-9]+}/editare/', [$adminController, 'productForm']);
            $group->get('/produse/{id:[0-9]+}/editare/modal/', [$adminController, 'productModalForm']);
            $group->post('/produse/{id:[0-9]+}/editare/', [$adminController, 'saveProduct']);
            $group->post('/produse/{id:[0-9]+}/sterge/', [$adminController, 'deleteProduct']);
            $group->get('/clienti/', [$adminController, 'customers']);
            $group->get('/clienti/{id:[0-9]+}/', [$adminController, 'customerDetail']);
            $group->get('/clienti/{id:[0-9]+}/modal/', [$adminController, 'customerModal']);
            $group->post('/clienti/{id:[0-9]+}/stare/', [$adminController, 'customerState']);
            $group->get('/clienti/{id:[0-9]+}/reduceri/noua/', [$adminController, 'customerDiscountForm']);
            $group->get('/clienti/{id:[0-9]+}/reduceri/noua/modal/', [$adminController, 'customerDiscountModal']);
            $group->post('/clienti/{id:[0-9]+}/reduceri/noua/', [$adminController, 'saveCustomerDiscount']);
            $group->post('/clienti/{id:[0-9]+}/reduceri/{discount_id:[0-9]+}/sterge/', [$adminController, 'deleteCustomerDiscount']);
            $group->get('/abonati-newsletter/', [$adminController, 'newsletterSubscribers']);
            $group->get('/abonati-newsletter/{id:[0-9]+}/', [$adminController, 'newsletterSubscriberDetail']);
            $group->get('/abonati-newsletter/{id:[0-9]+}/modal/', [$adminController, 'newsletterSubscriberModal']);
            $group->get('/cupoane-promotii/', [$adminController, 'promotions']);
            $group->get('/cupoane-promotii/cupon/nou/', [$adminController, 'couponForm']);
            $group->get('/cupoane-promotii/cupon/nou/modal/', [$adminController, 'couponModal']);
            $group->post('/cupoane-promotii/cupon/nou/', [$adminController, 'savePromotionCoupon']);
            $group->get('/cupoane-promotii/reducere/nou/', [$adminController, 'promotionForm']);
            $group->get('/cupoane-promotii/reducere/nou/modal/', [$adminController, 'promotionModal']);
            $group->post('/cupoane-promotii/reducere/nou/', [$adminController, 'savePromotion']);
            $group->get('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/editare/', [$adminController, 'editPromotionOffer']);
            $group->get('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/editare/modal/', [$adminController, 'editPromotionOfferModal']);
            $group->post('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/editare/', [$adminController, 'updatePromotionOffer']);
            $group->get('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/{action:dezactiveaza|reactiveaza|sterge}/confirmare/', [$adminController, 'confirmPromotionAction']);
            $group->post('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/reactiveaza/', [$adminController, 'reactivatePromotionOffer']);
            $group->post('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/sterge/', [$adminController, 'deletePromotionOffer']);
            $group->post('/cupoane-promotii/{offer_type:cupon|reducere}/{id:[0-9]+}/dezactiveaza/', [$adminController, 'deactivatePromotionOffer']);
            $group->get('/livrare/', [$adminController, 'shippingMethods']);
            $group->get('/metode-plata/', [$paymentMethodSettingsController, 'index']);
            $group->post('/metode-plata/', [$paymentMethodSettingsController, 'save']);
            $group->get('/metode-plata/costuri/nou/', [$paymentMethodSettingsController, 'feeForm']);
            $group->post('/metode-plata/costuri/nou/', [$paymentMethodSettingsController, 'saveFee']);
            $group->get('/metode-plata/costuri/{id:[0-9]+}/{action:editare|sterge}/', [$paymentMethodSettingsController, 'feeForm']);
            $group->post('/metode-plata/costuri/{id:[0-9]+}/{action:editare|sterge}/', [$paymentMethodSettingsController, 'saveFee']);
            $group->get('/livrare/noua/', [$adminController, 'shippingMethodForm']);
            $group->get('/livrare/noua/modal/', [$adminController, 'shippingMethodModal']);
            $group->post('/livrare/noua/', [$adminController, 'saveShippingMethod']);
            $group->get('/livrare/{id:[0-9]+}/editare/', [$adminController, 'shippingMethodForm']);
            $group->get('/livrare/{id:[0-9]+}/editare/modal/', [$adminController, 'shippingMethodModal']);
            $group->post('/livrare/{id:[0-9]+}/editare/', [$adminController, 'saveShippingMethod']);
            $group->post('/livrare/{id:[0-9]+}/stare/', [$adminController, 'shippingMethodStatus']);
            $group->get('/furnizori/', [$adminController, 'suppliers']);
            $group->get('/furnizori/nou/', [$adminController, 'supplierForm']);
            $group->get('/furnizori/nou/modal/', [$adminController, 'supplierModalForm']);
            $group->post('/furnizori/nou/', [$adminController, 'saveSupplier']);
            $group->get('/furnizori/{id:[0-9]+}/editare/', [$adminController, 'supplierForm']);
            $group->get('/furnizori/{id:[0-9]+}/editare/modal/', [$adminController, 'supplierModalForm']);
            $group->post('/furnizori/{id:[0-9]+}/editare/', [$adminController, 'saveSupplier']);
            $group->post('/furnizori/{id:[0-9]+}/sterge/', [$adminController, 'deleteSupplier']);
            $group->get('/detaliile-firmei/', [$companyAdminController, 'index']);
            $group->get('/nir-uri/', [$nirController, 'index']);
            $group->get('/nir-uri/stoc/', [$nirController, 'fifo']);
            $group->get('/nir-uri/fifo/', [$nirController, 'legacyFifo']);
            $group->get('/nir-uri/sold-initial/', [$nirController, 'actionForm']);
            $group->get('/nir-uri/sold-initial/modal/', [$nirController, 'actionForm']);
            $group->post('/nir-uri/sold-initial/', [$nirController, 'opening']);
            $group->get('/nir-uri/retururi/{id:[0-9]+}/receptie/', [$nirController, 'returnForm']);
            $group->get('/nir-uri/retururi/{id:[0-9]+}/receptie/modal/', [$nirController, 'returnForm']);
            $group->post('/nir-uri/retururi/{id:[0-9]+}/receptie/', [$nirController, 'receiveReturn']);
            $group->post('/nir-uri/retururi/{id:[0-9]+}/amanare/', [$nirController, 'dismissReturn']);
            $group->post('/nir-uri/referinte/{id:[0-9]+}/reasociere/', [$nirController, 'reassign']);
            $group->get('/nir-uri/produse/', [$nirController, 'lookup']);
            $group->get('/nir-uri/curs/', [$nirController, 'exchangeRate']);
            $group->post('/nir-uri/asociere/', [$nirController, 'matchLine']);
            $group->post('/nir-uri/furnizor/', [$nirController, 'supplier']);
            $group->post('/nir-uri/locatii/', [$nirController, 'location']);
            $group->post('/nir-uri/import/', [$nirController, 'import']);
            $group->get('/nir-uri/export/', [$nirController, 'actionForm']);
            $group->get('/nir-uri/export/modal/', [$nirController, 'actionForm']);
            $group->get('/nir-uri/export/{format:pdf|xlsx|zip}/', [$nirController, 'export']);
            $group->get('/nir-uri/nou/', [$nirController, 'form']);
            $group->get('/nir-uri/nou/modal/', [$nirController, 'form']);
            $group->post('/nir-uri/nou/', [$nirController, 'save']);
            $group->get('/nir-uri/{id:[0-9]+}/', [$nirController, 'detail']);
            foreach (['editare','corectie'] as $action) {
                $group->get('/nir-uri/{id:[0-9]+}/'.$action.'/', [$nirController, 'form']);
                $group->get('/nir-uri/{id:[0-9]+}/'.$action.'/modal/', [$nirController, 'form']);
                $group->post('/nir-uri/{id:[0-9]+}/'.$action.'/', [$nirController, 'save']);
            }
            foreach (['confirma','sterge','storno','documente'] as $action) {
                $group->get('/nir-uri/{id:[0-9]+}/'.$action.'/', [$nirController, 'actionForm']);
                $group->get('/nir-uri/{id:[0-9]+}/'.$action.'/modal/', [$nirController, 'actionForm']);
                $group->post('/nir-uri/{id:[0-9]+}/'.$action.'/', [$nirController, 'mutate']);
            }
            $group->get('/nir-uri/{id:[0-9]+}/documente/{attachment:[0-9]+}/', [$nirController, 'download']);
            $group->post('/nir-uri/{id:[0-9]+}/documente/{attachment:[0-9]+}/sterge/', [$nirController, 'detach']);
            $group->get('/nir-uri/{id:[0-9]+}/documente/{attachment:[0-9]+}/sterge/', [$nirController, 'detachForm']);
            $group->get('/nir-uri/{id:[0-9]+}/documente/{attachment:[0-9]+}/sterge/modal/', [$nirController, 'detachForm']);
            $group->get('/nir-uri/{id:[0-9]+}/export/{format:pdf|xlsx|zip}/', [$nirController, 'export']);
            $group->get('/detaliile-firmei/nou/', [$companyAdminController, 'companyForm']);
            $group->get('/detaliile-firmei/nou/modal/', [$companyAdminController, 'companyForm']);
            $group->post('/detaliile-firmei/nou/', [$companyAdminController, 'saveCompany']);
            $group->get('/detaliile-firmei/{id:[0-9]+}/editare/', [$companyAdminController, 'companyForm']);
            $group->get('/detaliile-firmei/{id:[0-9]+}/editare/modal/', [$companyAdminController, 'companyForm']);
            $group->post('/detaliile-firmei/{id:[0-9]+}/editare/', [$companyAdminController, 'saveCompany']);
            $group->get('/detaliile-firmei/{id:[0-9]+}/sterge/', [$companyAdminController, 'companyDeleteForm']);
            $group->get('/detaliile-firmei/{id:[0-9]+}/sterge/modal/', [$companyAdminController, 'companyDeleteForm']);
            $group->post('/detaliile-firmei/{id:[0-9]+}/sterge/', [$companyAdminController, 'deleteCompany']);
            $group->get('/detaliile-firmei/{id:[0-9]+}/stampila/', [$companyAdminController, 'stamp']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/nou/', [$companyAdminController, 'warehouseForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/nou/modal/', [$companyAdminController, 'warehouseForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/nou/', [$companyAdminController, 'saveWarehouse']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/editare/', [$companyAdminController, 'warehouseForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/editare/modal/', [$companyAdminController, 'warehouseForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/editare/', [$companyAdminController, 'saveWarehouse']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/sterge/', [$companyAdminController, 'warehouseDeleteForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/sterge/modal/', [$companyAdminController, 'warehouseDeleteForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/gestiuni/{id:[0-9]+}/sterge/', [$companyAdminController, 'deleteWarehouse']);
            $group->get('/comenzi/', [$adminController, 'orders']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/nou/', [$companyAdminController, 'locationForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/nou/modal/', [$companyAdminController, 'locationForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/locatii/nou/', [$companyAdminController, 'saveLocation']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/editare/', [$companyAdminController, 'locationForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/editare/modal/', [$companyAdminController, 'locationForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/editare/', [$companyAdminController, 'saveLocation']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/sterge/', [$companyAdminController, 'locationForm']);
            $group->get('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/sterge/modal/', [$companyAdminController, 'locationForm']);
            $group->post('/detaliile-firmei/{companyId:[0-9]+}/locatii/{id:[0-9]+}/sterge/', [$companyAdminController, 'deleteLocation']);
            $group->get('/comenzi/notificari/', [$orderNotificationSettingsController, 'form']);
            $group->get('/comenzi/notificari/modal/', [$orderNotificationSettingsController, 'form']);
            $group->post('/comenzi/notificari/', [$orderNotificationSettingsController, 'save']);
            $group->get('/comenzi/{id:[0-9]+}/', [$adminController, 'orderDetail']);
            $group->get('/comenzi/{id:[0-9]+}/status/modal/', [$adminController, 'orderStatusModal']);
            $group->get('/comenzi/{id:[0-9]+}/retur/modal/', [$adminController, 'orderReturnModal']);
            $group->get('/comenzi/{id:[0-9]+}/email/modal/', [$adminController, 'orderEmailModal']);
            $group->get('/comenzi/{id:[0-9]+}/anulare/{decision:accepta|respinge}/', [$adminController, 'orderCancellationForm']);
            $group->post('/comenzi/{id:[0-9]+}/anulare/decizie/', [$adminController, 'decideOrderCancellation']);
            $group->post('/comenzi/{id:[0-9]+}/actiune/', [$adminController, 'transitionOrder']);
            $group->post('/comenzi/{id:[0-9]+}/retur/', [$adminController, 'returnOrder']);
            $group->post('/comenzi/{id:[0-9]+}/email/', [$adminController, 'emailOrderStatus']);
            $group->get('/notificari/', [$adminController, 'notifications']);
            $group->post('/notificari/citeste/', [$adminController, 'readNotification']);
            $group->post('/notificari/sterge/', [$adminController, 'deleteNotification']);
            $group->post('/notificari/preferinte/', [$adminController, 'notificationPreferences']);
            $group->post('/notificari/push/abonare/', [$adminPushController, 'subscribe']);
            $group->post('/notificari/push/dezabonare/', [$adminPushController, 'unsubscribe']);
            $group->post('/notificari/push/test/', [$adminPushController, 'test']);
            $group->post('/notificari/push/dispozitive/{id:[0-9]+}/sterge/', [$adminPushController, 'removeDevice']);
            $group->get('/recenzii/', [$adminController, 'reviews']);
            $group->post('/recenzii/{id:[0-9]+}/actiune/', [$adminController, 'moderateReview']);
            $group->get('/continut/', [$adminController, 'content']);
            $group->post('/continut/sectiuni/{id:[0-9]+}/actiune/', [$adminController, 'sectionAction']);
            $group->get('/pagini/noua/', [$adminController, 'pageForm']);
            $group->post('/pagini/noua/', [$adminController, 'savePage']);
            $group->get('/pagini/{id:[0-9]+}/editare/', [$adminController, 'pageForm']);
            $group->post('/pagini/{id:[0-9]+}/editare/', [$adminController, 'savePage']);
            $group->get('/media/', [$adminController, 'media']);
            $group->post('/media/', [$adminController, 'uploadMedia']);
            $group->get('/cupoane/', [$adminController, 'coupons']);
            $group->post('/cupoane/', [$adminController, 'saveCoupon']);
            $group->get('/securitate/totp/', [$twoFactorController, 'setupForm']);
            $group->post('/securitate/totp/', [$twoFactorController, 'setup']);
        })->add(new AdminMiddleware($auth, $config));

        $app->add(new CsrfMiddleware($csrf));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new AdminRememberMiddleware($auth, $adminRememberMe));
        $app->add(new SessionMiddleware($config));
        $app->add(new SecurityHeadersMiddleware($config));
        $app->addErrorMiddleware($config->bool('APP_DEBUG'), true, true);
        return $app;
    }
}
