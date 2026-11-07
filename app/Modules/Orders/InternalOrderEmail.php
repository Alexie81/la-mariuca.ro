<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Shipping\DeliveryEstimateProvider;
use App\Modules\Shipping\StoredDeliveryEstimate;
use App\Shared\Config\Config;
use DomainException;
use Twig\Environment;

/** Internal delivery is authorized by the recipient snapshot saved in the outbox. */
final class InternalOrderEmail
{
    public function __construct(
        private readonly OrderTrackingService $tracking,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly OrderEmailImages $images,
        private readonly DeliveryEstimateProvider $deliveryEstimates = new StoredDeliveryEstimate(),
    ) {}

    /** Caller must use the recipient of a server-created internal notification, never a public request.
     * @return array{subject:string,text:string,html:string}
     */
    public function render(int $orderId, string $recipient): array
    {
        if (strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Adresa pentru notificarea internă nu este validă.');
        }
        $order = $this->tracking->details($orderId);
        if ($order === null) { throw new DomainException('Comanda nu este disponibilă.'); }
        $card = $order['payment_method'] === 'stripe';
        if ($card && $order['payment_status'] !== 'paid') {
            throw new DomainException('Notificarea internă așteaptă plata cardului.');
        }
        if (in_array($order['status'], ['cancelled', 'returned'], true)) {
            throw new DomainException('Nu se anunță o comandă nouă dacă a fost anulată sau returnată.');
        }
        $review = $order['status'] === 'manual_review';
        $shared = (new OrderEmailViewData($this->config, $this->images, $this->deliveryEstimates))->forOrder($order);
        $subject = 'Ai primit o nouă comandă! ' . $order['order_number'];
        $data = [
            'subject' => $subject,
            'preheader' => $order['order_number'] . ' · ' . $shared['total'] . ' · ' . $shared['payment_method'] . ' — ' . $shared['payment_state'] . '.',
            'badge' => $review ? 'Plătită · necesită verificare' : 'Comandă nouă în magazin',
            'internal_email' => true, 'review' => $review,
            // An ordinary admin URL always requires an authenticated administrator.
            // Never include a customer tracking token in an internal notification.
            'admin_order_url' => $this->config->rootUrl() . '/admin/comenzi/' . $orderId . '/',
            'shop_url' => $this->config->rootUrl() . '/',
            'logo_url' => $this->config->rootUrl() . '/assets/images/logo-la-mariuca-transparent.png',
            'status_icon' => $this->config->rootUrl() . '/assets/images/email-status-' . ($review ? 'review' : 'received') . '.png',
            'status_color' => $review ? '#94662e' : '#207552',
            'delivery_icon' => $this->config->rootUrl() . '/assets/images/email-order-truck.png',
            'payment_icon' => $this->config->rootUrl() . '/assets/images/email-order-' . ($card ? 'card' : 'cash') . '.png',
            'heart_icon_url' => $this->config->rootUrl() . '/assets/images/icon-favorite-hearts.png',
            'year' => date('Y'),
        ] + $shared;
        return ['subject' => $subject,
            'html' => $this->twig->render('emails/internal-order.twig', $data),
            'text' => $this->twig->render('emails/internal-order-text.twig', $data)];
    }
}
