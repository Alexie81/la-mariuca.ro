<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class CategoryAdminTest extends DatabaseTestCase
{
    private const PASSWORD = 'Category-admin-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $statement = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('category.admin@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $statement->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->seed();
        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'category.admin@example.test',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
    }

    public function testGuidedCategoryCrmTreeCreateEditValidationAndDeleteFlow(): void
    {
        $page = (string) $this->get('/admin/categorii/')->getBody();
        self::assertStringContainsString('Arborele categoriilor', $page);
        self::assertStringContainsString('class="admin-categories-table"', $page);
        self::assertStringContainsString('href="/admin/categorii/" aria-current="page"', $page);
        self::assertStringContainsString('data-category-depth="1"', $page);
        self::assertStringContainsString('data-category-depth="2"', $page);
        self::assertStringContainsString('assets/images/categories/', $page);
        self::assertStringContainsString('admin-category-slug', $page);
        self::assertStringContainsString('admin-category-tree-branches', $page);
        self::assertStringContainsString('Subcategorie în', $page);
        self::assertStringNotContainsString('>N1<', $page);
        self::assertStringNotContainsString('<code>#1</code>', $page);
        self::assertStringNotContainsString('ID · Nivel', $page);
        self::assertStringNotContainsString('Traseu în arbore', $page);
        self::assertStringNotContainsString('admin-categories-table__placeholder', $page);
        self::assertStringContainsString('data-admin-category-editor-dialog', $page);
        self::assertStringContainsString('data-admin-category-delete-dialog', $page);
        $productsPosition = strpos($page, 'href="/admin/produse/"');
        $categoriesPosition = strpos($page, 'href="/admin/categorii/"');
        $stockPosition = strpos($page, 'Stoc și achiziții');
        self::assertIsInt($productsPosition);
        self::assertIsInt($categoriesPosition);
        self::assertIsInt($stockPosition);
        self::assertTrue($productsPosition < $categoriesPosition && $categoriesPosition < $stockPosition);

        $modal = (string) $this->get('/admin/categorii/noua/modal/')->getBody();
        self::assertStringContainsString('data-admin-category-form', $modal);
        self::assertStringContainsString('data-name-check-url="/admin/categorii/verifica-nume/"', $modal);
        self::assertStringContainsString('data-slug-check-url="/admin/categorii/verifica-slug/"', $modal);
        self::assertStringContainsString('name="parent_id"', $modal);
        self::assertStringContainsString('class="admin-category-picker admin-category-parent-picker has-selection"', $modal);
        self::assertStringContainsString('role="radiogroup"', $modal);
        self::assertStringContainsString('type="radio" name="parent_id"', $modal);
        self::assertStringContainsString('data-admin-category-parent-search-clear', $modal);
        self::assertStringContainsString('data-admin-category-parent-close', $modal);
        self::assertStringContainsString('data-admin-category-parent-done', $modal);
        self::assertStringContainsString('O singură alegere', $modal);
        self::assertStringNotContainsString('<small>#', $modal);
        self::assertStringContainsString('name="position"', $modal);
        self::assertStringContainsString('name="image"', $modal);
        self::assertStringContainsString('enctype="multipart/form-data"', $modal);
        self::assertStringContainsString('name="description" rows="5" maxlength="100"', $modal);
        self::assertStringContainsString('name="seo_title"', $modal);
        self::assertStringContainsString('name="meta_description"', $modal);
        self::assertStringContainsString('data-slug-auto="true"', $modal);

        $existingName = $this->json($this->get('/admin/categorii/verifica-nume/?nume=Feti%C8%9Be'));
        self::assertFalse($existingName['available']);
        $availableName = $this->json($this->get('/admin/categorii/verifica-nume/?nume=Colec%C8%9Bii%20de%20var%C4%83'));
        self::assertTrue($availableName['available']);
        $slugCheck = $this->json($this->get('/admin/categorii/verifica-slug/?slug=Colec%C8%9Bii%20de%20Var%C4%83'));
        self::assertTrue($slugCheck['available']);
        self::assertSame('colectii-de-vara', $slugCheck['slug']);

        $mediaId = (int) $this->pdo->query('SELECT id FROM media ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $mediaId);
        $create = $this->request('POST', '/admin/categorii/noua/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Colecții de vară',
            'slug' => '',
            'parent_id' => '',
            'position' => '33',
            'description' => 'Ținute luminoase pentru zilele calde.',
            'image_media_id' => (string) $mediaId,
            'image_alt' => 'Colecții de vară pentru copii',
            'seo_title' => '',
            'meta_description' => '',
            'status' => 'published',
            'visibility' => 'public',
            'indexable' => 'on',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $create->getStatusCode());
        $payload = $this->json($create);
        self::assertTrue($payload['ok']);
        $rootId = (int) $payload['id'];
        $root = $this->category($rootId);
        self::assertSame('colectii-de-vara', $root['slug']);
        self::assertNull($root['parent_id']);
        self::assertSame(33, (int) $root['position']);
        self::assertSame('Colecții de vară', $root['seo_title']);
        self::assertSame('Colecții de vară', $root['meta_description']);
        self::assertSame($mediaId, (int) $root['image_media_id']);
        self::assertSame('Colecții de vară pentru copii', $this->pdo->query('SELECT alt_text FROM media WHERE id=' . $mediaId)->fetchColumn());

        $duplicate = $this->request('POST', '/admin/categorii/noua/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Colecții de vară',
            'slug' => 'alta-colectie',
            'parent_id' => '',
            'description' => '',
            'status' => 'draft',
            'visibility' => 'public',
        ], ['Accept' => 'application/json']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('deja o categorie', (string) $duplicate->getBody());

        $childCreate = $this->request('POST', '/admin/categorii/noua/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Rochii vaporoase',
            'slug' => '',
            'parent_id' => (string) $rootId,
            'position' => '88',
            'description' => 'Rochii lejere pentru vară.',
            'status' => 'published',
            'visibility' => 'public',
            'indexable' => 'on',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $childCreate->getStatusCode());
        $childId = (int) $this->json($childCreate)['id'];
        $child = $this->category($childId);
        self::assertSame($rootId, (int) $child['parent_id']);
        self::assertSame(0, (int) $child['position'], 'Numărul de ordine trebuie ignorat pentru o subcategorie.');

        $tree = (string) $this->get('/admin/categorii/?cautare=Rochii%20vaporoase')->getBody();
        self::assertStringContainsString('Colecții de vară › Rochii vaporoase', $tree);
        self::assertStringContainsString('data-category-depth="1"', $tree);
        $editModal = (string) $this->get('/admin/categorii/' . $rootId . '/editare/modal/')->getBody();
        self::assertStringNotContainsString('ID #', $editModal);
        self::assertStringNotContainsString('ID-ul categoriei', $editModal);
        self::assertStringNotContainsString('name="parent_id" value="' . $rootId . '"', $editModal);
        self::assertStringNotContainsString('name="parent_id" value="' . $childId . '"', $editModal);

        $edit = $this->request('POST', '/admin/categorii/' . $rootId . '/editare/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Colecții de vară',
            'slug' => 'vara-copii',
            'parent_id' => '',
            'position' => '44',
            'description' => 'Selecție nouă pentru sezonul cald.',
            'image_media_id' => (string) $mediaId,
            'image_alt' => 'Ținute de vară pentru copii',
            'seo_title' => 'Haine de vară pentru copii',
            'meta_description' => 'Descoperă colecția de vară pentru cei mici.',
            'status' => 'published',
            'visibility' => 'public',
            'indexable' => 'on',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $edit->getStatusCode());
        $edited = $this->category($rootId);
        self::assertSame('vara-copii', $edited['slug']);
        self::assertSame(44, (int) $edited['position']);
        self::assertSame('Haine de vară pentru copii', $edited['seo_title']);
        self::assertSame('Descoperă colecția de vară pentru cei mici.', $edited['meta_description']);
        self::assertSame('Ținute de vară pentru copii', $this->pdo->query('SELECT alt_text FROM media WHERE id=' . $mediaId)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM slug_redirects WHERE entity_type='category' AND old_slug='colectii-de-vara' AND new_slug='vara-copii'")->fetchColumn());

        $tooLong = $this->request('POST', '/admin/categorii/' . $rootId . '/editare/', [
            '_csrf' => $_SESSION['_csrf'],
            'name' => 'Colecții de vară',
            'slug' => 'vara-copii',
            'parent_id' => '',
            'description' => str_repeat('a', 101),
            'status' => 'published',
            'visibility' => 'public',
        ], ['Accept' => 'application/json']);
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertStringContainsString('maximum 100', (string) $tooLong->getBody());

        $blockedDelete = $this->post('/admin/categorii/' . $rootId . '/sterge/', ['_csrf' => $_SESSION['_csrf']]);
        self::assertSame(303, $blockedDelete->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM categories WHERE id=' . $rootId)->fetchColumn());

        self::assertSame(303, $this->post('/admin/categorii/' . $childId . '/sterge/', ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories WHERE id=' . $childId)->fetchColumn());
        self::assertSame(303, $this->post('/admin/categorii/' . $rootId . '/sterge/', ['_csrf' => $_SESSION['_csrf']])->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories WHERE id=' . $rootId)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM slug_redirects WHERE entity_type='category' AND (old_slug='vara-copii' OR new_slug='vara-copii')")->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function category(int $id): array
    {
        $row = $this->pdo->query('SELECT * FROM categories WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,mixed> $data */
    private function post(string $path, array $data): ResponseInterface
    {
        return $this->request('POST', $path, $data);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.95'])
            ->withHeader('User-Agent', 'LaMariuca-CategoryAdmin-Test/1.0');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
