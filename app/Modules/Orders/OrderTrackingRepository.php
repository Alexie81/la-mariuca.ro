<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use PDO;

final class OrderTrackingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|null */
    public function identity(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT id,order_number,email,public_token_hash FROM orders WHERE id=:id');
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function lookup(string $number, string $email): ?int
    {
        $query = $this->pdo->prepare('SELECT id,email FROM orders WHERE order_number=:number LIMIT 1');
        $query->execute(['number' => strtoupper(trim($number))]);
        $row = $query->fetch();
        if (!is_array($row) || !hash_equals(mb_strtolower(trim((string) $row['email'])), mb_strtolower(trim($email)))) {
            return null;
        }
        return (int) $row['id'];
    }

    /** @return list<array<string,mixed>> */
    public function events(int $orderId): array
    {
        $query = $this->pdo->prepare("SELECT action,context_json,created_at FROM audit_log WHERE entity_type='order' AND entity_id=:id ORDER BY id");
        $query->execute(['id' => $orderId]);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $orderId): array
    {
        $query = $this->pdo->prepare('SELECT id,document_number,private_path,created_at FROM order_documents WHERE order_id=:id AND revoked_at IS NULL ORDER BY id DESC');
        $query->execute(['id' => $orderId]);
        return $query->fetchAll();
    }

    public function addInvoice(int $orderId, string $number, string $privatePath): int
    {
        $query = $this->pdo->prepare('INSERT INTO order_documents(order_id,document_number,private_path,created_at) VALUES (:order,:number,:path,:now)');
        $query->execute(['order' => $orderId, 'number' => $number, 'path' => $privatePath, 'now' => gmdate('c')]);
        return (int) $this->pdo->lastInsertId();
    }
}
