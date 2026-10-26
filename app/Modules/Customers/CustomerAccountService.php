<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Shared\Support\RomanianLocations;
use RuntimeException;

final class CustomerAccountService
{
    private const ORDER_STATUS = [
        'new' => 'Comandă primită',
        'confirmed' => 'Confirmată',
        'preparing' => 'În pregătire',
        'shipped' => 'Expediată',
        'delivered' => 'Livrată',
        'cancelled' => 'Anulată',
        'returned' => 'Returnată',
        'manual_review' => 'În verificare',
    ];
    private const PAYMENT_STATUS = [
        'pending' => 'În așteptare',
        'paid' => 'Plătită',
        'failed' => 'Eșuată',
        'partially_refunded' => 'Rambursată parțial',
        'refunded' => 'Rambursată',
    ];

    private readonly RomanianLocations $locations;

    public function __construct(private readonly CustomerAccountRepository $repository, ?RomanianLocations $locations = null)
    {
        $this->locations = $locations ?? RomanianLocations::fromProjectRoot();
    }

    /** @return array<string, mixed> */
    public function dashboard(int $userId): array
    {
        $profile = $this->repository->profile($userId);
        if ($profile === null) {
            throw new RuntimeException('Contul nu mai este disponibil.');
        }
        if (($profile['email_verified_at'] ?? null) !== null) {
            $this->repository->claimVerifiedOrders($userId, (string) $profile['email']);
        }
        $orders = $this->repository->orders($userId);
        foreach ($orders as &$order) {
            $order['status_label'] = self::ORDER_STATUS[(string) $order['status']] ?? 'În procesare';
            $order['payment_label'] = self::PAYMENT_STATUS[(string) $order['payment_status']] ?? 'În verificare';
            $order['payment_method_label'] = $order['payment_method'] === 'stripe' ? 'Card' : 'Ramburs';
        }
        unset($order);
        $addresses = $this->repository->addresses($userId);

        return [
            'account' => $profile,
            'orders' => $orders,
            'addresses' => $addresses,
            'stats' => [
                'orders' => count($orders),
                'active_orders' => count(array_filter($orders, static fn(array $order): bool => in_array($order['status'], ['new', 'confirmed', 'preparing', 'shipped', 'manual_review'], true))),
                'addresses' => count($addresses),
            ],
        ];
    }

    /** @return array{profile: array<string, mixed>, addresses: list<array<string, mixed>>, default_address: array<string, mixed>|null} */
    public function checkoutDefaults(int $userId): array
    {
        $profile = $this->repository->profile($userId);
        if ($profile === null) {
            throw new RuntimeException('Contul nu mai este disponibil.');
        }
        $addresses = $this->repository->addresses($userId);
        $defaultAddress = null;
        foreach ($addresses as $address) {
            if ((int) $address['is_default'] === 1) {
                $defaultAddress = $address;
                break;
            }
        }

        return ['profile' => $profile, 'addresses' => $addresses, 'default_address' => $defaultAddress];
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $userId, array $data): void
    {
        $firstName = $this->clean((string) ($data['first_name'] ?? ''), 120);
        $lastName = $this->clean((string) ($data['last_name'] ?? ''), 120);
        $phone = $this->normalizeRomanianPhone((string) ($data['phone'] ?? ''));
        if ($firstName === '' || $lastName === '' || $phone === null) {
            throw new RuntimeException('Completează numele și un număr de telefon românesc valid.');
        }
        $this->repository->updateProfile($userId, $firstName, $lastName, $phone);
    }

    /** @param array<string, mixed> $data */
    public function saveAddress(int $userId, array $data): int
    {
        $addressId = filter_var($data['address_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($addressId !== false && $this->repository->address($userId, (int) $addressId) === null) {
            throw new RuntimeException('Adresa selectată nu a fost găsită.');
        }
        $label = $this->clean((string) ($data['label'] ?? ''), 60);
        $firstName = $this->clean((string) ($data['first_name'] ?? ''), 120);
        $lastName = $this->clean((string) ($data['last_name'] ?? ''), 120);
        $phone = $this->normalizeRomanianPhone((string) ($data['phone'] ?? ''));
        $street = $this->clean((string) ($data['address'] ?? ''), 220);
        $city = $this->clean((string) ($data['city'] ?? ''), 120);
        $county = $this->clean((string) ($data['county'] ?? ''), 120);
        $postalCode = preg_replace('/\s+/', '', trim((string) ($data['postal_code'] ?? ''))) ?? '';
        $notes = $this->clean((string) ($data['notes'] ?? ''), 250);
        if ($label === '' || $firstName === '' || $lastName === '' || $phone === null || $street === '' || preg_match('/^[0-9]{6}$/', $postalCode) !== 1) {
            throw new RuntimeException('Completează toate câmpurile adresei și un cod poștal românesc din 6 cifre.');
        }
        $officialLocation = $this->locations->canonicalize($county, $city);
        if ($officialLocation === null) {
            throw new RuntimeException('Alege județul și localitatea din nomenclatorul oficial disponibil în formular.');
        }
        $county = $officialLocation['county'];
        $city = $officialLocation['locality'];
        if (count($this->repository->addresses($userId)) >= 10 && $addressId === false) {
            throw new RuntimeException('Poți salva maximum 10 adrese în cont.');
        }

        return $this->repository->saveAddress($userId, $addressId === false ? null : (int) $addressId, $label, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'address' => $street,
            'city' => $city,
            'county' => $county,
            'postal_code' => $postalCode,
            'notes' => $notes,
        ], isset($data['is_default']));
    }

    public function deleteAddress(int $userId, int $addressId): void
    {
        if ($addressId < 1 || !$this->repository->deleteAddress($userId, $addressId)) {
            throw new RuntimeException('Adresa nu a fost găsită.');
        }
    }

    public function setDefaultAddress(int $userId, int $addressId): void
    {
        if ($addressId < 1 || !$this->repository->setDefaultAddress($userId, $addressId)) {
            throw new RuntimeException('Adresa nu a fost găsită.');
        }
    }

    private function clean(string $value, int $maxLength): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $maxLength);
    }

    private function normalizeRomanianPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '0040')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^0[2-8][0-9]{8}$/', $digits) === 1) {
            $digits = '40' . substr($digits, 1);
        }
        if (preg_match('/^40[2-8][0-9]{8}$/', $digits) !== 1) {
            return null;
        }

        return '+' . $digits;
    }
}
