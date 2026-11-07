<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;
use DomainException;
use Twig\Environment;
use App\Modules\Shipping\DeliveryEstimateProvider;
use App\Modules\Shipping\StoredDeliveryEstimate;

final class OrderConfirmationEmail
{
    public function __construct(
        private readonly OrderTrackingService $tracking,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly OrderEmailImages $images,
        private readonly DeliveryEstimateProvider $deliveryEstimates = new StoredDeliveryEstimate(),
    ) {}

    /** @return array{subject:string,text:string,html:string} */
    public function render(int $orderId, string $recipient): array
    {
        // Authorize the recipient before loading or rendering personal details.
        $trackingUrl = $this->tracking->emailUrl($orderId, $recipient);
        $order = $this->tracking->details($orderId);
        if ($order === null) { throw new DomainException('Comanda nu este disponibilă.'); }
        $card = $order['payment_method'] === 'stripe';
        if ($card && $order['payment_status'] !== 'paid') {
            throw new DomainException('Confirmarea prin email așteaptă plata cardului.');
        }
        if (in_array($order['status'], ['cancelled', 'returned'], true)) {
            throw new DomainException('Nu se trimite o confirmare pentru o comandă anulată sau returnată.');
        }
        $review = $order['status'] === 'manual_review';
        $message = $review ? 'Plata a fost primită. Verificăm comanda.'
            : ($card ? 'Comanda a fost confirmată cu succes.' : 'Comanda a fost plasată cu succes.');
        $subject = 'Comanda ' . $order['order_number'] . ($review ? ' — plata a fost primită'
            : ($card ? ' a fost confirmată cu succes' : ' a fost primită cu succes'));
        $introduction = $review ? 'Am înregistrat plata. Echipa noastră verifică detaliile comenzii și va reveni cu o actualizare.'
            : ($card ? 'Plata ta a fost primită, iar comanda este confirmată. Îți mulțumim că ai ales La Măriuca pentru bucuriile celor mici.'
            : 'Am primit alegerile tale și ne vom ocupa cu grijă de fiecare detaliu. Vei achita comanda la livrare.');
        $shared = (new OrderEmailViewData($this->config, $this->images, $this->deliveryEstimates))->forOrder($order);
        $data = [
            'subject' => $subject, 'message' => $message, 'introduction' => $introduction,
            'preheader' => $message . ' ' . $shared['payment_method'] . ' — ' . $shared['payment_state'] . '. Toate detaliile comenzii tale sunt aici.',
            'badge' => $review ? 'În verificare' : ($card ? 'Comandă confirmată' : 'Comandă primită'),
            'tracking_url' => $trackingUrl, 'shop_url' => $this->config->rootUrl() . '/',
            'logo_url' => $this->config->rootUrl() . '/assets/images/logo-la-mariuca-transparent.png',
            'status_icon' => $this->config->rootUrl() . '/assets/images/email-status-' . ($review ? 'review' : ($card ? 'confirmed' : 'received')) . '.png',
            'status_color' => $review ? '#94662e' : '#207552',
            'delivery_icon' => $this->config->rootUrl() . '/assets/images/email-order-truck.png',
            'payment_icon' => $this->config->rootUrl() . '/assets/images/email-order-' . ($card ? 'card' : 'cash') . '.png',
            'contact_url' => $this->config->rootUrl() . '/pagina/contact/',
            'delivery_url' => $this->config->rootUrl() . '/pagina/livrare-si-retur/',
            'terms_url' => $this->config->rootUrl() . '/pagina/termeni-si-conditii/',
            'privacy_url' => $this->config->rootUrl() . '/pagina/confidentialitate/',
            'anpc_logo_url' => $this->config->rootUrl() . '/assets/images/anpc-sal.png',
            'cab_logo_url' => $this->config->rootUrl() . '/assets/images/cab-it-mark.png',
            'care_badge_url' => $this->config->rootUrl() . '/assets/images/email-customer-care.png',
            'heart_icon_url' => $this->config->rootUrl() . '/assets/images/icon-favorite-hearts.png',
            'contact_email' => $this->config->get('CONTACT_EMAIL', 'contact@la-mariuca.ro'),
            'year' => date('Y'),
        ] + $shared;
        return ['subject' => $subject, 'html' => $this->twig->render('emails/order-confirmation.twig', $data),
            'text' => $this->twig->render('emails/order-confirmation-text.twig', $data)];
    }
}
