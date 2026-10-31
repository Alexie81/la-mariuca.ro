<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Media\MediaService;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

final class MediaTest extends DatabaseTestCase
{
    public function testImageIsDecodedAndReencodedOutsidePrivateOriginal(): void
    {
        $mediaRoot = sys_get_temp_dir() . '/la-mariuca-media-' . bin2hex(random_bytes(6));
        mkdir($mediaRoot, 0770, true);
        $source = $mediaRoot . '/upload.png';
        $image = imagecreatetruecolor(20, 10);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 120, 120));
        imagepng($image, $source);
        imagedestroy($image);
        try {
            $service = new MediaService($this->pdo, $mediaRoot);
            $id = $service->upload(new UploadedFile($source, 'photo.php.png', 'image/png', filesize($source), UPLOAD_ERR_OK), 'Imagine de test');
            $row = $this->pdo->query('SELECT * FROM media WHERE id=' . $id)->fetch();
            self::assertStringEndsWith('.bin', $row['storage_path']);
            self::assertMatchesRegularExpression('/\.(webp|jpg)$/', $row['public_path']);
            self::assertFileExists($mediaRoot . '/public/' . $row['public_path']);
            self::assertSame('Imagine de test', $row['alt_text']);
            self::assertSame($row['public_path'], $service->find($id)['public_path']);
            self::assertNull($service->find($id + 1000));
        } finally {
            $this->removeDirectory($mediaRoot);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
