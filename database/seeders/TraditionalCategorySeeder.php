<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;
use RuntimeException;

final class TraditionalCategorySeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $now = gmdate('c');
        $definitions = [
            [
                'slug' => 'costume-traditionale',
                'name' => 'Costume tradiționale',
                'parent' => null,
                'position' => 4,
                'description' => 'Descoperă costume tradiționale românești pentru copii, cu broderii delicate și croieli potrivite celor mici.',
                'seo' => 'Costume tradiționale pentru copii',
                'meta' => 'Costume tradiționale românești pentru fetițe și băieței, alese cu drag de La Măriuca Boutique.',
                'filename' => 'costume-traditionale-editorial-2026.webp',
                'alt' => 'Costume tradiționale românești pentru fetițe și băieței, cu broderii bordo',
            ],
            [
                'slug' => 'costume-traditionale-fetite',
                'name' => 'Fetițe',
                'parent' => 'costume-traditionale',
                'position' => 1,
                'description' => 'Ii, fote și costume tradiționale pentru fetițe, în nuanțe calde și cu broderii atent lucrate.',
                'seo' => 'Costume tradiționale pentru fetițe',
                'meta' => 'Descoperă costume tradiționale românești pentru fetițe, cu ie brodată, fotă și brâu.',
                'filename' => 'costume-traditionale-fetite-editorial-2026.webp',
                'alt' => 'Costum tradițional românesc pentru fetițe, cu ie brodată și fotă bordo',
            ],
            [
                'slug' => 'costume-traditionale-baietei',
                'name' => 'Băieței',
                'parent' => 'costume-traditionale',
                'position' => 2,
                'description' => 'Ii, veste și costume tradiționale pentru băieței, confortabile și bogate în detalii autentice.',
                'seo' => 'Costume tradiționale pentru băieței',
                'meta' => 'Descoperă costume tradiționale românești pentru băieței, cu ie, vestă și brâu brodat.',
                'filename' => 'costume-traditionale-baietei-editorial-2026.webp',
                'alt' => 'Costum tradițional românesc pentru băieței, cu ie, vestă și brâu brodat',
            ],
        ];

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $categoryIds = [];
            foreach ($definitions as $definition) {
                $parentId = $definition['parent'] === null ? null : ($categoryIds[$definition['parent']] ?? null);
                if ($definition['parent'] !== null && $parentId === null) {
                    throw new RuntimeException('Categoria părinte pentru costumele tradiționale nu a putut fi creată.');
                }

                $categoryId = $this->categoryId((string) $definition['slug']);
                if ($categoryId === null) {
                    $statement = $this->pdo->prepare("INSERT INTO categories (parent_id,name,slug,description,position,status,visibility,seo_title,meta_description,indexable,published_at,created_at,updated_at) VALUES (:parent,:name,:slug,:description,:position,'published','public',:seo,:meta,1,:now,:now,:now)");
                    $statement->execute([
                        'parent' => $parentId,
                        'name' => $definition['name'],
                        'slug' => $definition['slug'],
                        'description' => $definition['description'],
                        'position' => $definition['position'],
                        'seo' => $definition['seo'],
                        'meta' => $definition['meta'],
                        'now' => $now,
                    ]);
                    $categoryId = (int) $this->pdo->lastInsertId();
                } else {
                    $statement = $this->pdo->prepare("UPDATE categories SET parent_id=:parent,name=:name,description=:description,position=:position,status='published',visibility='public',seo_title=:seo,meta_description=:meta,indexable=1,published_at=COALESCE(published_at,:now),updated_at=:now WHERE id=:id");
                    $statement->execute([
                        'parent' => $parentId,
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'position' => $definition['position'],
                        'seo' => $definition['seo'],
                        'meta' => $definition['meta'],
                        'now' => $now,
                        'id' => $categoryId,
                    ]);
                }
                $categoryIds[$definition['slug']] = $categoryId;

                $filename = (string) $definition['filename'];
                $publicPath = 'assets/images/categories/' . $filename;
                $absolutePath = $projectRoot . '/public/' . $publicPath;
                $imageInfo = is_file($absolutePath) ? @getimagesize($absolutePath) : false;
                if (!is_array($imageInfo)) {
                    throw new RuntimeException('Imaginea categoriei lipsește sau este invalidă: ' . $filename);
                }

                $storagePath = 'seeded/category-images/' . $filename;
                $media = $this->pdo->prepare('SELECT id FROM media WHERE storage_path = :storage LIMIT 1');
                $media->execute(['storage' => $storagePath]);
                $mediaId = $media->fetchColumn();
                $mediaParams = [
                    'name' => $filename,
                    'storage' => $storagePath,
                    'public' => $publicPath,
                    'mime' => 'image/webp',
                    'width' => (int) $imageInfo[0],
                    'height' => (int) $imageInfo[1],
                    'size' => (int) (filesize($absolutePath) ?: 0),
                    'alt' => $definition['alt'],
                    'now' => $now,
                ];
                if ($mediaId === false) {
                    $statement = $this->pdo->prepare('INSERT INTO media (original_name,storage_path,public_path,mime_type,width,height,size_bytes,alt_text,created_at) VALUES (:name,:storage,:public,:mime,:width,:height,:size,:alt,:now)');
                    $statement->execute($mediaParams);
                    $mediaId = (int) $this->pdo->lastInsertId();
                } else {
                    $statement = $this->pdo->prepare('UPDATE media SET original_name=:name,public_path=:public,mime_type=:mime,width=:width,height=:height,size_bytes=:size,alt_text=:alt WHERE id=:id');
                    $statement->execute([
                        'name' => $mediaParams['name'],
                        'public' => $mediaParams['public'],
                        'mime' => $mediaParams['mime'],
                        'width' => $mediaParams['width'],
                        'height' => $mediaParams['height'],
                        'size' => $mediaParams['size'],
                        'alt' => $mediaParams['alt'],
                        'id' => (int) $mediaId,
                    ]);
                }

                $this->pdo->prepare('UPDATE categories SET image_media_id = :media_id WHERE id = :category_id')->execute([
                    'media_id' => (int) $mediaId,
                    'category_id' => $categoryId,
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function categoryId(string $slug): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
