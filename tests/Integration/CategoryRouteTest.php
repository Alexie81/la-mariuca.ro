<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CategoryRouteTest extends DatabaseTestCase
{
    public function testFlatSubcategoryUrlRedirectsPermanentlyToItsCanonicalHierarchy(): void
    {
        $this->seed();

        $response = $this->get('/categorie/rochite-ocazie/?sortare=price-asc');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(
            '/categorie/fetite/rochite/rochite-ocazie/?sortare=price-asc',
            $response->getHeaderLine('Location')
        );
    }

    public function testCanonicalCategoryUrlContainsFullBreadcrumbAndCanonicalMetadata(): void
    {
        $this->seed();

        $response = $this->get('/categorie/fetite/rochite/rochite-ocazie/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost/categorie/fetite/rochite/rochite-ocazie/">',
            $body
        );
        self::assertStringContainsString('href="/categorie/fetite/">Fetițe</a>', $body);
        self::assertStringContainsString('href="/categorie/fetite/rochite/">Rochițe</a>', $body);
        self::assertStringContainsString('<span aria-current="page">Rochițe de ocazie</span>', $body);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $body);
    }

    public function testInventedCategoryAncestryReturnsNotFound(): void
    {
        $this->seed();

        $response = $this->get('/categorie/baieti/rochite/rochite-ocazie/');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testFormerSlugInsideAValidHierarchyRedirectsToTheNewCanonicalPath(): void
    {
        $this->seed();
        $now = gmdate('c');
        $this->pdo->exec("UPDATE categories SET slug = 'rochite-eveniment' WHERE slug = 'rochite-ocazie'");
        $statement = $this->pdo->prepare('INSERT INTO slug_redirects (entity_type, old_slug, new_slug, created_at) VALUES (:type, :old, :new, :created)');
        $statement->execute([
            'type' => 'category',
            'old' => 'rochite-ocazie',
            'new' => 'rochite-eveniment',
            'created' => $now,
        ]);

        $response = $this->get('/categorie/fetite/rochite/rochite-ocazie/');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(
            '/categorie/fetite/rochite/rochite-eveniment/',
            $response->getHeaderLine('Location')
        );
    }

    public function testSitemapUsesHierarchicalCategoryUrls(): void
    {
        $this->seed();

        $body = (string) $this->get('/sitemap.xml')->getBody();

        self::assertStringContainsString(
            '<loc>http://localhost/categorie/fetite/rochite/rochite-ocazie/</loc>',
            $body
        );
        self::assertStringNotContainsString(
            '<loc>http://localhost/categorie/rochite-ocazie/</loc>',
            $body
        );
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }
}
