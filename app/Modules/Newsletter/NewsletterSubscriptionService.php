<?php

declare(strict_types=1);

namespace App\Modules\Newsletter;

use PDO;

final class NewsletterSubscriptionService
{
    private const SOURCES = ['footer_newsletter', 'contact_form', 'checkout', 'admin_import', 'unknown'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function subscribe(string $email, string $name, string $source): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $name = $this->singleLine($name, 190);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new \InvalidArgumentException('Introdu o adresă de email validă.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'unknown';
        }

        $linkedUser = $this->pdo->prepare("SELECT id,first_name,last_name FROM users WHERE role='customer' AND LOWER(email)=:email LIMIT 1");
        $linkedUser->execute(['email' => $email]);
        $user = $linkedUser->fetch();
        $userId = is_array($user) ? (int) $user['id'] : null;
        if ($name === '' && is_array($user)) {
            $name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id,status,name FROM newsletter_subscribers WHERE email=:email LIMIT 1');
            $existing->execute(['email' => $email]);
            $subscriber = $existing->fetch();
            $now = gmdate('c');
            if (!is_array($subscriber)) {
                $insert = $this->pdo->prepare("INSERT INTO newsletter_subscribers (user_id,email,name,status,subscribe_source,unsubscribe_source,subscribed_at,unsubscribed_at,last_notified_at,created_at,updated_at)
                    VALUES (:user,:email,:name,'subscribed',:source,NULL,:now,NULL,NULL,:now,:now)");
                $insert->execute(['user' => $userId, 'email' => $email, 'name' => $name !== '' ? $name : null, 'source' => $source, 'now' => $now]);
                $subscriberId = (int) $this->pdo->lastInsertId();
                $this->recordEvent($subscriberId, 'subscribe', $source, $now);
                $this->pdo->commit();
                return 'Abonarea a fost înregistrată. Îți mulțumim că rămâi aproape!';
            }

            $subscriberId = (int) $subscriber['id'];
            $resolvedName = $name !== '' ? $name : trim((string) ($subscriber['name'] ?? ''));
            if ((string) $subscriber['status'] === 'subscribed') {
                $update = $this->pdo->prepare('UPDATE newsletter_subscribers SET user_id=COALESCE(:user,user_id),name=:name,updated_at=:now WHERE id=:id');
                $update->execute(['user' => $userId, 'name' => $resolvedName !== '' ? $resolvedName : null, 'now' => $now, 'id' => $subscriberId]);
                $this->pdo->commit();
                return 'Adresa este deja abonată la noutățile La Măriuca.';
            }

            $update = $this->pdo->prepare("UPDATE newsletter_subscribers SET user_id=COALESCE(:user,user_id),name=:name,status='subscribed',subscribe_source=:source,unsubscribe_source=NULL,subscribed_at=:now,unsubscribed_at=NULL,updated_at=:now WHERE id=:id");
            $update->execute(['user' => $userId, 'name' => $resolvedName !== '' ? $resolvedName : null, 'source' => $source, 'now' => $now, 'id' => $subscriberId]);
            $this->recordEvent($subscriberId, 'resubscribe', $source, $now);
            $this->pdo->commit();
            return 'Abonarea a fost reactivată. Bine ai revenit!';
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function recordEvent(int $subscriberId, string $eventType, string $source, string $now): void
    {
        $statement = $this->pdo->prepare("INSERT INTO newsletter_subscription_events (subscriber_id,event_type,source,details_json,created_at) VALUES (:subscriber,:event,:source,'{}',:now)");
        $statement->execute(['subscriber' => $subscriberId, 'event' => $eventType, 'source' => $source, 'now' => $now]);
    }

    private function singleLine(string $value, int $limit): string
    {
        $value = preg_replace('/[\r\n\t]+/u', ' ', trim($value)) ?? '';
        return mb_substr(preg_replace('/\s{2,}/u', ' ', $value) ?? '', 0, $limit);
    }
}
