<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Modules\Orders\OrderConfirmationEmail;
use App\Modules\Orders\OrderEmailImages;
use App\Modules\Orders\OrderService;
use App\Modules\Orders\OrderTrackingEmail;
use App\Modules\Orders\OrderTrackingRepository;
use App\Modules\Orders\OrderTrackingService;
use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OutboxProcessor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly string $root,
        private readonly ?TransportInterface $transport = null,
    ) {}

    /** @return array{processed:int,sent:int,previewed:int,failed:int} */
    public function process(int $limit = 20): array
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Emailurile se procesează numai în afara tranzacțiilor.'); }
        $mode = MailDeliveryPolicy::resolve($this->config);
        $floor = $this->config->get('MAIL_SEND_AFTER_ID', '0');
        if (!ctype_digit($floor)) { throw new RuntimeException('MAIL_SEND_AFTER_ID trebuie să fie un ID pozitiv sau zero.'); }
        $types = array_values(array_filter(array_map('trim', explode(',', $this->config->get('MAIL_MESSAGE_TYPES')))));
        foreach ($types as $type) {
            if (!in_array($type, ['order_confirmation', 'order_status_update', 'verify_email'], true)) {
                throw new RuntimeException('MAIL_MESSAGE_TYPES conține un tip necunoscut.');
            }
        }
        $params = ['floor' => (int) $floor, 'now' => gmdate('c')];
        $scope = 'id > :floor';
        if ($types !== []) {
            $holders = [];
            foreach ($types as $index => $type) { $holders[] = ':type' . $index; $params['type' . $index] = $type; }
            $scope .= ' AND message_type IN (' . implode(',', $holders) . ')';
        }
        $eligible = "status IN ('pending','failed') AND available_at <= :now AND attempts < 5 AND " . $scope;
        $statement = $this->pdo->prepare('SELECT * FROM outbox_messages WHERE ' . $eligible . ' ORDER BY id LIMIT ' . max(1, min(100, $limit)));
        $statement->execute($params);
        $messages = $statement->fetchAll();
        $result = ['processed' => 0, 'sent' => 0, 'previewed' => 0, 'failed' => 0];
        if ($messages === []) { return $result; }
        $transport = null;
        if ($mode === 'smtp') {
            try { $transport = $this->transport ?? Transport::fromDsn($this->config->get('MAILER_DSN')); }
            catch (Throwable) { throw new RuntimeException('Transportul SMTP nu a putut fi inițializat. Verifică configurația privată.'); }
        }
        $tracking = new OrderTrackingService(new OrderTrackingRepository($this->pdo), new OrderService($this->pdo), $this->config, $this->root);
        $confirmation = new OrderConfirmationEmail($tracking, $this->config,
            new Environment(new FilesystemLoader($this->root . '/resources/views'), ['autoescape' => 'html']),
            new OrderEmailImages($this->config, $this->root));
        foreach ($messages as $message) {
            // Repeat the full eligibility check: a stale worker must not bypass retries or activation scope.
            $claim = $this->pdo->prepare("UPDATE outbox_messages SET status='processing',attempts=attempts+1 WHERE id=:id AND " . $eligible);
            $claim->execute($params + ['id' => $message['id']]);
            if ($claim->rowCount() !== 1) { continue; }
            $result['processed']++;
            $smtpAccepted = false;
            try {
                $payload = json_decode((string) $message['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $html = null;
                if ($message['message_type'] === 'order_confirmation') {
                    $rendered = $confirmation->render((int) ($payload['order_id'] ?? 0), (string) $message['recipient']);
                    $subject = $rendered['subject']; $body = $rendered['text']; $html = $rendered['html'];
                } elseif ($message['message_type'] === 'order_status_update') {
                    $labels = ['new'=>'nouă','confirmed'=>'confirmată','preparing'=>'în pregătire','shipped'=>'expediată','delivered'=>'livrată','cancelled'=>'anulată','returned'=>'returnată','manual_review'=>'în verificare'];
                    $number = (string) ($payload['order_number'] ?? ('#' . (int) ($payload['order_id'] ?? 0)));
                    $status = $labels[(string) ($payload['status'] ?? '')] ?? (string) ($payload['status'] ?? 'actualizată');
                    $subject = 'Actualizare pentru comanda ' . $number;
                    $body = 'Bună, ' . (string) ($payload['customer_name'] ?? '') . "!\n\nComanda " . $number . ' este acum ' . $status . '.';
                    if (trim((string) ($payload['message'] ?? '')) !== '') { $body .= "\n\n" . trim((string) $payload['message']); }
                    $body .= "\n\nÎți mulțumim,\nEchipa La Măriuca Boutique";
                    $rendered = (new OrderTrackingEmail($tracking))->withTracking($body, (int) ($payload['order_id'] ?? 0), (string) $message['recipient']);
                    $body = $rendered['text']; $html = $rendered['html'];
                } elseif ($message['message_type'] === 'verify_email') {
                    $subject = 'Verifică adresa de email';
                    $body = 'Folosește tokenul de verificare transmis securizat de aplicație.';
                } else { throw new RuntimeException('Tip de mesaj necunoscut.'); }
                if ($transport !== null) {
                    $email = (new Email())->from(new Address($this->config->get('MAIL_FROM_ADDRESS'), $this->config->get('MAIL_FROM_NAME', 'La Măriuca Boutique')))
                        ->replyTo($this->config->get('CONTACT_EMAIL', 'contact@la-mariuca.ro'))->to((string) $message['recipient'])->subject($subject)->text($body);
                    if ($html !== null) { $email->html($this->embedLocalImages($email, $html)); }
                    (new Mailer($transport))->send($email);
                    $smtpAccepted = true;
                    $result['sent']++;
                } else {
                    $directory = $this->root . '/storage/private/mail';
                    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) { throw new RuntimeException('Directorul preview nu este disponibil.'); }
                    if (file_put_contents($directory . '/' . (int) $message['id'] . '.txt', "To: " . $message['recipient'] . "\nSubject: {$subject}\n\n{$body}\n", LOCK_EX) === false) { throw new RuntimeException('Preview-ul nu a fost salvat.'); }
                    if ($html !== null && file_put_contents($directory . '/' . (int) $message['id'] . '.html', $html, LOCK_EX) === false) { throw new RuntimeException('Preview-ul HTML nu a fost salvat.'); }
                    $result['previewed']++;
                }
                // Preserve the existing schema's completed state; counters distinguish SMTP from previews.
                $this->pdo->prepare("UPDATE outbox_messages SET status='sent',sent_at=:now,last_error=NULL WHERE id=:id")->execute(['now'=>gmdate('c'),'id'=>$message['id']]);
            } catch (Throwable) {
                if ($smtpAccepted) {
                    // SMTP already accepted this message. Never enqueue it again if persistence failed.
                    $this->pdo->prepare("UPDATE outbox_messages SET last_error=:error WHERE id=:id AND status='processing'")->execute([
                        'error'=>'SMTP a acceptat mesajul, dar finalizarea în bază a eșuat. Necesită verificare manuală; nu retrimite automat.', 'id'=>$message['id'],
                    ]);
                    $result['failed']++;
                    continue;
                }
                // Transport exceptions can contain credentials or AUTH transcripts. Never persist them.
                $safeError = $mode === 'smtp' ? 'Expedierea nu a reușit; verifică datele comenzii și configurația SMTP. Reîncercare automată.' : 'Previzualizarea nu a putut fi generată. Reîncercare automată.';
                $this->pdo->prepare("UPDATE outbox_messages SET status='failed',available_at=:retry,last_error=:error WHERE id=:id")->execute(['retry'=>gmdate('c', time()+300),'error'=>$safeError,'id'=>$message['id']]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private function embedLocalImages(Email $email, string $html): string
    {
        $prefix = $this->config->rootUrl() . '/';
        $public = realpath($this->root . '/public');
        if ($public === false) { return $html; }
        $embedded = [];
        return preg_replace_callback('/(<img\b[^>]*\bsrc=")([^"]+)(")/i', function (array $match) use ($email, $prefix, $public, &$embedded): string {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_starts_with($url, $prefix)) { return $match[0]; }
            $relative = rawurldecode(substr($url, strlen($prefix)));
            if (str_contains($relative, '..') || str_contains($relative, ':') || str_contains($relative, "\0")) { return $match[0]; }
            $path = realpath($public . '/' . $relative);
            if ($path === false || !str_starts_with($path, $public . DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path) > 2_000_000) { return $match[0]; }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, ['image/png','image/jpeg','image/gif'], true)) { return $match[0]; }
            if (!isset($embedded[$path])) {
                $embedded[$path] = 'store-image-' . count($embedded);
                $email->embedFromPath($path, $embedded[$path], $mime);
            }
            return $match[1] . 'cid:' . $embedded[$path] . $match[3];
        }, $html) ?? $html;
    }
}
