<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use RuntimeException;

final class ProductReviewService
{
    public function __construct(private readonly ProductReviewRepository $reviews)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $customer
     */
    public function submit(int $productId, array $data, ?array $customer): int
    {
        if (trim((string) ($data['website'] ?? '')) !== '') {
            throw new RuntimeException('Recenzia nu a putut fi trimisă.');
        }

        $name = $this->plainText((string) ($data['author_name'] ?? ''), 100);
        $email = mb_strtolower(trim((string) ($data['author_email'] ?? '')));
        $title = $this->plainText((string) ($data['title'] ?? ''), 140);
        $body = trim(str_replace(["\r\n", "\r"], "\n", strip_tags((string) ($data['body'] ?? ''))));
        $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);

        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Scrie numele care va apărea lângă recenzie.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new RuntimeException('Adresa de email nu este validă.');
        }
        if ($rating === false || $rating < 1 || $rating > 5) {
            throw new RuntimeException('Alege un calificativ între 1 și 5 stele.');
        }
        if ($title !== '' && mb_strlen($title) < 3) {
            throw new RuntimeException('Titlul recenziei trebuie să aibă cel puțin 3 caractere.');
        }
        if (mb_strlen($body) < 20 || mb_strlen($body) > 1500) {
            throw new RuntimeException('Recenzia trebuie să aibă între 20 și 1.500 de caractere.');
        }
        if (empty($data['review_consent'])) {
            throw new RuntimeException('Confirmă acordul pentru publicarea recenziei.');
        }

        $customerId = isset($customer['id']) ? (int) $customer['id'] : null;
        $now = gmdate('c');

        return $this->reviews->create([
            'product_id' => $productId,
            'customer_id' => $customerId,
            'author_name' => $name,
            'author_email' => $email,
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'status' => 'pending',
            'is_verified_purchase' => $this->reviews->hasVerifiedPurchase($productId, $customerId, $email),
            'is_demo' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function approvedForProduct(int $productId): array
    {
        return $this->reviews->approvedForProduct($productId);
    }

    /** @return array{count:int,average:float,demo_count:int,distribution:array<int,int>} */
    public function summaryForProduct(int $productId): array
    {
        return $this->reviews->summaryForProduct($productId);
    }

    /** @return list<array<string, mixed>> */
    public function forModeration(): array
    {
        return $this->reviews->forModeration();
    }

    public function moderate(int $reviewId, string $action, int $moderatorId): void
    {
        $status = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'pending' => 'pending',
            default => throw new RuntimeException('Acțiunea de moderare nu este validă.'),
        };
        $this->reviews->moderate($reviewId, $status, $moderatorId);
    }

    private function plainText(string $value, int $maxLength): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException('Unul dintre câmpuri este prea lung.');
        }
        return $value;
    }
}
