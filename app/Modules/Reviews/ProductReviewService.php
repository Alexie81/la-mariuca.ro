<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use App\Modules\Admin\AdminPushService;
use RuntimeException;

final class ProductReviewService
{
    public function __construct(private readonly ProductReviewRepository $reviews, private readonly ?AdminPushService $push = null)
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
        $orderNumber = mb_strtoupper($this->plainText((string) ($data['order_number'] ?? ''), 40));
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
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{3,39}$/', $orderNumber)) {
            throw new ProductReviewValidationException('order_number', 'Completează un număr de comandă valid.');
        }
        $customerId = isset($customer['id']) ? (int) $customer['id'] : null;
        if (!$this->reviews->orderExistsForProduct($productId, $customerId, $email, $orderNumber)) {
            throw new ProductReviewValidationException('order_number', 'Comanda nu există, nu conține produsul sau nu este încă livrată.');
        }
        if ($this->reviews->hasReviewForOrder($productId, $orderNumber)) {
            throw new ProductReviewValidationException('order_number', 'Pentru acest produs a fost deja trimisă o recenzie din comanda indicată.');
        }
        if (mb_strlen($body) < 20 || mb_strlen($body) > 1500) {
            throw new RuntimeException('Recenzia trebuie să aibă între 20 și 1.500 de caractere.');
        }
        if (empty($data['review_consent'])) {
            throw new RuntimeException('Confirmă acordul pentru publicarea recenziei.');
        }

        $now = gmdate('c');

        $reviewId = $this->reviews->create([
            'product_id' => $productId,
            'customer_id' => $customerId,
            'author_name' => $name,
            'author_email' => $email,
            'order_number' => $orderNumber,
            'rating' => $rating,
            'title' => '',
            'body' => $body,
            'status' => 'pending',
            'is_verified_purchase' => $this->reviews->hasVerifiedPurchase($productId, $customerId, $email, $orderNumber),
            'is_demo' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        try {
            $this->push?->queueReview($reviewId);
            $this->push?->processPending(10);
        } catch (\Throwable) {
            // O indisponibilitate Web Push nu trebuie să blocheze recenzia clientului.
        }
        return $reviewId;
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

    /** @param array<string,mixed> $input @return array<string,int|string> */
    public function moderationFilters(array $input): array
    {
        $value=static fn(string $key):string=>is_scalar($input[$key] ?? null)?trim((string)$input[$key]):'';
        return ['q'=>mb_substr($value('q'),0,120),
            'stare'=>in_array($value('stare'),['pending','approved','rejected'],true)?$value('stare'):'',
            'nota'=>in_array($value('nota'),['1','2','3','4','5'],true)?$value('nota'):'',
            'raspuns'=>in_array($value('raspuns'),['yes','no'],true)?$value('raspuns'):'',
            'sortare'=>in_array($value('sortare'),['priority','newest','oldest','rating'],true)?$value('sortare'):'priority',
            'per_page'=>in_array($value('per_page'),['10','20','50'],true)?(int)$value('per_page'):10,
            'pagina'=>max(1,min(1000000,(int)$value('pagina')))];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function moderationWorkspace(array $input): array
    {
        $filters=$this->moderationFilters($input); $count=$this->reviews->moderationCount($filters);
        $pages=max(1,(int)ceil($count/(int)$filters['per_page'])); $page=min((int)$filters['pagina'],$pages); $filters['pagina']=$page;
        $offset=($page-1)*(int)$filters['per_page'];
        return ['reviews'=>$this->reviews->moderationPage($filters,(int)$filters['per_page'],$offset),
            'summary'=>$this->reviews->moderationSummary(),'filters'=>$filters,'total'=>$count,'pages'=>$pages,'page'=>$page,
            'page_numbers'=>range(max(1,$page-2),min($pages,$page+2)),
            'from'=>$count>0?$offset+1:0,'to'=>min($count,$offset+(int)$filters['per_page'])];
    }

    /** @return array<string,mixed>|null */
    public function moderationDetail(int $id): ?array
    {
        return $this->reviews->moderationDetail($id);
    }

    public function moderate(int $reviewId, string $action, int $moderatorId, string $response = ''): void
    {
        if ($moderatorId < 1) {
            throw new RuntimeException('Moderatorul nu este valid.');
        }
        if ($action === 'respond') {
            $response = $this->plainText($response, 1500);
            if (mb_strlen($response) < 2) {
                throw new RuntimeException('Scrie răspunsul care va fi afișat public.');
            }
            $this->reviews->respond($reviewId, $response, $moderatorId);
            return;
        }
        if ($action === 'remove') {
            $this->reviews->remove($reviewId, $moderatorId);
            return;
        }
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
