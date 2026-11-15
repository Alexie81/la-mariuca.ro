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
                admin_response, responded_at, is_verified_purchase, is_demo, created_at
            FROM product_reviews
            WHERE product_id = :product AND status = 'approved' AND deleted_at IS NULL
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
            WHERE product_id = :product AND status = 'approved' AND deleted_at IS NULL");
        $statement->execute(['product' => $productId]);
        $summary = $statement->fetch() ?: [];

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $distributionStatement = $this->pdo->prepare("SELECT rating, COUNT(*) AS rating_count
            FROM product_reviews
            WHERE product_id = :product AND status = 'approved' AND deleted_at IS NULL
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
            (product_id, customer_id, author_name, author_email, order_number, order_reference, rating, title, body, status,
             is_verified_purchase, is_demo, created_at, updated_at)
            VALUES (:product, :customer, :name, :email, :order_number, :order_reference, :rating, :title, :body, :status,
                    :verified, :demo, :created, :updated)");
        $orderNumber = mb_strtoupper(trim((string) ($review['order_number'] ?? '')));
        $statement->execute([
            'product' => $review['product_id'],
            'customer' => $review['customer_id'],
            'name' => $review['author_name'],
            'email' => $review['author_email'],
            'order_number' => $orderNumber,
            'order_reference' => $orderNumber !== '' ? $orderNumber : null,
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

    public function hasVerifiedPurchase(int $productId, ?int $customerId, string $email, string $orderNumber): bool
    {
        $conditions = ['LOWER(o.email) = LOWER(:email)'];
        $params = ['product' => $productId, 'email' => $email, 'order_number' => $orderNumber];
        if ($customerId !== null) {
            $conditions[] = 'o.user_id = :customer';
            $params['customer'] = $customerId;
        }

        $statement = $this->pdo->prepare("SELECT 1
            FROM orders o
            JOIN order_items item ON item.order_id = o.id
            WHERE item.product_id = :product
              AND o.status = 'delivered'
              AND UPPER(o.order_number) = UPPER(:order_number)
              AND (" . implode(' OR ', $conditions) . ")
            LIMIT 1");
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function orderExistsForProduct(int $productId, ?int $customerId, string $email, string $orderNumber): bool
    {
        $conditions = ['LOWER(o.email) = LOWER(:email)'];
        $params = ['product' => $productId, 'email' => $email, 'order_number' => $orderNumber];
        if ($customerId !== null) {
            $conditions[] = 'o.user_id = :customer';
            $params['customer'] = $customerId;
        }

        $statement = $this->pdo->prepare("SELECT 1
            FROM orders o
            JOIN order_items item ON item.order_id = o.id
            WHERE item.product_id = :product
              AND o.status = 'delivered'
              AND UPPER(o.order_number) = UPPER(:order_number)
              AND (" . implode(' OR ', $conditions) . ")
            LIMIT 1");
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function hasReviewForOrder(int $productId, string $orderNumber): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM product_reviews WHERE product_id=:product AND order_reference=:reference LIMIT 1');
        $statement->execute([
            'product' => $productId,
            'reference' => mb_strtoupper(trim($orderNumber)),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    public function forModeration(): array
    {
        $reviews = $this->pdo->query("SELECT review.*, product.name AS product_name, product.slug AS product_slug,
                moderator.first_name AS moderator_first_name, moderator.last_name AS moderator_last_name
            FROM product_reviews review
            JOIN products product ON product.id = review.product_id
            LEFT JOIN users moderator ON moderator.id = review.moderated_by
            WHERE review.deleted_at IS NULL
            ORDER BY CASE review.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
                review.created_at DESC, review.id DESC")->fetchAll();
        if ($reviews === []) {
            return [];
        }
        $byId = [];
        foreach ($reviews as $index => $review) {
            $reviews[$index]['moderation_history'] = [];
            $byId[(int) $review['id']] = $index;
        }
        $history = $this->pdo->query("SELECT audit.*, actor.first_name AS actor_first_name, actor.last_name AS actor_last_name
            FROM audit_log audit
            LEFT JOIN users actor ON actor.id=audit.actor_user_id
            WHERE audit.entity_type='product_review'
            ORDER BY audit.created_at DESC,audit.id DESC")->fetchAll();
        foreach ($history as $event) {
            $reviewId = (int) ($event['entity_id'] ?? 0);
            if (!isset($byId[$reviewId])) {
                continue;
            }
            $context = json_decode((string) ($event['context_json'] ?? '{}'), true);
            $event['context'] = is_array($context) ? $context : [];
            $reviews[$byId[$reviewId]]['moderation_history'][] = $event;
        }

        return $reviews;
    }

    public function moderate(int $reviewId, string $status, int $moderatorId): void
    {
        $previous = $this->reviewState($reviewId);
        $statement = $this->pdo->prepare("UPDATE product_reviews
            SET status = :status, moderated_at = :moderated, moderated_by = :moderator, updated_at = :updated
            WHERE id = :id AND deleted_at IS NULL");
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
        $this->audit($moderatorId, 'review.' . $status, $reviewId, [
            'previous_status' => (string) ($previous['status'] ?? ''),
            'status' => $status,
        ], $now);
    }

    public function respond(int $reviewId, string $response, int $moderatorId): void
    {
        $previous = $this->reviewState($reviewId);
        if ((string) ($previous['status'] ?? '') !== 'approved') {
            throw new RuntimeException('Răspunsul poate fi publicat numai pentru o recenzie aprobată.');
        }
        $now = gmdate('c');
        $statement = $this->pdo->prepare('UPDATE product_reviews SET admin_response=:response,responded_at=:responded,responded_by=:moderator,updated_at=:updated WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['response' => $response, 'responded' => $now, 'moderator' => $moderatorId, 'updated' => $now, 'id' => $reviewId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Recenzia nu există.');
        }
        $this->audit($moderatorId, 'review.responded', $reviewId, [
            'replaced_existing_response' => trim((string) ($previous['admin_response'] ?? '')) !== '',
            'response_length' => mb_strlen($response),
        ], $now);
    }

    public function remove(int $reviewId, int $moderatorId): void
    {
        $previous = $this->reviewState($reviewId);
        $now = gmdate('c');
        $statement = $this->pdo->prepare("UPDATE product_reviews SET status='rejected',deleted_at=:deleted,deleted_by=:moderator,updated_at=:updated WHERE id=:id AND deleted_at IS NULL");
        $statement->execute(['deleted' => $now, 'moderator' => $moderatorId, 'updated' => $now, 'id' => $reviewId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Recenzia nu există.');
        }
        $this->audit($moderatorId, 'review.removed', $reviewId, [
            'previous_status' => (string) ($previous['status'] ?? ''),
            'retention' => 'soft_delete',
        ], $now);
    }

    /** @return array<string,mixed> */
    private function reviewState(int $reviewId): array
    {
        $statement = $this->pdo->prepare('SELECT status,admin_response,deleted_at FROM product_reviews WHERE id=:id');
        $statement->execute(['id' => $reviewId]);
        $review = $statement->fetch();
        if (!is_array($review) || $review['deleted_at'] !== null) {
            throw new RuntimeException('Recenzia nu există.');
        }
        return $review;
    }

    /** @param array<string,mixed> $context */
    private function audit(int $actorId, string $action, int $reviewId, array $context, string $now): void
    {
        $statement = $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (:actor,:action,'product_review',:review,:context,:created)");
        $statement->execute([
            'actor' => $actorId,
            'action' => $action,
            'review' => $reviewId,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created' => $now,
        ]);
    }

    public function ensureDemoForProductSlug(string $slug): int
    {
        $productStatement = $this->pdo->prepare("SELECT id FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
        $productStatement->execute(['slug' => $slug]);
        $productId = $productStatement->fetchColumn();
        if ($productId === false) {
            throw new RuntimeException('Produsul pentru recenzia demo nu există sau nu este publicat.');
        }

        $existing = $this->pdo->prepare("SELECT id FROM product_reviews
            WHERE product_id = :product
              AND is_demo = 1
              AND author_email = 'recenzie-demo@localhost.invalid'
            LIMIT 1");
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
            'body' => 'Cuburile sunt foarte plăcute la atingere, iar combinația de texturi și culori a atras imediat atenția. Ne plac mult pentru momentele de joacă liniștită. Cel mic revine la ele în fiecare seară și descoperă mereu altă formă, alt material sau un detaliu cusut. Sunt ușoare, simplu de prins și arată minunat în camera lui. Am observat că încearcă să potrivească fețele cu luna, steaua și frunza, apoi le așază unele peste altele și râde când turnul se răstoarnă. Inelele laterale sunt ușor de apucat, iar materialele diferite îl fac să rămână curios mai mult timp decât la alte jucării. Culorile sunt liniștite și se potrivesc foarte bine cu restul camerei, fără să pară prea încărcate. După mai multe zile de joacă, cusăturile arată la fel de îngrijit, iar cuburile și-au păstrat forma. Pentru noi au devenit o alegere foarte reușită atât pentru joaca de zi cu zi, cât și pentru momentele calme de dinainte de somn.',
            'status' => 'approved',
            'is_verified_purchase' => false,
            'is_demo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<int> */
    public function ensureDemoPackForProductSlug(string $slug): array
    {
        $productStatement = $this->pdo->prepare("SELECT id FROM products WHERE slug = :slug AND status = 'published' LIMIT 1");
        $productStatement->execute(['slug' => $slug]);
        $productId = $productStatement->fetchColumn();
        if ($productId === false) {
            throw new RuntimeException('Produsul pentru recenziile demo nu există sau nu este publicat.');
        }

        $reviewIds = [$this->ensureDemoForProductSlug($slug)];
        $reviews = [
            ['Andreea P.', 5, 'Culori liniștite și texturi plăcute', 'Culorile sunt foarte delicate, iar fiecare cub are o textură diferită. Cel mic le explorează cu multă curiozitate.'],
            ['Elena D.', 5, 'Un cadou tare reușit', 'Le-am ales pentru un cadou și au fost foarte apreciate. Arată frumos împreună și sunt moi, potrivite pentru joacă liniștită.'],
            ['Mara S.', 4, 'Frumoase și ușor de prins', 'Cuburile sunt ușoare și potrivite pentru mânuțe mici. Mi-ar fi plăcut o cutie de depozitare, însă produsul este foarte frumos.'],
            ['Raluca T.', 5, 'Preferatele noastre pentru joaca de seară', 'Texturile și formele cusute îi țin atenția, iar nuanțele se potrivesc foarte bine în camera copilului. Suntem încântați.'],
            ['Cristina A.', 5, 'Detalii foarte atent lucrate', 'Cusăturile sunt îngrijite, materialele sunt plăcute la atingere și combinația dintre culori este exact ca în fotografii.'],
            ['Alina C.', 4, 'Un set delicat și interesant', 'Setul este plăcut și oferă multe elemente de explorat. Inelele laterale sunt cele care au atras primele atenția.'],
            ['Denisa R.', 5, 'Joacă senzorială reușită', 'Fiecare cub aduce altă senzație la atingere și alt detaliu de descoperit. Este un set potrivit pentru momente calme de joacă.'],
            ['Bianca N.', 5, 'Exact ca în imagini', 'Nuanțele sunt calde, materialele sunt moi, iar dimensiunea cuburilor este potrivită. Produsul a ajuns să fie folosit zilnic.'],
            ['Sorina V.', 5, 'Foarte plăcute la atingere', 'Ne place varietatea materialelor și faptul că piesele sunt ușoare. Cubul cu luna a devenit imediat preferatul nostru.'],
            ['Maria G.', 5, 'O alegere inspirată pentru cei mici', 'Un set vesel, delicat și bine finisat. Formele aplicate și culorile îl fac interesant fără să fie prea încărcat vizual.'],
        ];

        $findExisting = $this->pdo->prepare('SELECT id FROM product_reviews WHERE product_id = :product AND author_email = :email LIMIT 1');
        foreach ($reviews as $index => [$author, $rating, $title, $body]) {
            $email = sprintf('recenzie-demo-%02d@localhost.invalid', $index + 2);
            $findExisting->execute(['product' => $productId, 'email' => $email]);
            $existingId = $findExisting->fetchColumn();
            if ($existingId !== false) {
                $reviewIds[] = (int) $existingId;
                continue;
            }

            $createdAt = gmdate('c', time() - (($index + 1) * 86400));
            $reviewIds[] = $this->create([
                'product_id' => (int) $productId,
                'customer_id' => null,
                'author_name' => $author,
                'author_email' => $email,
                'rating' => $rating,
                'title' => $title,
                'body' => $body,
                'status' => 'approved',
                'is_verified_purchase' => false,
                'is_demo' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $reviewIds;
    }
}
