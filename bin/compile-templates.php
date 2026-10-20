<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$twig = new Environment(new FilesystemLoader($root . '/resources/views'));

foreach (['absolute_url', 'cart_summary', 'csrf_token', 'current_user', 'favorite_count', 'flash_messages', 'is_favorite', 'is_variant_in_cart', 'money', 'navigation_categories', 'percent_basis_points', 'url'] as $name) {
    $twig->addFunction(new TwigFunction($name, static fn(): null => null));
}
$twig->addFilter(new TwigFilter('json_decode', static fn(): array => []));

$count = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') {
        continue;
    }

    $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/resources/views') + 1));
    $twig->load($name);
    ++$count;
}

fwrite(STDOUT, sprintf("Compiled %d Twig templates.%s", $count, PHP_EOL));
