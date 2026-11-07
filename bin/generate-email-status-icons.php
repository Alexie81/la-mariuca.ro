<?php

declare(strict_types=1);

// Code-drawn, high-density PNGs keep the status marks crisp in Gmail and Outlook.
// Filled strokes avoid GD's antialiased diagonal-line thickness inconsistency.
const STATUS_ICON_SCALE = 8;
const STATUS_ICON_SIZE = 56;

function statusColor(GdImage $image, string $hex): int
{
    return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

function statusCircle(GdImage $image, float $x, float $y, float $radius, int $color): void
{
    imagefilledellipse($image, (int) round($x * STATUS_ICON_SCALE), (int) round($y * STATUS_ICON_SCALE),
        (int) round($radius * 2 * STATUS_ICON_SCALE), (int) round($radius * 2 * STATUS_ICON_SCALE), $color);
}

/** @param list<array{float|int,float|int}> $points */
function statusStroke(GdImage $image, array $points, float $width, int $color): void
{
    foreach ($points as $point) {
        statusCircle($image, $point[0], $point[1], $width / 2, $color);
    }
    for ($i = 1; $i < count($points); ++$i) {
        [$x1, $y1] = $points[$i - 1]; [$x2, $y2] = $points[$i];
        $length = hypot($x2 - $x1, $y2 - $y1);
        if ($length === 0.0) { continue; }
        $dx = ($y2 - $y1) / $length * $width / 2;
        $dy = ($x2 - $x1) / $length * $width / 2;
        $polygon = [$x1 + $dx, $y1 - $dy, $x2 + $dx, $y2 - $dy, $x2 - $dx, $y2 + $dy, $x1 - $dx, $y1 + $dy];
        imagefilledpolygon($image, array_map(static fn ($v): int => (int) round($v * STATUS_ICON_SCALE), $polygon), $color);
    }
}

foreach (['confirmed', 'received', 'review'] as $kind) {
    $size = STATUS_ICON_SIZE * STATUS_ICON_SCALE;
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
    imagealphablending($image, true);
    $review = $kind === 'review';
    $ink = statusColor($image, $review ? '9C6B32' : '207552');
    $ring = statusColor($image, $review ? 'D5B788' : '78B99B');
    $white = statusColor($image, 'FFFFFF');
    statusCircle($image, 28, 28, 27.5, statusColor($image, $review ? 'FAF0DF' : 'E6F4EC'));
    statusCircle($image, 28, 28.7, 19.6, statusColor($image, $review ? 'EDDFC8' : 'CEE7D9'));
    statusCircle($image, 28, 27.8, 19, $ring);
    statusCircle($image, 28, 27.8, 17.7, statusColor($image, $review ? 'FFFBF4' : 'F6FCF8'));
    if ($kind === 'confirmed') {
        statusStroke($image, [[17.5, 28], [24.5, 35], [38, 21]], 3.7, $ink);
    } elseif ($kind === 'received') {
        statusStroke($image, [[19, 24], [18, 36], [34, 36], [33, 24], [19, 24]], 2.1, $ink);
        $handle = [];
        for ($angle = 180; $angle <= 360; $angle += 10) {
            $handle[] = [26 + 4.3 * cos(deg2rad($angle)), 23 + 5 * sin(deg2rad($angle))];
        }
        statusStroke($image, $handle, 2.1, $ink);
        statusCircle($image, 36, 35, 7.6, $white);
        statusCircle($image, 36, 35, 6.3, $ink);
        statusStroke($image, [[33.2, 35], [35.2, 37], [39.1, 32.9]], 1.8, $white);
    } else {
        statusStroke($image, [[28, 17.5], [28, 28], [35, 32]], 2.7, $ink);
    }
    $output = imagecreatetruecolor(STATUS_ICON_SIZE * 3, STATUS_ICON_SIZE * 3);
    imagealphablending($output, false);
    imagesavealpha($output, true);
    imagecopyresampled($output, $image, 0, 0, 0, 0, STATUS_ICON_SIZE * 3, STATUS_ICON_SIZE * 3, $size, $size);
    imagepng($output, dirname(__DIR__) . '/public/assets/images/email-status-' . $kind . '.png', 9);
}
echo "Generated three email status icons.\n";
