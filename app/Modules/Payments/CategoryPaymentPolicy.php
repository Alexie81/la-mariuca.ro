<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;
use LogicException;
use PDO;

/** Resolves category restrictions from the current catalog, including every ancestor. */
final class CategoryPaymentPolicy
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /**
     * @param list<array<string,mixed>> $items Authoritative cart lines, not browser-submitted products.
     * @return array{methods:array<string,array{available:bool,reason:string,blocked_products:list<array{id:int,name:string,slug:string,image_path:?string}>}>,preferred:?string,fallback_to_cod:bool,has_available:bool}
     */
    public function resolve(array $items, bool $lock = false): array
    {
        if ($lock && !$this->pdo->inTransaction()) {
            throw new LogicException('Payment policy locking requires the checkout transaction.');
        }
        $suffix = $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $cartProducts = [];
        foreach ($items as $item) {
            $cartProducts[(int) ($item['product_id'] ?? 0)] ??= $item;
        }
        ksort($cartProducts, SORT_NUMERIC);
        $productIds = array_keys($cartProducts);
        $products = [];
        $memberships = [];
        if ($productIds !== []) {
            foreach ($this->rowsForIds('SELECT id,name,slug,primary_category_id FROM products WHERE id IN (%s) ORDER BY id' . $suffix, $productIds) as $product) {
                $id = (int) $product['id'];
                $products[$id] = $product;
                // Include the primary category even if an older record has no join-table entry.
                if ((int) ($product['primary_category_id'] ?? 0) > 0) {
                    $memberships[$id][(int) $product['primary_category_id']] = true;
                }
            }
            foreach ($this->rowsForIds('SELECT product_id,category_id FROM product_categories WHERE product_id IN (%s) ORDER BY product_id,category_id' . $suffix, $productIds) as $membership) {
                $memberships[(int) $membership['product_id']][(int) $membership['category_id']] = true;
            }
        }

        $directIds = [];
        foreach ($memberships as $ids) {
            foreach (array_keys($ids) as $id) {
                $directIds[$id] = true;
            }
        }
        $categories = $this->categories(array_keys($directIds), $suffix);
        $cardRequired = [];
        $codRequired = [];
        $invalid = [];
        foreach ($cartProducts as $productId => $item) {
            $product = $products[$productId] ?? null;
            $display = [
                'id' => $productId,
                'name' => (string) ($product['name'] ?? $item['name'] ?? 'Produs indisponibil'),
                'slug' => (string) ($product['slug'] ?? ''),
                'image_path' => isset($item['image_path']) ? (string) $item['image_path'] : null,
            ];
            $rule = $product === null ? 'invalid' : $this->productRule(array_keys($memberships[$productId] ?? []), $categories);
            if ($rule === 'invalid') {
                $invalid[] = $display;
            } elseif ($rule === 'card') {
                $cardRequired[] = $display;
            } elseif ($rule === 'cod') {
                $codRequired[] = $display;
            }
        }

        $stripeGlobal = $this->config->bool('STRIPE_ENABLED') && trim($this->config->get('STRIPE_SECRET_KEY')) !== '';
        $codGlobal = $this->config->bool('COD_ENABLED', true);
        $methods = [
            'stripe' => ['available' => false, 'reason' => '', 'blocked_products' => []],
            'cod' => ['available' => false, 'reason' => '', 'blocked_products' => []],
        ];
        if ($cartProducts === [] || $invalid !== []) {
            $reason = $cartProducts === []
                ? 'Adaugă produse în coș pentru a alege metoda de plată.'
                : 'Metodele de plată ale unor produse nu sunt disponibile momentan. Contactează-ne pentru ajutor.';
            foreach ($methods as &$method) {
                $method['reason'] = $reason;
                $method['blocked_products'] = $invalid;
            }
            unset($method);
            return ['methods' => $methods, 'preferred' => null, 'fallback_to_cod' => false, 'has_available' => false];
        }

        // Card-only rules win over cash-only rules, both within a product and across the cart.
        $requiresCard = $cardRequired !== [];
        $requiresCod = !$requiresCard && $codRequired !== [];
        $fallback = !$stripeGlobal && $codGlobal && $requiresCard;
        $methods['stripe']['available'] = $stripeGlobal && !$requiresCod;
        $methods['cod']['available'] = $codGlobal && (!$requiresCard || !$stripeGlobal);

        if (!$stripeGlobal) {
            $methods['stripe']['reason'] = 'Plata online cu cardul nu este disponibilă momentan în magazin.';
        } elseif ($requiresCod) {
            $methods['stripe']['reason'] = $this->restrictionReason(count($codRequired), count($cartProducts), 'cu cardul');
            $methods['stripe']['blocked_products'] = $codRequired;
        }
        if (!$codGlobal) {
            $methods['cod']['reason'] = 'Plata ramburs nu este disponibilă momentan în magazin.';
        } elseif ($requiresCard && $stripeGlobal) {
            $methods['cod']['reason'] = $this->restrictionReason(count($cardRequired), count($cartProducts), 'ramburs');
            $methods['cod']['blocked_products'] = $cardRequired;
        } elseif ($fallback) {
            $methods['cod']['reason'] = 'Plata cu cardul este indisponibilă momentan. Poți finaliza comanda cu plata ramburs.';
        }

        $preferred = $methods['stripe']['available'] ? 'stripe' : ($methods['cod']['available'] ? 'cod' : null);
        return ['methods' => $methods, 'preferred' => $preferred, 'fallback_to_cod' => $fallback, 'has_available' => $preferred !== null];
    }

    /** @param list<int> $categoryIds @param array<int,array<string,mixed>> $categories */
    private function productRule(array $categoryIds, array $categories): string
    {
        $hasCardOnly = false;
        $hasCodOnly = false;
        $checked = [];
        foreach ($categoryIds as $categoryId) {
            $path = [];
            while ($categoryId > 0) {
                if (isset($path[$categoryId]) || !isset($categories[$categoryId])) {
                    // Corrupt ancestry must never silently remove a payment restriction.
                    return 'invalid';
                }
                if (isset($checked[$categoryId])) {
                    break;
                }
                $path[$categoryId] = true;
                $category = $categories[$categoryId];
                $card = (int) $category['allow_card'] === 1;
                $cod = (int) $category['allow_cod'] === 1;
                if (!$card && !$cod) {
                    return 'invalid';
                }
                $hasCardOnly = $hasCardOnly || ($card && !$cod);
                $hasCodOnly = $hasCodOnly || (!$card && $cod);
                $categoryId = (int) ($category['parent_id'] ?? 0);
            }
            $checked += $path;
        }
        return $hasCardOnly ? 'card' : ($hasCodOnly ? 'cod' : 'both');
    }

    /**
     * Plan ancestor IDs once, then lock only relevant rows in ID order. Locking reads
     * refresh the rows under MySQL REPEATABLE READ; reparented ancestors are followed
     * again from those fresh rows, without relying on an earlier catalog snapshot.
     *
     * @param list<int> $directIds
     * @return array<int,array<string,mixed>>
     */
    private function categories(array $directIds, string $lockSuffix): array
    {
        if ($directIds === []) {
            return [];
        }
        $snapshot = [];
        foreach ($this->pdo->query('SELECT id,parent_id,allow_card,allow_cod FROM categories ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $snapshot[(int) $category['id']] = $category;
        }
        if ($lockSuffix === '') {
            return $snapshot;
        }

        $planned = [];
        foreach ($directIds as $id) {
            while ($id > 0 && !isset($planned[$id])) {
                $planned[$id] = true;
                $id = (int) ($snapshot[$id]['parent_id'] ?? 0);
            }
        }
        $pending = array_keys($planned);
        $read = [];
        $locked = [];
        while ($pending !== []) {
            sort($pending, SORT_NUMERIC);
            foreach ($pending as $id) {
                $read[$id] = true;
            }
            $rows = $this->rowsForIds('SELECT id,parent_id,allow_card,allow_cod FROM categories WHERE id IN (%s) ORDER BY id' . $lockSuffix, $pending);
            $pending = [];
            foreach ($rows as $category) {
                $locked[(int) $category['id']] = $category;
                $parentId = (int) ($category['parent_id'] ?? 0);
                if ($parentId > 0 && !isset($read[$parentId])) {
                    $pending[$parentId] = $parentId;
                }
            }
            $pending = array_values($pending);
        }
        return $locked;
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function rowsForIds(string $sql, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(sprintf($sql, $placeholders));
        $statement->execute($ids);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function restrictionReason(int $affected, int $total, string $method): string
    {
        return ($affected === $total ? 'Produsele din coș nu acceptă plata ' : 'Unele produse din coș nu acceptă plata ') . $method . '.';
    }
}
