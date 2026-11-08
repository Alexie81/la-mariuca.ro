<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use Psr\Http\Message\UploadedFileInterface;

final class CompanyStampStorage
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_PIXELS = 12_000_000;

    public function __construct(private readonly string $root)
    {
    }

    public function store(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK || $file->getSize() === null || $file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            throw new CompanyValidationException(['stamp_file'=>'Alege o imagine PNG, JPG sau WebP de maximum 2 MB.']);
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->read(self::MAX_BYTES + 1);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new CompanyValidationException(['stamp_file'=>'Imaginea trebuie să aibă maximum 2 MB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
            throw new CompanyValidationException(['stamp_file'=>'Sunt acceptate numai imagini PNG, JPG sau WebP.']);
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new CompanyValidationException(['stamp_file'=>'Imaginea este invalidă sau prea mare. Folosește maximum 6.000 px pe latură și 12 megapixeli.']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new CompanyValidationException(['stamp_file'=>'Imaginea nu poate fi citită. Încearcă alt fișier.']);
        }
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            imagedestroy($image);
            throw new \RuntimeException('Ștampila nu poate fi salvată momentan.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $scale = min(1, 1600 / max($width, $height));
        $output = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 255, 255, 255, 127);
        imagefill($output, 0, 0, $transparent);
        imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), $width, $height);
        $name = bin2hex(random_bytes(16)) . '.png';
        $path = $directory . '/' . $name;
        $saved = imagepng($output, $path, 6);
        imagedestroy($output);
        imagedestroy($image);
        if (!$saved) {
            @unlink($path);
            throw new \RuntimeException('Ștampila nu poate fi salvată momentan.');
        }
        @chmod($path, 0660);
        return $name;
    }

    public function path(?string $name): ?string
    {
        if ($name === null || preg_match('/^[a-f0-9]{32}\.png$/D', $name) !== 1) {
            return null;
        }
        $path = $this->directory() . '/' . $name;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    public function remove(?string $name): void
    {
        $path = $this->path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function directory(): string
    {
        return $this->root . '/storage/private/company-stamps';
    }
}
