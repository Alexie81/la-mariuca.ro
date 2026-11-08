<?php

declare(strict_types=1);

use App\Modules\Payments\StripeCatalogQueue;
use App\Modules\Payments\StripeCatalogSync;
use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = Config::load($root);
$pdo = ConnectionFactory::create($config, $root);
$command = $argv[1] ?? 'process';
$limit = max(1, min(200, (int) ($argv[2] ?? 20)));
try {
    if (!in_array($command, ['process', 'sync-all', 'work'], true)) {
        throw new RuntimeException('Folosește: stripe-catalog.php process|sync-all|work [limită].');
    }
    if (!$config->bool('STRIPE_CATALOG_ENABLED') || !$config->stripeConfigured()) {
        fwrite(STDOUT, "Sincronizarea catalogului Stripe este dezactivată sau neconfigurată.\n");
        exit(1);
    }
    if ($command === 'sync-all') {
        $count = (new StripeCatalogQueue($pdo))->enqueueAll();
        fwrite(STDOUT, "Produse puse în coadă: {$count}.\n");
    }
    $sync = new StripeCatalogSync($pdo, $config);
    do {
        $result = $sync->process($limit);
        if ($command !== 'work' || $result['synced'] + $result['failed'] > 0) {
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        }
        if ($command === 'work') { sleep(5); }
    } while ($command === 'work');
} catch (Throwable $error) {
    // API exception messages may contain sensitive request data; keep CLI logs generic.
    fwrite(STDERR, "Sincronizarea nu a putut rula. Verifică setările și coloana last_error din coada Stripe.\n");
    exit(1);
}
