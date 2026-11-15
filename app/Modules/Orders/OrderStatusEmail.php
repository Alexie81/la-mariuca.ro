<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Twig\Environment;

/** Status and personal message are preserved from the queued event. */
final class OrderStatusEmail
{
    public function __construct(
        private readonly OrderTrackingService $tracking,
        private readonly Config $config,
        private readonly Environment $twig,
    ) {}

    /** @param array<string,mixed> $payload @return array{subject:string,text:string,html:string} */
    public function render(array $payload, string $recipient): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $trackingUrl = $this->tracking->emailUrl($orderId, $recipient);
        $order = $this->tracking->details($orderId);
        if ($order === null) { throw new DomainException('Comanda nu este disponibilă.'); }
        $labels = ['new'=>'nouă', 'confirmed'=>'confirmată', 'preparing'=>'în pregătire', 'shipped'=>'expediată',
            'delivered'=>'livrată', 'cancelled'=>'anulată', 'returned'=>'returnată', 'manual_review'=>'în verificare',
            'return_requested'=>'cu retur solicitat', 'return_confirmed'=>'cu retur confirmat', 'return_refused'=>'cu retur refuzat', 'refunded'=>'rambursată'];
        $state = (string) ($payload['status'] ?? $order['status']);
        if (!isset($labels[$state])) { throw new DomainException('Starea comenzii nu este validă.'); }
        $customMessage = trim((string) ($payload['message'] ?? ''));
        $root = $this->config->rootUrl();
        $subject = ($customMessage !== '' ? 'Mesaj de la magazin — comanda ' : 'Actualizare pentru comanda ') . $order['order_number'];
        $data = [
            'subject'=>$subject, 'preheader'=>'Comanda ' . $order['order_number'] . ' este ' . $labels[$state] . '. Vezi mesajul echipei La Măriuca.',
            'first_name'=>trim((string) $order['first_name']), 'number'=>$order['order_number'], 'email'=>$recipient,
            'date'=>(new DateTimeImmutable((string) $order['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i'),
            'status_label'=>$labels[$state], 'custom_message'=>$customMessage,
            'badge'=>'Noutăți despre comanda ta', 'status_color'=>'#95506d',
            'status_icon'=>$root . '/assets/images/email-order-receipt-v2.png',
            'tracking_url'=>$trackingUrl, 'shop_url'=>$root . '/',
            'logo_url'=>$root . '/assets/images/logo-la-mariuca-transparent.png',
            'contact_url'=>$root . '/pagina/contact/', 'delivery_url'=>$root . '/pagina/livrare-si-retur/',
            'terms_url'=>$root . '/pagina/termeni-si-conditii/', 'privacy_url'=>$root . '/pagina/confidentialitate/',
            'anpc_logo_url'=>$root . '/assets/images/anpc-sal.png', 'cab_logo_url'=>$root . '/assets/images/cab-it-mark.png',
            'care_badge_url'=>$root . '/assets/images/email-customer-care.png',
            'heart_icon_url'=>$root . '/assets/images/icon-favorite-hearts.png',
            'contact_email'=>$this->config->get('CONTACT_EMAIL', 'contact@la-mariuca.ro'), 'year'=>date('Y'),
        ];
        return ['subject'=>$subject, 'html'=>$this->twig->render('emails/order-status.twig', $data),
            'text'=>$this->twig->render('emails/order-status-text.twig', $data)];
    }
}
