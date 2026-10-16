<?php

declare(strict_types=1);

$configuredRoot = $_SERVER['APP_ROOT'] ?? getenv('APP_ROOT');
$root = is_string($configuredRoot) && $configuredRoot !== '' ? rtrim($configuredRoot, '/\\') : dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('Aplicația nu este instalată: vendor/autoload.php lipsește.');
}
require $root . '/vendor/autoload.php';

$app = App\AppFactory::create($root);
$app->run();
