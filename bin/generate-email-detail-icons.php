<?php

declare(strict_types=1);

// Code-drawn PNGs: large, rounded glyphs with consistent filled strokes.
// Raster assets retain their color and geometry in email clients without SVG support.
const DETAIL_SCALE = 8;
const DETAIL_SIZE = 42;

function detailColor(GdImage $image, string $hex): int
{
    return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

function detailCircle(GdImage $image, float $x, float $y, float $radius, int $color): void
{
    imagefilledellipse($image, (int) round($x * DETAIL_SCALE), (int) round($y * DETAIL_SCALE),
        (int) round($radius * 2 * DETAIL_SCALE), (int) round($radius * 2 * DETAIL_SCALE), $color);
}

/** @param list<array{float|int,float|int}> $points */
function detailStroke(GdImage $image, array $points, float $width, int $color): void
{
    foreach ($points as [$x, $y]) { detailCircle($image, $x, $y, $width / 2, $color); }
    for ($i = 1; $i < count($points); ++$i) {
        [$x1, $y1] = $points[$i - 1]; [$x2, $y2] = $points[$i];
        $length = hypot($x2 - $x1, $y2 - $y1);
        if ($length === 0.0) { continue; }
        $dx = ($y2 - $y1) / $length * $width / 2;
        $dy = ($x2 - $x1) / $length * $width / 2;
        imagefilledpolygon($image, array_map(static fn ($v): int => (int) round($v * DETAIL_SCALE),
            [$x1 + $dx, $y1 - $dy, $x2 + $dx, $y2 - $dy, $x2 - $dx, $y2 + $dy, $x1 - $dx, $y1 + $dy]), $color);
    }
}

function detailRoundRect(GdImage $image, float $x, float $y, float $width, float $height, float $radius, int $color): void
{
    imagefilledrectangle($image, (int) round(($x + $radius) * DETAIL_SCALE), (int) round($y * DETAIL_SCALE),
        (int) round(($x + $width - $radius) * DETAIL_SCALE), (int) round(($y + $height) * DETAIL_SCALE), $color);
    imagefilledrectangle($image, (int) round($x * DETAIL_SCALE), (int) round(($y + $radius) * DETAIL_SCALE),
        (int) round(($x + $width) * DETAIL_SCALE), (int) round(($y + $height - $radius) * DETAIL_SCALE), $color);
    foreach ([[$x + $radius, $y + $radius], [$x + $width - $radius, $y + $radius],
        [$x + $radius, $y + $height - $radius], [$x + $width - $radius, $y + $height - $radius]] as [$cx, $cy]) {
        detailCircle($image, $cx, $cy, $radius, $color);
    }
}

foreach (['receipt', 'cash', 'card', 'truck'] as $kind) {
    $size = DETAIL_SIZE * DETAIL_SCALE;
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
    imagealphablending($image, true);
    $ink = detailColor($image, '92304E');
    $paper = detailColor($image, 'FFFCFD');
    $tint = detailColor($image, 'FFF1F5');
    detailRoundRect($image, .5, .5, 41, 41, 13, detailColor($image, 'EAC4D0'));
    detailRoundRect($image, 1.2, 1.2, 39.6, 39.6, 12.3, $tint);
    if ($kind === 'receipt') {
        detailStroke($image, [[13, 32], [13, 10], [16, 8], [19, 10], [22, 8], [25, 10], [29, 8],
            [29, 32], [25, 30], [22, 32], [19, 30], [16, 32], [13, 30]], 2.1, $ink);
        detailStroke($image, [[17, 16], [25, 16]], 2.1, $ink);
        detailStroke($image, [[17, 21], [25, 21]], 2.1, $ink);
        detailStroke($image, [[17, 26], [22, 26]], 2.1, $ink);
    } elseif ($kind === 'card') {
        detailRoundRect($image, 7, 11, 28, 21, 4, $ink);
        detailRoundRect($image, 9.2, 13.2, 23.6, 16.6, 2, $paper);
        detailRoundRect($image, 8, 16, 26, 3, 0, $ink);
        detailRoundRect($image, 12, 23, 6, 3.5, 1, $ink);
        detailStroke($image, [[26, 25], [29, 25]], 2.1, $ink);
    } elseif ($kind === 'cash') {
        detailRoundRect($image, 6.5, 11.5, 29, 19, 3.5, $ink);
        detailRoundRect($image, 8.7, 13.7, 24.6, 14.6, 1.5, $paper);
        detailCircle($image, 21, 21, 5.1, $ink);
        detailCircle($image, 21, 21, 3, $tint);
        detailStroke($image, [[12, 18], [13, 18]], 2.2, $ink);
        detailStroke($image, [[29, 24], [30, 24]], 2.2, $ink);
    } else {
        detailRoundRect($image, 6, 11, 19, 18, 2.4, $ink);
        detailRoundRect($image, 8.2, 13.2, 14.6, 13.6, 1, $paper);
        detailStroke($image, [[24, 17], [29.5, 17], [35.5, 23.5], [35.5, 28], [24, 28]], 2.2, $ink);
        detailStroke($image, [[28, 18], [28, 23], [34.5, 23]], 2.1, $ink);
        foreach ([12.5, 29.5] as $wheel) {
            detailCircle($image, $wheel, 29, 4.1, $paper);
            detailCircle($image, $wheel, 29, 3.1, $ink);
            detailCircle($image, $wheel, 29, 1, $paper);
        }
    }
    $output = imagecreatetruecolor(DETAIL_SIZE * 4, DETAIL_SIZE * 4);
    imagealphablending($output, false);
    imagesavealpha($output, true);
    imagecopyresampled($output, $image, 0, 0, 0, 0, DETAIL_SIZE * 4, DETAIL_SIZE * 4, $size, $size);
    imagepng($output, dirname(__DIR__) . '/public/assets/images/email-order-' . $kind . '-v2.png', 9);
    imagedestroy($output);
    imagedestroy($image);
}

echo "Generated four high-contrast email detail icons.\n";
