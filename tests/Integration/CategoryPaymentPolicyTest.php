<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Payments\CategoryPaymentPolicy;
use App\Shared\Config\Config;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

final class CategoryPaymentPolicyTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $environment = [];
    private int $sequence = 0;

    protected function setUp(): void
    {
        foreach (['STRIPE_ENABLED', 'STRIPE_SECRET_KEY', 'COD_ENABLED'] as $key) {
            $this->environment[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['STRIPE_ENABLED'] = 'true';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_category_payment_policy';
        $_ENV['COD_ENABLED'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testExistingAndNewCategoriesDefaultToBothMethods(): void
    {
        $category = $this->category();
        $flags = $this->pdo->query('SELECT allow_card,allow_cod FROM categories WHERE id=' . $category)->fetch();
        self::assertSame(1, (int) $flags['allow_card']);
        self::assertSame(1, (int) $flags['allow_cod']);
        $result = $this->policy()->resolve([$this->product([$category]), $this->product()]);
        $this->assertMethods($result, true, true, 'stripe');
        self::assertFalse($result['fallback_to_cod']);
        self::assertFalse($result['fallback_to_stripe']);
    }

    /** @return iterable<string,array{list<string>,bool,bool,bool,bool,?string,bool,bool}> */
    public static function combinations(): iterable
    {
        yield 'unrestricted' => [['both'], true, true, true, true, 'stripe', false, false];
        yield 'card required' => [['card', 'both'], true, true, true, false, 'stripe', false, false];
        yield 'cash required' => [['cod', 'both'], true, true, false, true, 'cod', false, false];
        yield 'conflicting products favor card' => [['cod', 'card'], true, true, true, false, 'stripe', false, false];
        yield 'conflicting products reverse order' => [['card', 'cod'], true, true, true, false, 'stripe', false, false];
        yield 'card global off overrides card-only' => [['card'], false, true, false, true, 'cod', true, false];
        yield 'card global off overrides mixed restrictions' => [['card', 'cod'], false, true, false, true, 'cod', true, false];
        yield 'card global off unrestricted' => [['both'], false, true, false, true, 'cod', false, false];
        yield 'cash global off unrestricted' => [['both'], true, false, true, false, 'stripe', false, false];
        yield 'cash global off overrides cash-only' => [['cod'], true, false, true, false, 'stripe', false, true];
        yield 'cash global off overrides cash-only mixed cart' => [['cod', 'both'], true, false, true, false, 'stripe', false, true];
        yield 'cash global off card priority needs no exception' => [['card', 'cod'], true, false, true, false, 'stripe', false, false];
        yield 'both globally off' => [['both'], false, false, false, false, null, false, false];
        yield 'both globally off cash-only' => [['cod'], false, false, false, false, null, false, false];
        yield 'invalid category despite other card rule' => [['invalid', 'card'], true, true, false, false, null, false, false];
        yield 'invalid category cannot fallback to cash' => [['invalid', 'card'], false, true, false, false, null, false, false];
        yield 'invalid category cannot fallback to card' => [['invalid', 'cod'], true, false, false, false, null, false, false];
    }

    #[DataProvider('combinations')]
    public function testCartAndGlobalPaymentCombinations(array $rules, bool $stripeEnabled, bool $codEnabled, bool $stripeAvailable, bool $codAvailable, ?string $preferred, bool $fallback, bool $fallbackToStripe): void
    {
        $_ENV['STRIPE_ENABLED'] = $stripeEnabled ? 'true' : 'false';
        $_ENV['COD_ENABLED'] = $codEnabled ? 'true' : 'false';
        $items = [];
        foreach ($rules as $rule) {
            [$card, $cod] = match ($rule) {
                'card' => [1, 0],
                'cod' => [0, 1],
                'invalid' => [0, 0],
                default => [1, 1],
            };
            $items[] = $this->product([$this->category($card, $cod)]);
        }
        $result = $this->policy()->resolve($items);
        $this->assertMethods($result, $stripeAvailable, $codAvailable, $preferred);
        self::assertSame($fallback, $result['fallback_to_cod']);
        self::assertSame($fallbackToStripe, $result['fallback_to_stripe']);
        foreach ($result['methods'] as $method) {
            if (!$method['available']) {
                self::assertNotSame('', $method['reason']);
            }
        }
    }

    public function testAllAncestorRulesApplyAndCardWinsWithinOneProduct(): void
    {
        $root = $this->category(1, 0);
        $child = $this->category(1, 1, $root);
        $grandchild = $this->category(0, 1, $child);
        $item = $this->product([$grandchild]);
        $result = $this->policy()->resolve([$item]);
        $this->assertMethods($result, true, false, 'stripe');
        self::assertSame([$item['product_id']], array_column($result['methods']['cod']['blocked_products'], 'id'));

        $this->pdo->prepare('UPDATE categories SET allow_card=1,allow_cod=1 WHERE id=?')->execute([$root]);
        $this->assertMethods($this->policy()->resolve([$item]), false, true, 'cod');
    }

    public function testMultipleCategoryMembershipsAndPrimaryFallbackAreRespected(): void
    {
        $cash = $this->category(0, 1);
        $card = $this->category(1, 0);
        $item = $this->product([$cash, $card]);
        $this->assertMethods($this->policy()->resolve([$item]), true, false, 'stripe');

        $primaryOnly = $this->product([], $card);
        $this->assertMethods($this->policy()->resolve([$primaryOnly]), true, false, 'stripe');
        $inconsistentLegacy = $this->product([$cash], $card);
        $this->assertMethods($this->policy()->resolve([$inconsistentLegacy]), true, false, 'stripe');
    }

    public function testAffectedProductsAreUniqueAndReasonsDistinguishSomeFromAll(): void
    {
        $item = $this->product([$this->category(1, 0)]);
        $secondVariant = $item + ['variant_id' => 22];
        $item['name'] = 'Outdated cart title';
        $both = $this->product();
        $result = $this->policy()->resolve([$item, $secondVariant, $both]);
        self::assertCount(1, $result['methods']['cod']['blocked_products']);
        self::assertSame($secondVariant['name'], $result['methods']['cod']['blocked_products'][0]['name']);
        self::assertSame($item['image_path'], $result['methods']['cod']['blocked_products'][0]['image_path']);
        self::assertStringStartsWith('Unele produse', $result['methods']['cod']['reason']);
        self::assertSame([], $result['methods']['stripe']['blocked_products']);

        $all = $this->policy()->resolve([$item, $secondVariant]);
        self::assertStringStartsWith('Produsele din coș', $all['methods']['cod']['reason']);
        $_ENV['COD_ENABLED'] = 'false';
        $global = $this->policy()->resolve([$item]);
        self::assertStringContainsString('în magazin', $global['methods']['cod']['reason']);
        self::assertSame([], $global['methods']['cod']['blocked_products']);
    }

    public function testMissingStripeKeyUsesCashFallbackAndDoesNotChangeCategoryFlags(): void
    {
        $_ENV['STRIPE_SECRET_KEY'] = '';
        $category = $this->category(1, 0);
        $result = $this->policy()->resolve([$this->product([$category])]);
        $this->assertMethods($result, false, true, 'cod');
        self::assertTrue($result['fallback_to_cod']);
        self::assertSame(0, (int) $this->pdo->query('SELECT allow_cod FROM categories WHERE id=' . $category)->fetchColumn());
    }

    public function testCyclicCategoryTreeFailsClosed(): void
    {
        $root = $this->category();
        $child = $this->category(1, 1, $root);
        $this->pdo->prepare('UPDATE categories SET parent_id=? WHERE id=?')->execute([$child, $root]);
        $this->assertMethods($this->policy()->resolve([$this->product([$child])]), false, false, null);
    }

    public function testCashOnlyFallbackRequiresAConfiguredStripeKeyAndPreservesCategoryFlags(): void
    {
        $_ENV['COD_ENABLED'] = 'false';
        $category = $this->category(0, 1);
        $item = $this->product([$category]);
        $result = $this->policy()->resolve([$item]);
        $this->assertMethods($result, true, false, 'stripe');
        self::assertTrue($result['fallback_to_stripe']);
        self::assertFalse($result['fallback_to_cod']);
        self::assertSame([], $result['methods']['stripe']['blocked_products']);
        self::assertStringContainsString('Plata ramburs este indisponibilă', $result['methods']['stripe']['reason']);
        self::assertSame(0, (int) $this->pdo->query('SELECT allow_card FROM categories WHERE id=' . $category)->fetchColumn());

        $_ENV['STRIPE_SECRET_KEY'] = '';
        $unconfigured = $this->policy()->resolve([$item]);
        $this->assertMethods($unconfigured, false, false, null);
        self::assertFalse($unconfigured['fallback_to_stripe']);
        self::assertFalse($unconfigured['fallback_to_cod']);
    }

    public function testMissingProductAndEmptyCartCannotAcquirePaymentEligibility(): void
    {
        $this->assertMethods($this->policy()->resolve([]), false, false, null);
        $this->assertMethods($this->policy()->resolve([['product_id' => 999999, 'name' => 'Missing']]), false, false, null);
    }

    public function testCheckoutResolutionReadsChangesAfterDisplayAndDoesNotCommitCallerTransaction(): void
    {
        $category = $this->category();
        $item = $this->product([$category]);
        $policy = $this->policy();
        $this->assertMethods($policy->resolve([$item]), true, true, 'stripe');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE categories SET allow_cod=0 WHERE id=?')->execute([$category]);
            $this->assertMethods($policy->resolve([$item], true), true, false, 'stripe');
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
        $this->assertMethods($policy->resolve([$item]), true, true, 'stripe');
    }

    public function testLockingRequiresAnExistingCheckoutTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->policy()->resolve([], true);
    }

    private function policy(): CategoryPaymentPolicy
    {
        return new CategoryPaymentPolicy($this->pdo, Config::load($this->root));
    }

    private function category(int $card = 1, int $cod = 1, ?int $parent = null): int
    {
        $name = 'Payment category ' . ++$this->sequence;
        $statement = $this->pdo->prepare('INSERT INTO categories (name,slug,parent_id,created_at,updated_at) VALUES (?,?,?,?,?)');
        $statement->execute([$name, 'payment-category-' . $this->sequence, $parent, gmdate('c'), gmdate('c')]);
        $id = (int) $this->pdo->lastInsertId();
        if ($card !== 1 || $cod !== 1) {
            $this->pdo->prepare('UPDATE categories SET allow_card=?,allow_cod=? WHERE id=?')->execute([$card, $cod, $id]);
        }
        return $id;
    }

    /** @param list<int> $categoryIds @return array<string,mixed> */
    private function product(array $categoryIds = [], ?int $primary = null): array
    {
        $name = 'Payment product ' . ++$this->sequence;
        $slug = 'payment-product-' . $this->sequence;
        $statement = $this->pdo->prepare('INSERT INTO products (name,slug,primary_category_id,created_at,updated_at) VALUES (?,?,?,?,?)');
        $statement->execute([$name, $slug, $primary, gmdate('c'), gmdate('c')]);
        $id = (int) $this->pdo->lastInsertId();
        foreach ($categoryIds as $categoryId) {
            $this->pdo->prepare('INSERT INTO product_categories (product_id,category_id) VALUES (?,?)')->execute([$id, $categoryId]);
        }
        return ['product_id' => $id, 'name' => $name, 'slug' => $slug, 'image_path' => 'media/payment-test.webp'];
    }

    private function assertMethods(array $result, bool $stripe, bool $cod, ?string $preferred): void
    {
        self::assertSame($stripe, $result['methods']['stripe']['available']);
        self::assertSame($cod, $result['methods']['cod']['available']);
        self::assertSame($preferred, $result['preferred']);
        self::assertSame($stripe || $cod, $result['has_available']);
    }
}
