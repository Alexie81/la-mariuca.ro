<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Shared\Support\Slugger;
use PDO;
use RuntimeException;

final class ContentAdminService
{
    private \HTMLPurifier $purifier;

    public function __construct(private readonly PDO $pdo)
    {
        $configuration = \HTMLPurifier_Config::createDefault();
        $configuration->set('HTML.Allowed', 'p,br,strong,em,ul,ol,li,h2,h3,a[href|title],blockquote');
        $configuration->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $configuration->set('Attr.EnableID', false);
        $this->purifier = new \HTMLPurifier($configuration);
    }

    /** @return array<string,mixed>|null */
    public function page(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM content_pages WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function savePage(array $data, ?int $id = null): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Titlul este obligatoriu.');
        }
        $slug = Slugger::slug((string) ($data['slug'] ?? $title));
        $status = in_array(($data['status'] ?? ''), ['draft', 'published', 'archived'], true) ? (string) $data['status'] : 'draft';
        $params = [
            'title' => $title, 'slug' => $slug, 'body' => $this->purifier->purify((string) ($data['body_html'] ?? '')),
            'status' => $status, 'legal' => isset($data['legal_review_required']) ? 1 : 0,
            'seo_title' => trim((string) ($data['seo_title'] ?? '')), 'meta' => trim((string) ($data['meta_description'] ?? '')),
            'indexable' => isset($data['indexable']) ? 1 : 0, 'published_at' => $status === 'published' ? gmdate('c') : null, 'updated_at' => gmdate('c'),
        ];
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO content_pages (title,slug,body_html,status,legal_review_required,seo_title,meta_description,indexable,published_at,updated_at) VALUES (:title,:slug,:body,:status,:legal,:seo_title,:meta,:indexable,:published_at,:updated_at)')->execute($params);
            return (int) $this->pdo->lastInsertId();
        }
        $params['id'] = $id;
        $this->pdo->prepare('UPDATE content_pages SET title=:title,slug=:slug,body_html=:body,status=:status,legal_review_required=:legal,seo_title=:seo_title,meta_description=:meta,indexable=:indexable,published_at=:published_at,updated_at=:updated_at WHERE id=:id')->execute($params);
        return $id;
    }

    public function sectionAction(int $id, string $action): void
    {
        $statement = $this->pdo->prepare('SELECT id,position,enabled FROM homepage_sections WHERE id=:id');
        $statement->execute(['id' => $id]);
        $section = $statement->fetch();
        if (!is_array($section)) {
            throw new RuntimeException('Secțiunea nu există.');
        }
        if ($action === 'toggle') {
            $this->pdo->prepare('UPDATE homepage_sections SET enabled=:enabled,updated_at=:now WHERE id=:id')->execute(['enabled' => (int) $section['enabled'] === 1 ? 0 : 1, 'now' => gmdate('c'), 'id' => $id]);
            return;
        }
        $operator = $action === 'up' ? '<' : ($action === 'down' ? '>' : null);
        $direction = $action === 'up' ? 'DESC' : 'ASC';
        if ($operator === null) {
            throw new RuntimeException('Acțiune invalidă.');
        }
        $neighbor = $this->pdo->prepare("SELECT id,position FROM homepage_sections WHERE position {$operator} :position ORDER BY position {$direction} LIMIT 1");
        $neighbor->execute(['position' => $section['position']]);
        $other = $neighbor->fetch();
        if (!is_array($other)) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE homepage_sections SET position=:position,updated_at=:now WHERE id=:id')->execute(['position' => $other['position'], 'now' => gmdate('c'), 'id' => $id]);
            $this->pdo->prepare('UPDATE homepage_sections SET position=:position,updated_at=:now WHERE id=:id')->execute(['position' => $section['position'], 'now' => gmdate('c'), 'id' => $other['id']]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
