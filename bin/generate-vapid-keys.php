<?php

declare(strict_types=1);

use Minishlink\WebPush\VAPID;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$path = $root . '/storage/private/web-push-vapid.json';
$force = in_array('--force', $argv, true);
if (is_file($path) && !$force) {
    fwrite(STDERR, "Cheile VAPID există deja. Folosește --force doar dacă vrei să invalidezi toate abonamentele existente.\n");
    exit(1);
}

$keys = VAPID::createVapidKeys();
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    throw new RuntimeException('Directorul privat nu a putut fi creat.');
}
$temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
$json = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
    @unlink($temporary);
    throw new RuntimeException('Cheile VAPID nu au putut fi salvate.');
}
@chmod($path, 0600);
fwrite(STDOUT, "Cheile Web Push au fost generate în storage/private/web-push-vapid.json. Conținutul nu a fost afișat.\n");
