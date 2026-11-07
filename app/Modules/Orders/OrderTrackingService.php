<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;
use RuntimeException;

final class OrderTrackingService
{
    public function __construct(private readonly OrderTrackingRepository $repository, private readonly OrderService $orders, private readonly Config $config, private readonly string $root) {}

    public function lookup(string $number, string $email): ?int
    {
        if (strlen($number) > 100 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $this->repository->lookup($number, $email);
    }

    /** Link is bound to the order, recipient and its existing revocable public token. */
    public function emailUrl(int $orderId, string $recipient): string
    {
        $order = $this->repository->identity($orderId);
        if ($order === null || mb_strtolower(trim($recipient)) !== mb_strtolower(trim((string) $order['email']))) {
            throw new RuntimeException('Destinatarul nu corespunde comenzii.');
        }
        return $this->config->rootUrl() . '/urmarire-comanda/acces/' . $orderId . '/' . $this->signature($order) . '/';
    }

    public function verifyLink(int $orderId, string $signature): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1) {
            return false;
        }
        $order = $this->repository->identity($orderId);
        return $order !== null && hash_equals($this->signature($order), $signature);
    }

    /** @param array<string,mixed> $order */
    private function signature(array $order): string
    {
        $key = $this->config->get('APP_KEY');
        if ($key === '') {
            throw new RuntimeException('APP_KEY este necesară pentru linkurile de urmărire.');
        }
        return hash_hmac('sha256', 'order-tracking:v1|' . $order['id'] . '|' . $order['email'] . '|' . $order['public_token_hash'], $key);
    }

    /** @return array<string,mixed>|null */
    public function details(int $orderId): ?array
    {
        $order = $this->orders->detailsForTracking($orderId);
        if ($order === null) {
            return null;
        }
        // Keep internal notes, payment provider identifiers and tokens out of the view model.
        $order = array_intersect_key($order, array_flip(['id','order_number','first_name','email','phone','status','payment_status','payment_method','created_at','updated_at','shipped_at','cancelled_at','shipping_method_name','shipping_address','subtotal_bani','discount_bani','shipping_bani','shipping_discount_bani','tax_bani','total_bani','items']));
        $stages = [
            'new' => ['Comandă primită', 'Alegerile tale au ajuns la noi.', 'bag'],
            'confirmed' => ['Confirmată', 'Am verificat detaliile comenzii.', 'check'],
            'preparing' => ['În pregătire', 'Pregătim cu grijă fiecare produs.', 'gift'],
            'shipped' => ['Pe drum spre tine', 'Coletul a fost predat pentru livrare.', 'truck'],
            'delivered' => ['Livrată', 'Comanda a ajuns la destinație.', 'heart'],
        ];
        $dates = ['new' => $order['created_at']];
        foreach (['shipped'=>'shipped_at', 'cancelled'=>'cancelled_at'] as $status => $field) {
            if (!empty($order[$field])) {
                $dates[$status] = $order[$field];
            }
        }
        $actions = ['order.confirm'=>'confirmed', 'order.prepare'=>'preparing', 'order.ship'=>'shipped', 'order.deliver'=>'delivered', 'order.cancel'=>'cancelled', 'order.return_full'=>'returned', 'order.manual_review'=>'manual_review', 'stripe.session_failed'=>'manual_review'];
        foreach ($this->repository->events($orderId) as $event) {
            $context = json_decode((string) $event['context_json'], true);
            if ($event['action'] === 'stripe.session_failed' && is_array($context) && ($context['status_changed'] ?? true) === false) {
                continue;
            }
            if (isset($actions[$event['action']])) {
                $dates[$actions[$event['action']]] = $event['created_at'];
            }
        }
        $current = array_search($order['status'], array_keys($stages), true);
        $upcoming = [
            'confirmed'=>'Verificarea detaliilor comenzii.',
            'preparing'=>'Pregătirea produselor pentru tine.',
            'shipped'=>'Preluarea coletului pentru livrare.',
            'delivered'=>'Ultimul pas: bucuria ajunge la tine.',
        ];
        $order['timeline'] = [];
        foreach ($stages as $key => [$label, $description, $icon]) {
            $index = count($order['timeline']);
            $done = $current !== false ? $index < $current : isset($dates[$key]);
            $order['timeline'][] = ['key'=>$key, 'label'=>$label, 'description'=>!$done && $key !== $order['status'] ? ($upcoming[$key] ?? $description) : $description, 'icon'=>$icon,
                'done'=>$done,
                'current'=>$key === $order['status'], 'date'=>$dates[$key] ?? null];
        }
        $order['status_label'] = $stages[$order['status']][0] ?? match ($order['status']) {
            'cancelled'=>'Comandă anulată', 'returned'=>'Comandă returnată', default=>'Verificăm comanda',
        };
        $order['status_description'] = $stages[$order['status']][1] ?? match ($order['status']) {
            'cancelled'=>'Această comandă a fost anulată. Suntem aici dacă ai nevoie de ajutor.',
            'returned'=>'Returul acestei comenzi a fost înregistrat. Starea plății este afișată separat.',
            default=>'Ne ocupăm de verificarea detaliilor și te vom ține la curent.',
        };
        $order['status_icon'] = $stages[$order['status']][2] ?? 'info';
        $order['status_date'] = $dates[$order['status']] ?? null;
        $order['payment_label'] = match ($order['payment_status']) {
            'paid'=>'Plătită', 'failed'=>'Plată eșuată', 'refunded'=>'Rambursată', 'partially_refunded'=>'Rambursată parțial',
            default=>$order['payment_method'] === 'cod' ? 'Ramburs la livrare' : 'Plată în așteptare',
        };
        $order['quantity'] = array_sum(array_column($order['items'], 'quantity'));
        $order['cancellation_request'] = $this->repository->cancellationRequest($orderId);
        $order['cancellation_deadline'] = gmdate('c', OrderCancellationPolicy::deadline((string) $order['created_at']));
        $order['cancellation_remaining'] = max(0, OrderCancellationPolicy::deadline((string) $order['created_at']) - time());
        $order['can_request_cancellation'] = $order['cancellation_request'] === null
            && OrderCancellationPolicy::allows((string) $order['status'], (string) $order['created_at']);
        $order['invoices'] = [];
        foreach ($this->repository->documents($orderId) as $document) {
            if ($this->resolvePdf((string) $document['private_path']) !== null) {
                $order['invoices'][] = ['id'=>$document['id'], 'number'=>$document['document_number']];
            }
        }
        return $order;
    }

    /** Records intent only. Approval, refunds and fiscal integrations are separate workflows. */
    public function requestCancellation(int $orderId, string $reason): int
    {
        $reason = trim((string) preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new \DomainException('Scrie motivul anulării în 5–1.000 de caractere.');
        }
        return $this->repository->transaction(function () use ($orderId, $reason): int {
            $order = $this->repository->lockCancellationOrder($orderId);
            if ($order === null) {
                throw new \DomainException('Comanda nu mai este disponibilă.');
            }
            $existing = $this->repository->cancellationRequest($orderId);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            // Recheck after acquiring the order lock; an open form grants no extra time.
            if (!OrderCancellationPolicy::allows((string) $order['status'], (string) $order['created_at'])) {
                throw new \DomainException('Cererea nu mai poate fi trimisă: au trecut 5 zile de la plasare sau comanda a depășit etapa de pregătire.');
            }
            return $this->repository->addCancellationRequest($orderId, $reason, (string) $order['status'], gmdate('c'));
        });
    }

    /** @return array{path:string,name:string}|null */
    public function invoice(int $orderId, int $documentId): ?array
    {
        foreach ($this->repository->documents($orderId) as $document) {
            if ((int) $document['id'] === $documentId) {
                $path = $this->resolvePdf((string) $document['private_path']);
                return $path === null ? null : ['path'=>$path, 'name'=>'factura-' . $documentId . '.pdf'];
            }
        }
        return null;
    }

    /** Integration entry point: the invoicing system stores the PDF privately first. */
    public function registerInvoice(int $orderId, string $number, string $relativePath): int
    {
        if ($this->repository->identity($orderId) === null || trim($number) === '' || mb_strlen($number) > 190 || $this->resolvePdf($relativePath) === null) {
            throw new RuntimeException('Factura PDF sau comanda nu este disponibilă.');
        }
        return $this->repository->addInvoice($orderId, trim($number), $relativePath);
    }

    private function resolvePdf(string $relativePath): ?string
    {
        $base = realpath($this->root . '/storage/private/invoices');
        $path = realpath($this->root . '/storage/private/invoices/' . $relativePath);
        if ($base === false || $path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $base) . '/') || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $signature = fread($handle, 5);
        fclose($handle);
        return $signature === '%PDF-' ? $path : null;
    }
}
