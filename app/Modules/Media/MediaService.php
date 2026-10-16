<?php

declare(strict_types=1);

namespace App\Modules\Media;

use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class MediaService
{
    private const MAX_BYTES = 8_388_608;
    private const MAX_PIXELS = 12_000_000;

    public function __construct(private readonly PDO $pdo, private readonly string $root)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM media ORDER BY id DESC')->fetchAll();
    }

    public function upload(UploadedFileInterface $file, string $altText): int
    {
        if ($file->getError() !== UPLOAD_ERR_OK || $file->getSize() === null || $file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('Fișierul trebuie să fie o imagine validă de maximum 8 MB.');
        }
        $bytes = (string) $file->getStream();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Sunt acceptate numai imagini JPG, PNG sau WebP.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > self::MAX_PIXELS) {
            throw new RuntimeException('Imaginea nu poate fi decodată sau depășește 12 megapixeli.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Conținutul imaginii este corupt.');
        }

        $token = bin2hex(random_bytes(16));
        $privateDirectory = $this->root . '/storage/private/media/originals';
        $relativeDirectory = 'media/' . gmdate('Y/m');
        $publicDirectory = $this->root . '/public/' . $relativeDirectory;
        foreach ([$privateDirectory, $publicDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                imagedestroy($image);
                throw new RuntimeException('Directorul media nu poate fi creat.');
            }
        }
        $privatePath = $privateDirectory . '/' . $token . '.bin';
        if (file_put_contents($privatePath, $bytes, LOCK_EX) === false) {
            imagedestroy($image);
            throw new RuntimeException('Originalul nu poate fi salvat în storage privat.');
        }

        $sourceWidth = (int) $info[0];
        $sourceHeight = (int) $info[1];
        $largestWidth = min(1600, $sourceWidth);
        $largestHeight = max(1, (int) round($sourceHeight * ($largestWidth / $sourceWidth)));
        $derivative = $largestWidth === $sourceWidth ? $image : imagescale($image, $largestWidth, $largestHeight, IMG_BICUBIC_FIXED);
        if ($derivative === false) {
            imagedestroy($image);
            throw new RuntimeException('Derivatul imaginii nu poate fi creat.');
        }
        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $publicPath = $relativeDirectory . '/' . $token . '.' . $extension;
        $absolutePublicPath = $this->root . '/public/' . $publicPath;
        $written = $extension === 'webp' ? imagewebp($derivative, $absolutePublicPath, 84) : imagejpeg($derivative, $absolutePublicPath, 88);
        if ($derivative !== $image) {
            imagedestroy($derivative);
        }
        imagedestroy($image);
        if (!$written) {
            @unlink($privatePath);
            throw new RuntimeException('Derivatul public nu poate fi salvat.');
        }

        $statement = $this->pdo->prepare('INSERT INTO media (original_name,storage_path,public_path,mime_type,width,height,size_bytes,alt_text,created_at) VALUES (:name,:storage,:public,:mime,:width,:height,:size,:alt,:now)');
        $statement->execute([
            'name' => mb_substr(basename((string) $file->getClientFilename()), 0, 255),
            'storage' => 'storage/private/media/originals/' . $token . '.bin', 'public' => $publicPath,
            'mime' => $mime, 'width' => $largestWidth, 'height' => $largestHeight,
            'size' => filesize($absolutePublicPath) ?: 0, 'alt' => mb_substr(trim($altText), 0, 500), 'now' => gmdate('c'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
