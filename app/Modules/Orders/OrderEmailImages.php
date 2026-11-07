<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Shared\Config\Config;

/** Small JPEG derivatives for email clients that cannot display catalog WebP files. */
final class OrderEmailImages
{
    public function __construct(private readonly Config $config, private readonly string $root) {}

    public function productUrl(?string $publicPath): ?string
    {
        if (!$publicPath || str_contains($publicPath, '..') || str_contains($publicPath, ':')) {
            return null;
        }
        $public = realpath($this->root . '/public');
        $source = realpath($this->root . '/public/' . ltrim($publicPath, '/'));
        if (!$public || !$source || !str_starts_with(str_replace('\\', '/', $source), str_replace('\\', '/', $public) . '/') || !is_file($source)) {
            return null;
        }
        $size = @getimagesize($source);
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 40000000) {
            return null;
        }
        $relative = '/media/email/' . hash('sha256', $publicPath . '|' . filemtime($source) . '|' . filesize($source)) . '.jpg';
        $target = $public . $relative;
        if (!is_file($target)) {
            $image = match ($size[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                IMAGETYPE_PNG => @imagecreatefrompng($source),
                IMAGETYPE_WEBP => @imagecreatefromwebp($source),
                IMAGETYPE_GIF => @imagecreatefromgif($source),
                default => false,
            };
            if (!$image) { return null; }
            $canvas = imagecreatetruecolor(240, 288);
            $background = imagecolorallocate($canvas, 250, 245, 240);
            imagefill($canvas, 0, 0, $background);
            $scale = min(240 / $size[0], 288 / $size[1]);
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
            imagecopyresampled($canvas, $image, (int) ((240 - $width) / 2), (int) ((288 - $height) / 2), 0, 0, $width, $height, $size[0], $size[1]);
            $directory = dirname($target);
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) { return null; }
            $temporary = tempnam($directory, '.email-');
            if ($temporary === false) { return null; }
            if (!imagejpeg($canvas, $temporary, 88) || !@rename($temporary, $target)) {
                @unlink($temporary);
                return null;
            }
        }
        return $this->config->rootUrl() . $relative;
    }
}
