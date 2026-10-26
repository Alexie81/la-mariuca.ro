<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use PDO;

final class CustomerAccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function profile(int $userId): ?array
    {
        $statement = $this->pdo->prepare("SELECT u.id,u.email,u.first_name,u.last_name,u.phone,u.email_verified_at,u.created_at,
            EXISTS(SELECT 1 FROM oauth_identities oi WHERE oi.user_id=u.id AND oi.provider='google') AS uses_google
            FROM users u WHERE u.id=:id AND u.role='customer' AND u.status='active' LIMIT 1");
        $statement->execute(['id' => $userId]);
        $profile = $statement->fetch();

        return is_array($profile) ? $profile : null;
    }

    public function claimVerifiedOrders(int $userId, string $email): void
    {
        $statement = $this->pdo->prepare('UPDATE orders SET user_id=:user_id,updated_at=:now WHERE user_id IS NULL AND LOWER(email)=:email');
        $statement->execute(['user_id' => $userId, 'now' => gmdate('c'), 'email' => mb_strtolower(trim($email))]);
    }

    /** @return list<array<string, mixed>> */
    public function orders(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id,order_number,total_bani,subtotal_bani,discount_bani,shipping_bani,status,payment_status,payment_method,shipping_address_json,awb,created_at FROM orders WHERE user_id=:id ORDER BY id DESC LIMIT 50');
        $statement->execute(['id' => $userId]);
        $orders = $statement->fetchAll();

        foreach ($orders as &$order) {
            $items = $this->pdo->prepare('SELECT product_name,variant_summary,sku,quantity,unit_price_bani,line_total_bani FROM order_items WHERE order_id=:order_id ORDER BY id');
            $items->execute(['order_id' => $order['id']]);
            $order['items'] = $items->fetchAll();
            $order['shipping_address'] = $this->decodeJson((string) $order['shipping_address_json']);
        }
        unset($order);

        return $orders;
    }

    /** @return list<array<string, mixed>> */
    public function addresses(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id,label,address_json,is_default,created_at,updated_at FROM addresses WHERE user_id=:user_id ORDER BY is_default DESC,id DESC');
        $statement->execute(['user_id' => $userId]);
        $addresses = $statement->fetchAll();
        foreach ($addresses as &$address) {
            $address['details'] = $this->decodeJson((string) $address['address_json']);
        }
        unset($address);

        return $addresses;
    }

    /** @return array<string, mixed>|null */
    public function address(int $userId, int $addressId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,label,address_json,is_default FROM addresses WHERE id=:id AND user_id=:user_id LIMIT 1');
        $statement->execute(['id' => $addressId, 'user_id' => $userId]);
        $address = $statement->fetch();
        if (!is_array($address)) {
            return null;
        }
        $address['details'] = $this->decodeJson((string) $address['address_json']);

        return $address;
    }

    /** @param array<string, string> $details */
    public function saveAddress(int $userId, ?int $addressId, string $label, array $details, bool $isDefault): int
    {
        $now = gmdate('c');
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->pdo->beginTransaction();
        try {
            if ($isDefault) {
                $this->pdo->prepare('UPDATE addresses SET is_default=0,updated_at=:now WHERE user_id=:user_id')->execute(['now' => $now, 'user_id' => $userId]);
            }
            if ($addressId !== null) {
                $statement = $this->pdo->prepare('UPDATE addresses SET label=:label,address_json=:address_json,is_default=:is_default,updated_at=:now WHERE id=:id AND user_id=:user_id');
                $statement->execute(['label' => $label, 'address_json' => $json, 'is_default' => $isDefault ? 1 : 0, 'now' => $now, 'id' => $addressId, 'user_id' => $userId]);
                if ($statement->rowCount() !== 1 && $this->address($userId, $addressId) === null) {
                    throw new \RuntimeException('Adresa nu a putut fi actualizată.');
                }
                $savedId = $addressId;
            } else {
                $hasAddresses = $this->pdo->prepare('SELECT COUNT(*) FROM addresses WHERE user_id=:user_id');
                $hasAddresses->execute(['user_id' => $userId]);
                $makeDefault = $isDefault || (int) $hasAddresses->fetchColumn() === 0;
                $statement = $this->pdo->prepare('INSERT INTO addresses (user_id,label,address_json,is_default,created_at,updated_at) VALUES (:user_id,:label,:address_json,:is_default,:now,:now)');
                $statement->execute(['user_id' => $userId, 'label' => $label, 'address_json' => $json, 'is_default' => $makeDefault ? 1 : 0, 'now' => $now]);
                $savedId = (int) $this->pdo->lastInsertId();
            }
            $this->ensureDefaultAddress($userId, $now);
            $this->pdo->commit();

            return $savedId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteAddress(int $userId, int $addressId): bool
    {
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM addresses WHERE id=:id AND user_id=:user_id');
            $statement->execute(['id' => $addressId, 'user_id' => $userId]);
            $deleted = $statement->rowCount() === 1;
            if ($deleted) {
                $this->ensureDefaultAddress($userId, $now);
            }
            $this->pdo->commit();

            return $deleted;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function setDefaultAddress(int $userId, int $addressId): bool
    {
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $owned = $this->pdo->prepare('SELECT 1 FROM addresses WHERE id=:id AND user_id=:user_id');
            $owned->execute(['id' => $addressId, 'user_id' => $userId]);
            if ($owned->fetchColumn() === false) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->prepare('UPDATE addresses SET is_default=0,updated_at=:now WHERE user_id=:user_id')->execute(['now' => $now, 'user_id' => $userId]);
            $this->pdo->prepare('UPDATE addresses SET is_default=1,updated_at=:now WHERE id=:id AND user_id=:user_id')->execute(['now' => $now, 'id' => $addressId, 'user_id' => $userId]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateProfile(int $userId, string $firstName, string $lastName, string $phone): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET first_name=:first_name,last_name=:last_name,phone=:phone,updated_at=:now WHERE id=:id AND role=\'customer\' AND status=\'active\'');
        $statement->execute(['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'now' => gmdate('c'), 'id' => $userId]);
        if ($statement->rowCount() !== 1) {
            $existing = $this->profile($userId);
            if ($existing === null) {
                throw new \RuntimeException('Datele contului nu au putut fi actualizate.');
            }
        }
    }

    private function ensureDefaultAddress(int $userId, string $now): void
    {
        $default = $this->pdo->prepare('SELECT id FROM addresses WHERE user_id=:user_id AND is_default=1 LIMIT 1');
        $default->execute(['user_id' => $userId]);
        if ($default->fetchColumn() !== false) {
            return;
        }
        $fallback = $this->pdo->prepare('SELECT id FROM addresses WHERE user_id=:user_id ORDER BY id DESC LIMIT 1');
        $fallback->execute(['user_id' => $userId]);
        $fallbackId = $fallback->fetchColumn();
        if ($fallbackId !== false) {
            $this->pdo->prepare('UPDATE addresses SET is_default=1,updated_at=:now WHERE id=:id AND user_id=:user_id')->execute(['now' => $now, 'id' => (int) $fallbackId, 'user_id' => $userId]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
