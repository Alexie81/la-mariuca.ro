<?php

declare(strict_types=1);

namespace App\Modules\Content;

use PDO;

final class ContentRepository
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

    /** @return list<array<string,mixed>> */
    public function homepageSections(): array
    {
        $rows = $this->pdo->query('SELECT * FROM homepage_sections WHERE enabled=1 ORDER BY position,id')->fetchAll();
        foreach ($rows as &$row) {
            $row['content'] = json_decode((string) $row['content_json'], true) ?: [];
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function page(string $slug): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM content_pages WHERE slug=:slug AND status='published'");
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();
        if (is_array($row)) {
            $row['body_html'] = $this->purifier->purify((string) $row['body_html']);
        }
        return is_array($row) ? $row : null;
    }
}
