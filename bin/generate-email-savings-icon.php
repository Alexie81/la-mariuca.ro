<?php

declare(strict_types=1);

// Original code-drawn artwork. Run: php bin/generate-email-savings-icon.php
// Four pixels per displayed pixel keep the 28px email icon crisp without SVG support.
const SAVINGS_ICON_SIZE = 112;
const SAVINGS_ICON_FRAMES = 12;

function savingsIconColor(GdImage $image, string $hex): int
{
    return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

/** @param list<array{float|int,float|int}> $points */
function savingsIconPolygon(GdImage $image, array $points, int $color): void
{
    $vertices = [];
    foreach ($points as [$x, $y]) {
        $vertices[] = (int) round($x * SAVINGS_ICON_SIZE / 100);
        $vertices[] = (int) round($y * SAVINGS_ICON_SIZE / 100);
    }
    imagefilledpolygon($image, $vertices, $color);
}

function savingsIconCircle(GdImage $image, float $x, float $y, float $radius, int $color): void
{
    $scale = SAVINGS_ICON_SIZE / 100;
    imagefilledellipse($image, (int) round($x * $scale), (int) round($y * $scale),
        (int) round($radius * 2 * $scale), (int) round($radius * 2 * $scale), $color);
}

/** @param list<array{float|int,float|int}> $points */
function savingsIconStroke(GdImage $image, array $points, float $width, int $color): void
{
    foreach ($points as [$x, $y]) {
        savingsIconCircle($image, $x, $y, $width / 2, $color);
    }
    for ($i = 1; $i < count($points); ++$i) {
        [$x1, $y1] = $points[$i - 1];
        [$x2, $y2] = $points[$i];
        $length = hypot($x2 - $x1, $y2 - $y1);
        if ($length === 0.0) {
            continue;
        }
        $dx = ($y2 - $y1) / $length * $width / 2;
        $dy = ($x2 - $x1) / $length * $width / 2;
        savingsIconPolygon($image, [[$x1 + $dx, $y1 - $dy], [$x2 + $dx, $y2 - $dy],
            [$x2 - $dx, $y2 + $dy], [$x1 - $dx, $y1 + $dy]], $color);
    }
}

function savingsIconFrame(float $motion): GdImage
{
    // Native palette drawing avoids opaque matte fringes on transparent GIF edges.
    $image = imagecreate(SAVINGS_ICON_SIZE, SAVINGS_ICON_SIZE);
    $transparent = savingsIconColor($image, 'FFFFFF');
    imagecolortransparent($image, $transparent);
    $rose = savingsIconColor($image, 'C96E7D');
    $pink = savingsIconColor($image, 'EAB2B2');
    $blush = savingsIconColor($image, 'F8D8CA');
    $gold = savingsIconColor($image, 'D4A34F');
    $lightGold = savingsIconColor($image, 'F1CF87');
    $green = savingsIconColor($image, '5B9A7C');
    $mint = savingsIconColor($image, '95C4A6');

    // A fixed popper stays legible in Outlook's first-frame-only rendering.
    savingsIconPolygon($image, [[18, 90], [34, 49], [60, 75]], $gold);
    savingsIconPolygon($image, [[19, 88], [34, 49], [45, 59]], $lightGold);
    savingsIconPolygon($image, [[22, 80], [25, 72], [36, 84], [29, 87]], $rose);
    savingsIconPolygon($image, [[29, 63], [32, 55], [52, 78], [44, 81]], $rose);
    savingsIconStroke($image, [[20, 85], [33, 52]], 1.8, $blush);

    $rim = [];
    $inside = [];
    for ($angle = 0; $angle <= 360; $angle += 10) {
        $radians = deg2rad($angle);
        $rim[] = [47 + 13.4 * cos($radians) - 4.2 * sin($radians), 62 + 13.4 * cos($radians) + 4.2 * sin($radians)];
        $inside[] = [47 + 11.5 * cos($radians) - 2.5 * sin($radians), 62 + 11.5 * cos($radians) + 2.5 * sin($radians)];
    }
    savingsIconPolygon($image, $rim, $pink);
    savingsIconPolygon($image, $inside, $rose);

    // Gentle outward drift, never a flash or a disappearing icon.
    $lift = 4 * $motion;
    $spread = 3 * $motion;
    savingsIconStroke($image, [[43, 48], [46, 42], [51, 39]], 2.6, $gold);
    savingsIconStroke($image, [[61, 58], [66, 53], [72, 51]], 2.7, $pink);

    $curl = [];
    for ($angle = 205; $angle <= 490; $angle += 12) {
        $radians = deg2rad($angle);
        $curl[] = [31 - $spread + 6.1 * cos($radians), 30 - $lift + 8.4 * sin($radians)];
    }
    savingsIconStroke($image, $curl, 3.2, $green);
    savingsIconStroke($image, [[60, 33 - $lift], [62 + $spread, 24 - $lift], [69 + $spread, 23 - $lift],
        [71 + $spread, 16 - $lift]], 3.1, $rose);

    $starX = 81 + $spread;
    $starY = 39 - $lift;
    savingsIconPolygon($image, [[$starX, $starY - 7], [$starX + 2, $starY - 2], [$starX + 7, $starY],
        [$starX + 2, $starY + 2], [$starX, $starY + 7], [$starX - 2, $starY + 2],
        [$starX - 7, $starY], [$starX - 2, $starY - 2]], $gold);
    savingsIconPolygon($image, [[49, 19 - $lift], [54, 21 - $lift], [52, 27 - $lift], [47, 25 - $lift]], $lightGold);
    savingsIconPolygon($image, [[74 + $spread, 66], [79 + $spread, 62], [83 + $spread, 67], [78 + $spread, 71]], $green);
    savingsIconStroke($image, [[89, 55 - $lift / 2], [93, 59 - $lift / 2]], 3.4, $pink);
    savingsIconCircle($image, 57 + $spread, 45 - $lift, 2.5, $mint);
    savingsIconCircle($image, 22 - $spread, 47 - $lift, 2.2, $pink);
    savingsIconCircle($image, 84 + $spread, 17 - $lift, 2.3, $green);
    return $image;
}

/** @return array{screen:string,palette:string,palette_bits:int,descriptor:string,data:string,transparent:int} */
function savingsIconGifParts(GdImage $image): array
{
    ob_start();
    imagegif($image);
    $gif = (string) ob_get_clean();
    $packed = ord($gif[10]);
    if (($packed & 0x80) === 0) {
        throw new RuntimeException('GD must produce a GIF with a color table.');
    }
    $paletteLength = 3 * (1 << (($packed & 7) + 1));
    $palette = substr($gif, 13, $paletteLength);
    $offset = 13 + $paletteLength;
    while (isset($gif[$offset]) && $gif[$offset] === "\x21") {
        $offset += 2; // Extension introducer and label.
        while (($length = ord($gif[$offset++])) !== 0) {
            $offset += $length;
        }
    }
    if (($gif[$offset] ?? '') !== "\x2C") {
        throw new RuntimeException('GD produced an unexpected GIF image block.');
    }
    $descriptor = substr($gif, $offset, 10);
    if ((ord($descriptor[9]) & 0x80) !== 0) {
        throw new RuntimeException('An unexpected local color table was found in the GD frame.');
    }
    $offset += 10;
    $dataStart = $offset++;
    while (($length = ord($gif[$offset++])) !== 0) {
        $offset += $length;
    }
    return ['screen' => substr($gif, 6, 7), 'palette' => $palette, 'palette_bits' => $packed & 7,
        'descriptor' => $descriptor, 'data' => substr($gif, $dataStart, $offset - $dataStart),
        'transparent' => imagecolortransparent($image)];
}

$animation = '';
for ($frame = 0; $frame < SAVINGS_ICON_FRAMES; ++$frame) {
    $motion = sin(M_PI * $frame / (SAVINGS_ICON_FRAMES - 1));
    $parts = savingsIconGifParts(savingsIconFrame($motion));
    if ($frame === 0) {
        $animation = 'GIF89a' . $parts['screen'] . $parts['palette'];
        // NETSCAPE repeat count 1 means one repeat after the first play: two plays total.
        $animation .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01" . pack('v', 1) . "\x00";
    }
    // 200 centiseconds total: settle briefly on complete first and last frames.
    $delay = $frame === 0 || $frame === SAVINGS_ICON_FRAMES - 1 ? 25 : 15;
    // Disposal 2 restores transparent background; every frame contains complete artwork.
    $animation .= "\x21\xF9\x04\x09" . pack('v', $delay) . chr($parts['transparent']) . "\x00";
    $descriptor = $parts['descriptor'];
    $descriptor[9] = chr((ord($descriptor[9]) & 0x78) | 0x80 | $parts['palette_bits']);
    $animation .= $descriptor . $parts['palette'] . $parts['data'];
}
$animation .= "\x3B";
$path = dirname(__DIR__) . '/public/assets/images/email-savings-confetti.gif';
if (file_put_contents($path, $animation) === false) {
    throw new RuntimeException('The savings icon could not be written.');
}
printf("Generated %s: %d × %d px, %d frames, 2 seconds, 2 plays, %d bytes.\n", basename($path),
    SAVINGS_ICON_SIZE, SAVINGS_ICON_SIZE, SAVINGS_ICON_FRAMES, strlen($animation));
