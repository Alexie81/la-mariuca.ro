<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Twig\Environment;

/** Renders immutable cancellation events; delivery recipients come from the server-created outbox. */
final class OrderCancellationEmail
{
    public const TYPES = ['order_cancellation_received', 'internal_order_cancellation', 'order_cancellation_decision'];

    public function __construct(
        private readonly OrderTrackingService $tracking,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly OrderEmailImages $images,
    ) {}

    /** @return array{subject:string,text:string,html:string} */
    public function render(int $orderId, int $requestId, string $recipient, string $type): array
    {
        if (!in_array($type, self::TYPES, true) || $requestId < 1) {
            throw new DomainException('Evenimentul solicitării de anulare nu este valid.');
        }
        if (strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Destinatarul notificării nu este valid.');
        }
        $internal = $type === 'internal_order_cancellation';
        // Customer emails authorize the recipient before loading any order details.
        $trackingUrl = $internal ? null : $this->tracking->emailUrl($orderId, $recipient);
        $order = $this->tracking->details($orderId);
        $request = $order['cancellation_request'] ?? null;
        if ($order === null || !is_array($request) || (int) $request['id'] !== $requestId) {
            throw new DomainException('Solicitarea de anulare nu aparține acestei comenzi.');
        }
        $decision = $type === 'order_cancellation_decision';
        $state = $decision ? (string) $request['status'] : 'pending';
        if ($decision && (!in_array($state, ['accepted', 'rejected'], true) || empty($request['resolved_at']))) {
            throw new DomainException('Solicitarea de anulare nu are încă o decizie.');
        }
        $accepted = $state === 'accepted';
        $rejected = $state === 'rejected';
        $customerName = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', trim($order['first_name'] . ' ' . $order['last_name'])));
        $subject = $internal ? mb_substr($customerName, 0, 120) . ' solicită anularea comenzii ' . $order['order_number']
            : 'Comanda ' . $order['order_number'] . ' — ' . ($accepted ? 'solicitarea de anulare a fost acceptată'
                : ($rejected ? 'răspuns la solicitarea de anulare' : 'am primit solicitarea de anulare'));
        $message = $internal ? 'Ai primit o solicitare de anulare.'
            : ($accepted ? 'Solicitarea ta de anulare a fost acceptată.'
                : ($rejected ? 'Solicitarea ta de anulare nu a fost acceptată.' : 'Am primit solicitarea ta de anulare.'));
        $introduction = $internal ? 'Clientul ' . $customerName . ' dorește anularea comenzii ' . $order['order_number'] . '. Verifică motivul și produsele de mai jos, apoi răspunde din administrarea magazinului.'
            : ($accepted ? 'Am analizat solicitarea și am anulat comanda. Îți mulțumim că ne-ai anunțat.'
                : ($rejected ? 'Am analizat solicitarea. Mai jos găsești motivul deciziei noastre. Dacă ai nevoie de ajutor, ne poți scrie direct.'
                    : 'Cererea ta a fost înregistrată. Îți comunicăm rezultatul verificării într-un email separat. Acest mesaj confirmă primirea cererii, nu aprobarea anulării.'));
        $shared = (new OrderEmailViewData($this->config, $this->images))->forOrder($order);
        // Unlike new-order confirmations, cancellation emails may concern unpaid or refunded cards.
        $paymentState = match ((string) $order['payment_status']) {
            'paid' => 'Plătit', 'refunded' => 'Rambursat', 'partially_refunded' => 'Rambursat parțial', 'failed' => 'Plată eșuată',
            default => $order['payment_method'] === 'stripe' ? 'Plată în așteptare'
                : ($order['status'] === 'cancelled' ? 'Fără plată la livrare' : 'De achitat la livrare'),
        };
        $shared['payment_state'] = $paymentState;
        $shared['paid'] = $order['payment_status'] === 'paid';
        // This is an order snapshot, not a renewed promise to deliver a cancelled order.
        $shared['delivery_estimate'] = null;
        $rootUrl = $this->config->rootUrl();
        $data = [
            'subject' => $subject, 'preheader' => $message . ' ' . $order['order_number'],
            'message' => $message, 'introduction' => $introduction,
            'badge' => $internal ? 'Cerere nouă de anulare' : ($accepted ? 'Anulare acceptată' : ($rejected ? 'Solicitare respinsă' : 'Cerere înregistrată')),
            'status_icon' => $rootUrl . '/assets/images/email-status-' . ($accepted ? 'confirmed' : 'review') . '.png',
            'status_color' => $accepted ? '#207552' : ($rejected ? '#a14962' : '#94662e'),
            'internal_email' => $internal, 'decision_email' => $decision,
            'accepted' => $accepted, 'rejected' => $rejected,
            'request_reason' => (string) $request['reason'],
            'request_date' => $this->displayDate((string) $request['created_at']),
            'decision_reason' => $decision ? (string) ($request['decision_reason'] ?? '') : '',
            'decision_date' => $decision ? $this->displayDate((string) $request['resolved_at']) : '',
            'refund_note' => $accepted && in_array($order['payment_status'], ['paid', 'partially_refunded'], true),
            'summary_payment_note' => $order['status'] === 'cancelled' && $order['payment_method'] === 'cod' && in_array($order['payment_status'], ['pending', 'failed'], true)
                ? 'Comandă anulată · fără plată la livrare' : $paymentState,
            'cta_url' => $internal ? $rootUrl . '/admin/comenzi/' . $orderId . '/#cerere-anulare' : $trackingUrl,
            'cta_label' => $internal ? 'Vezi solicitarea de anulare' : 'Vezi comanda și solicitarea',
            'tracking_url' => $trackingUrl,
            'shop_url' => $rootUrl . '/', 'logo_url' => $rootUrl . '/assets/images/logo-la-mariuca-transparent.png',
            'contact_url' => $rootUrl . '/pagina/contact/', 'delivery_url' => $rootUrl . '/pagina/livrare-si-retur/',
            'terms_url' => $rootUrl . '/pagina/termeni-si-conditii/', 'privacy_url' => $rootUrl . '/pagina/confidentialitate/',
            'anpc_logo_url' => $rootUrl . '/assets/images/anpc-sal.png', 'cab_logo_url' => $rootUrl . '/assets/images/cab-it-mark.png',
            'care_badge_url' => $rootUrl . '/assets/images/email-customer-care.png',
            'heart_icon_url' => $rootUrl . '/assets/images/icon-favorite-hearts.png',
            'contact_email' => $this->config->get('CONTACT_EMAIL', 'contact@la-mariuca.ro'), 'year' => date('Y'),
        ] + $shared;
        return ['subject' => $subject, 'html' => $this->twig->render('emails/order-cancellation.twig', $data),
            'text' => $this->twig->render('emails/order-cancellation-text.twig', $data)];
    }

    private function displayDate(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i');
    }
}
