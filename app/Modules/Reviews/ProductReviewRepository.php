<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use PDO;
use RuntimeException;

final class ProductReviewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function approvedForProduct(int $productId): array
    {
        $statement = $this->pdo->prepare("SELECT id, author_name, rating, title, body,
                is_verified_purchase, is_demo, created_at
            FROM product_reviews
            WHERE product_id = :product AND status = 'approved'
            ORDER BY is_demo ASC, created_at DESC, id DESC");
        $statement->execute(['product' => $productId]);

        return $statement->fetchAll();
    }

    /** @return array{count:int,average:float,demo_count:int,distribution:array<int,int>} */
    public function summaryForProduct(int $productId): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS review_count,
                COALESCE(AVG(rating), 0) AS average_rating,
                COALESCE(SUM(CASE WHEN is_demo = 1 THEN 1 ELSE 0 END), 0) AS demo_count
            FROM product_reviews
            WHERE product_id = :product AND status = 'approved'");
        $statement->execute(['product' => $productId]);
        $summary = $statement->fetch() ?: [];

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $distributionStatement = $this->pdo->prepare("SELECT rating, COUNT(*) AS rating_count
            FROM product_reviews
            WHERE product_id = :product AND status = 'approved'
            GROUP BY rating");
        $distributionStatement->execute(['product' => $productId]);
        foreach ($distributionStatement->fetchAll() as $row) {
            $distribution[(int) $row['rating']] = (int) $row['rating_count'];
        }

        return [
            'count' => (int) ($summary['review_count'] ?? 0),
            'average' => round((float) ($summary['average_rating'] ?? 0), 1),
            'demo_count' => (int) ($summary['demo_count'] ?? 0),
            'distribution' => $distribution,
        ];
    }

    /** @param array<string, mixed> $review */
    public function create(array $review): int
    {
        $statement = $this->pdo->prepare("INSERT INTO product_reviews
            (product_id, customer_id, author_name, author_email, rating, title, body, status,
             is_verified_purchase, is_demo, created_at, updated_at)
            VALUES (:product, :customer, :name, :email, :rating, :title, :body, :status,
                    :verified, :demo, :created, :updated)");
        $statement->execute([
            'product' => $review['product_id'],
            'customer' => $review['customer_id'],
            'name' => $review['author_name'],
            'email' => $review['author_email'],
            'rating' => $review['rating'],
            'title' => $review['title'],
            'body' => $review['body'],
            'status' => $review['status'] ?? 'pending',
            'verified' => !empty($review['is_verified_purchase']) ? 1 : 0,
            'demo' => !empty($review['is_demo']) ? 1 : 0,
            'created' => $review['created_at'],
            'updated' => $review['updated_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasVerifiedPurchase(int $productId, ?int $customerId, string $email): bool
    {
        $conditions = ['LOWER(o.email) = LOWER(:email)'];
        $params = ['product' => $productId, 'email' => $email];
        if ($customerId !== null) {
            $conditions[] = 'o.user_id = :customer';
            $params['customer'] = $customerId;
        }

        $statement = $this->pdo->prepare("SELECT 1
            FROM orders o
            JOIN order_items item ON item.order_id = o.id
            WHERE item.product_id = :product
              AND o.status = 'delivered'
              AND (" . implode(' OR ', $conditions) . ")
            LIMIT 1");
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    public function forModeration(): array
    {
        return $this->pdo->query("SELECT review.*, product.name AS product_name, product.slug AS product_slug,
                moderator.first_name AS moderator_first_name, moderator.last_name AS moderator_last_name
            FROM product_reviews review
            JOIN products product ON product.id = review.product_id
            LEFT JOIN users moderator ON moderator.id = review.moderated_by
            ORDER BY CASE review.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
                review.created_at DESC, review.id DESC")->fetchAll();
    }

    public function moderate(int $reviewId, string $status, int $moderatorId): void
    {
        $statement = $this->pdo->prepare("UPDATE product_reviews
            SET status = :status, moderated_at = :moderated, moderated_by = :moderator, updated_at = :updated
            WHERE id = :id");
        $now = gmdate('c');
        $statement->execute([
            'status' => $status,
            'moderated' => $now,
            'moderator' => $moderatorId,
            'updated' => $now,
            'id' => $reviewId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Recenzia nu există.');
        }
    }

    public function ensureDemoForProductSlug(string $slug): int
    {
        $productStatement = $this->pdo->prepare("SELECT id FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
        $productStatement->execute(['slug' => $slug]);
        $productId = $productStatement->fetchColumn();
        if ($productId === false) {
            throw new RuntimeException('Produsul pentru recenzia demo nu există sau nu este publicat.');
        }

        $existing = $this->pdo->prepare('SELECT id FROM product_reviews WHERE product_id = :product AND is_demo = 1 LIMIT 1');
        $existing->execute(['product' => $productId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $now = gmdate('c');
        return $this->create([
            'product_id' => (int) $productId,
            'customer_id' => null,
            'author_name' => 'Ioana M.',
            'author_email' => 'recenzie-demo@localhost.invalid',
            'rating' => 5,
            'title' => 'Joacă liniștită și plină de descoperiri',
            'body' => 'Cuburile sunt foarte plăcute la atingere, iar combinația de texturi și culori a atras imediat atenția. Ne plac mult pentru momentele de joacă liniștită.',
            'status' => 'approved',
            'is_verified_purchase' => false,
            'is_demo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
