<?php

declare(strict_types=1);

namespace App;

use App\Modules\Admin\AdminController;
use App\Modules\Cart\CartController;
use App\Modules\Cart\CartService;
use App\Modules\Catalog\CatalogAdminService;
use App\Modules\Catalog\CatalogRepository;
use App\Modules\Catalog\StorefrontController;
use App\Modules\Checkout\CheckoutController;
use App\Modules\Checkout\CheckoutService;
use App\Modules\Content\ContentRepository;
use App\Modules\Content\ContentAdminService;
use App\Modules\Customers\AuthController;
use App\Modules\Customers\TwoFactorController;
use App\Modules\Media\MediaService;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\StripeWebhookController;
use App\Modules\Payments\StripeCheckoutService;
use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Http\AdminMiddleware;
use App\Shared\Http\CsrfMiddleware;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\SecurityHeadersMiddleware;
use App\Shared\Http\SessionMiddleware;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Security\Csrf;
use App\Shared\Security\TotpService;
use App\Shared\Support\Flash;
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
        $catalog = new CatalogRepository($pdo);
        $cart = new CartService($pdo);

        $twig = new Environment(new FilesystemLoader($root . '/resources/views'), [
            'cache' => false,
            'debug' => $config->bool('APP_DEBUG'),
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
        $twig->addFunction(new TwigFunction('url', static fn(string $path = ''): string => $config->path($path)));
        $twig->addFunction(new TwigFunction('absolute_url', static fn(string $path = ''): string => $config->rootUrl() . '/' . ltrim($path, '/')));
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
        $twig->addFunction(new TwigFunction('navigation_categories', static fn(): array => $catalog->navigationCategories()));
        $twig->addFunction(new TwigFunction('cart_summary', static fn(): array => $cart->summary()));
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
        $view = new View($twig);
        $rateLimiter = new RateLimiter($pdo);
        $content = new ContentRepository($pdo);
        $orders = new OrderService($pdo);

        $storefront = new StorefrontController($catalog, $content, $rateLimiter, $view, $config);
        $cartController = new CartController($cart, $view, $config);
        $checkoutController = new CheckoutController(new CheckoutService($pdo, $cart, $config), $cart, $orders, new StripeCheckoutService($pdo, $config), $rateLimiter, $view, $config);
        $authController = new AuthController($pdo, $auth, $rateLimiter, $view, $config);
        $twoFactorController = new TwoFactorController($pdo, $auth, new TotpService($config), $rateLimiter, $view, $config);
        $adminController = new AdminController($pdo, $catalog, new CatalogAdminService($pdo), $orders, new MediaService($pdo, $root), new ContentAdminService($pdo), $auth, $view, $config);

        $app = SlimAppFactory::create();
        $basePath = '/' . trim($config->get('APP_BASE_PATH'), '/');
        if ($basePath !== '/') {
            $app->setBasePath($basePath);
        }

        $app->get('/', [$storefront, 'home']);
        $app->get('/categorie/{slug}/', [$storefront, 'category']);
        $app->get('/colectie/{slug}/', [$storefront, 'collection']);
        $app->get('/produs/{slug}/', [$storefront, 'product']);
        $app->get('/cautare/', [$storefront, 'search']);
        $app->get('/cautare/sugestii/', [$storefront, 'searchSuggestions']);
        $app->get('/favorite/', [$storefront, 'favorites']);
        $app->post('/favorite/', [$storefront, 'toggleFavorite']);
        $app->get('/cos/', [$cartController, 'show']);
        $app->post('/cos/adauga/', [$cartController, 'add']);
        $app->post('/cos/actualizeaza/', [$cartController, 'update']);
        $app->post('/cos/cupon/', [$cartController, 'coupon']);
        $app->get('/finalizare-comanda/', [$checkoutController, 'show']);
        $app->post('/finalizare-comanda/', [$checkoutController, 'place']);
        $app->get('/comanda/{token}/', [$checkoutController, 'confirmation']);
        $app->get('/pagina/{slug}/', [$storefront, 'page']);
        $app->get('/robots.txt', [$storefront, 'robots']);
        $app->get('/sitemap.xml', [$storefront, 'sitemap']);
        $app->post('/stripe/webhook', new StripeWebhookController($pdo, $config));

        $app->get('/cont/autentificare/', [$authController, 'loginForm']);
        $app->post('/cont/autentificare/', [$authController, 'login']);
        $app->get('/cont/totp/', [$twoFactorController, 'challengeForm']);
        $app->post('/cont/totp/', [$twoFactorController, 'challenge']);
        $app->get('/cont/inregistrare/', [$authController, 'registerForm']);
        $app->post('/cont/inregistrare/', [$authController, 'register']);
        $app->get('/cont/', [$authController, 'account']);
        $app->post('/cont/deconectare/', [$authController, 'logout']);

        $app->group('/admin', function (RouteCollectorProxy $group) use ($adminController, $twoFactorController): void {
            $group->get('/', [$adminController, 'dashboard']);
            $group->get('/categorii/', [$adminController, 'categories']);
            $group->get('/categorii/noua/', [$adminController, 'categoryForm']);
            $group->post('/categorii/noua/', [$adminController, 'saveCategory']);
            $group->get('/categorii/{id:[0-9]+}/editare/', [$adminController, 'categoryForm']);
            $group->post('/categorii/{id:[0-9]+}/editare/', [$adminController, 'saveCategory']);
            $group->get('/produse/', [$adminController, 'products']);
            $group->get('/produse/nou/', [$adminController, 'productForm']);
            $group->post('/produse/nou/', [$adminController, 'saveProduct']);
            $group->get('/produse/{id:[0-9]+}/editare/', [$adminController, 'productForm']);
            $group->post('/produse/{id:[0-9]+}/editare/', [$adminController, 'saveProduct']);
            $group->get('/comenzi/', [$adminController, 'orders']);
            $group->post('/comenzi/{id:[0-9]+}/actiune/', [$adminController, 'transitionOrder']);
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
        $app->add(new SessionMiddleware($config));
        $app->add(new SecurityHeadersMiddleware($config));
        $app->addErrorMiddleware($config->bool('APP_DEBUG'), true, true);
        return $app;
    }
}
