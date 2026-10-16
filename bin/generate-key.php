<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    copy($root . '/.env.example', $envFile);
}
$contents = file_get_contents($envFile);
if ($contents === false) {
    fwrite(STDERR, "Nu se poate citi .env\n");
    exit(1);
}
$key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1, $count);
if ($count === 0) {
    $contents .= "\nAPP_KEY=" . $key . "\n";
}
file_put_contents($envFile, $contents, LOCK_EX);
fwrite(STDOUT, "Cheia aplicației a fost generată în .env.\n");
