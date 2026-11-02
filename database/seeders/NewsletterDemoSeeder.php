<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class NewsletterDemoSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(int $count = 18): int
    {
        $count = max(1, min(60, $count));
        $names = ['Ioana Marinescu', 'Andreea Pavel', 'Elena Ionescu', 'Maria Dobre', 'Cristina Stan', 'Raluca Pop', 'Diana Matei', 'Alina Radu', 'Gabriela Tudor', 'Oana Ene', 'Simona Luca', 'Nicoleta Ilie'];
        $inserted = 0;
        $featuredId = 0;
        $base = new \DateTimeImmutable('2026-09-12 08:30:00', new \DateTimeZone('UTC'));
        for ($index = 1; $index <= $count; $index++) {
            $email = sprintf('newsletter.demo+%02d@example.test', $index);
            $existing = $this->pdo->prepare('SELECT id FROM newsletter_subscribers WHERE email=:email LIMIT 1');
            $existing->execute(['email' => $email]);
            $subscriberId = $existing->fetchColumn();
            $source = $index % 3 === 0 ? 'contact_form' : 'footer_newsletter';
            $subscribedAt = $base->modify('+' . $index . ' days')->format('c');
            if ($subscriberId === false) {
                $inactive = in_array($index, [7, 14], true);
                $unsubscribeAt = $inactive ? $base->modify('+' . ($index + 20) . ' days')->format('c') : null;
                $insert = $this->pdo->prepare("INSERT INTO newsletter_subscribers (user_id,email,name,status,subscribe_source,unsubscribe_source,subscribed_at,unsubscribed_at,last_notified_at,created_at,updated_at)
                    VALUES (NULL,:email,:name,:status,:source,:unsubscribe_source,:subscribed,:unsubscribed,NULL,:subscribed,:subscribed)");
                $insert->execute([
                    'email' => $email,
                    'name' => $names[($index - 1) % count($names)],
                    'status' => $inactive ? 'unsubscribed' : 'subscribed',
                    'source' => $source,
                    'unsubscribe_source' => $inactive ? 'email_link' : null,
                    'subscribed' => $subscribedAt,
                    'unsubscribed' => $unsubscribeAt,
                ]);
                $subscriberId = (int) $this->pdo->lastInsertId();
                $event = $this->pdo->prepare("INSERT INTO newsletter_subscription_events (subscriber_id,event_type,source,details_json,created_at) VALUES (:subscriber,'subscribe',:source,'{}',:created)");
                $event->execute(['subscriber' => $subscriberId, 'source' => $source, 'created' => $subscribedAt]);
                if ($inactive) {
                    $event = $this->pdo->prepare("INSERT INTO newsletter_subscription_events (subscriber_id,event_type,source,details_json,created_at) VALUES (:subscriber,'unsubscribe','email_link','{}',:created)");
                    $event->execute(['subscriber' => $subscriberId, 'created' => $unsubscribeAt]);
                }
                $inserted++;
            }
            if ($index === 1) {
                $featuredId = (int) $subscriberId;
            }
        }

        if ($featuredId > 0) {
            $subjects = ['Bun venit în comunitatea La Măriuca', 'Noutăți moi pentru zile răcoroase', 'Ghidul cadourilor pentru cei mici', 'Colecția de botez, aleasă cu grijă', 'Povești noi din atelier', 'O surpriză pentru weekend'];
            foreach ($subjects as $index => $subject) {
                $sentAt = $base->modify('+' . (35 + $index * 4) . ' days')->format('c');
                $messageLookup = $this->pdo->prepare('SELECT id FROM newsletter_messages WHERE subject=:subject LIMIT 1');
                $messageLookup->execute(['subject' => $subject]);
                $messageId = $messageLookup->fetchColumn();
                if ($messageId === false) {
                    $message = $this->pdo->prepare("INSERT INTO newsletter_messages (subject,preview_text,status,sent_at,created_at,updated_at) VALUES (:subject,:preview,'sent',:sent,:sent,:sent)");
                    $message->execute(['subject' => $subject, 'preview' => 'Selecții, inspirație și noutăți pentru copilărie frumoasă.', 'sent' => $sentAt]);
                    $messageId = (int) $this->pdo->lastInsertId();
                }
                $deliveryLookup = $this->pdo->prepare('SELECT id FROM newsletter_deliveries WHERE subscriber_id=:subscriber AND message_id=:message LIMIT 1');
                $deliveryLookup->execute(['subscriber' => $featuredId, 'message' => $messageId]);
                if ($deliveryLookup->fetchColumn() === false) {
                    $status = $index % 3 === 0 ? 'opened' : 'delivered';
                    $delivery = $this->pdo->prepare("INSERT INTO newsletter_deliveries (subscriber_id,message_id,status,sent_at,delivered_at,opened_at,clicked_at,error_message,created_at,updated_at)
                        VALUES (:subscriber,:message,:status,:sent,:sent,:opened,NULL,NULL,:sent,:sent)");
                    $delivery->execute(['subscriber' => $featuredId, 'message' => $messageId, 'status' => $status, 'sent' => $sentAt, 'opened' => $status === 'opened' ? $sentAt : null]);
                }
                $this->pdo->prepare('UPDATE newsletter_subscribers SET last_notified_at=:sent,updated_at=:sent WHERE id=:id')->execute(['sent' => $sentAt, 'id' => $featuredId]);
            }
        }

        return $inserted;
    }
}
