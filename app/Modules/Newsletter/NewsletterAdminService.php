<?php

declare(strict_types=1);

namespace App\Modules\Newsletter;

use PDO;

final class NewsletterAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int,from:int,to:int}
     */
    public function paginate(int $page, int $perPage = 15, string $search = ''): array
    {
        $perPage = max(1, min(50, $perPage));
        [$where, $parameters] = $this->searchWhere($search);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM newsletter_subscribers s' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare("SELECT s.*,u.first_name AS linked_first_name,u.last_name AS linked_last_name,
                (SELECT COUNT(*) FROM newsletter_deliveries d WHERE d.subscriber_id=s.id AND d.status IN ('sent','delivered','opened','clicked','bounced','failed')) AS message_count,
                (SELECT m.subject FROM newsletter_deliveries d JOIN newsletter_messages m ON m.id=d.message_id WHERE d.subscriber_id=s.id AND d.status<>'queued' ORDER BY COALESCE(d.sent_at,d.created_at) DESC,d.id DESC LIMIT 1) AS last_message_subject
            FROM newsletter_subscribers s LEFT JOIN users u ON u.id=s.user_id" . $where . "
            ORDER BY CASE WHEN s.status='subscribed' THEN 0 ELSE 1 END,COALESCE(s.last_notified_at,s.subscribed_at) DESC,s.id DESC
            LIMIT :limit OFFSET :offset");
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /** @return array{total:int,active:int,from_contact:int,sent_messages:int} */
    public function summary(): array
    {
        $subscriber = $this->pdo->query("SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status='subscribed' THEN 1 ELSE 0 END),0) AS active,
                COALESCE(SUM(CASE WHEN subscribe_source='contact_form' THEN 1 ELSE 0 END),0) AS from_contact
            FROM newsletter_subscribers")->fetch();
        $sentMessages = (int) $this->pdo->query("SELECT COUNT(*) FROM newsletter_deliveries WHERE status IN ('sent','delivered','opened','clicked')")->fetchColumn();

        return [
            'total' => (int) ($subscriber['total'] ?? 0),
            'active' => (int) ($subscriber['active'] ?? 0),
            'from_contact' => (int) ($subscriber['from_contact'] ?? 0),
            'sent_messages' => $sentMessages,
        ];
    }

    /** @return array{subscriber:array<string,mixed>,events:list<array<string,mixed>>}|null */
    public function detail(int $subscriberId): ?array
    {
        $statement = $this->pdo->prepare("SELECT s.*,u.first_name AS customer_first_name,u.last_name AS customer_last_name,u.created_at AS customer_created_at,
                (SELECT COUNT(*) FROM newsletter_deliveries d WHERE d.subscriber_id=s.id AND d.status<>'queued') AS message_count,
                (SELECT COUNT(*) FROM newsletter_deliveries d WHERE d.subscriber_id=s.id AND d.status IN ('delivered','opened','clicked')) AS delivered_count,
                (SELECT COUNT(*) FROM newsletter_deliveries d WHERE d.subscriber_id=s.id AND d.status IN ('opened','clicked')) AS opened_count,
                (SELECT m.subject FROM newsletter_deliveries d JOIN newsletter_messages m ON m.id=d.message_id WHERE d.subscriber_id=s.id AND d.status<>'queued' ORDER BY COALESCE(d.sent_at,d.created_at) DESC,d.id DESC LIMIT 1) AS last_message_subject
            FROM newsletter_subscribers s LEFT JOIN users u ON u.id=s.user_id
            WHERE s.id=:id LIMIT 1");
        $statement->execute(['id' => $subscriberId]);
        $subscriber = $statement->fetch();
        if (!is_array($subscriber)) {
            return null;
        }

        $events = $this->pdo->prepare('SELECT * FROM newsletter_subscription_events WHERE subscriber_id=:id ORDER BY created_at DESC,id DESC LIMIT 30');
        $events->execute(['id' => $subscriberId]);

        return [
            'subscriber' => $subscriber,
            'events' => $events->fetchAll(),
        ];
    }

    /** @return array{0:string,1:array<string,string>} */
    private function searchWhere(string $search): array
    {
        $normalized = $this->normalize($search);
        if ($normalized === '') {
            return ['', []];
        }
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['a', 'al', 'ale', 'cu', 'de', 'din', 'in', 'la', 'si', 'un', 'registru'];
        $tokens = array_slice(array_values(array_unique(array_filter($tokens, static fn(string $token): bool => strlen($token) > 1 && !in_array($token, $stopWords, true)))), 0, 8);
        if ($tokens === []) {
            $tokens = [$normalized];
        }

        $where = [];
        $parameters = [];
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['activ', 'activa', 'abonat', 'abonata', 'abonati', 'subscribed'], true)) {
                $where[] = "s.status='subscribed'";
                continue;
            }
            if (in_array($token, ['dezabonat', 'dezabonata', 'inactiv', 'inactiva', 'unsubscribed'], true)) {
                $where[] = "s.status='unsubscribed'";
                continue;
            }
            if (in_array($token, ['newsletter', 'footer', 'subsol', 'formularnewsletter'], true)) {
                $where[] = "s.subscribe_source='footer_newsletter'";
                continue;
            }
            if (in_array($token, ['contact', 'whatsapp'], true)) {
                $where[] = "s.subscribe_source='contact_form'";
                continue;
            }
            if (in_array($token, ['comanda', 'checkout'], true)) {
                $where[] = "s.subscribe_source='checkout'";
                continue;
            }

            $clauses = [];
            foreach (['s.email', 's.name', 's.subscribe_source', 's.unsubscribe_source', 's.subscribed_at', 's.last_notified_at'] as $columnIndex => $column) {
                $placeholder = ':newsletter_' . $index . '_' . $columnIndex;
                $clauses[] = $this->normalizedColumn($column) . ' LIKE ' . $placeholder;
                $parameters[$placeholder] = '%' . $token . '%';
            }
            $messagePlaceholder = ':newsletter_message_' . $index;
            $clauses[] = 'EXISTS(SELECT 1 FROM newsletter_deliveries search_delivery JOIN newsletter_messages search_message ON search_message.id=search_delivery.message_id WHERE search_delivery.subscriber_id=s.id AND (' . $this->normalizedColumn('search_message.subject') . ' LIKE ' . $messagePlaceholder . ' OR ' . $this->normalizedColumn('search_delivery.status') . ' LIKE ' . $messagePlaceholder . '))';
            $parameters[$messagePlaceholder] = '%' . $token . '%';
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [' WHERE ' . implode(' AND ', $where), $parameters];
    }

    private function normalizedColumn(string $column): string
    {
        $expression = "LOWER(COALESCE({$column},''))";
        foreach (['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't'] as $from => $to) {
            $expression = "REPLACE({$expression},'{$from}','{$to}')";
        }
        return $expression;
    }

    private function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value), 'UTF-8'), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
