<?php

declare(strict_types=1);

use App\Modules\Payments\StripeAttemptReconciliationService;
use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
try {
    $config = Config::load($root);
    $pdo = ConnectionFactory::create($config, $root);
    $result = (new StripeAttemptReconciliationService($pdo, $config))->process((int) ($argv[1] ?? 20));
    $errors = $result['errors'];
    unset($result['errors']);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    foreach ($errors as $error) { fwrite(STDERR, json_encode($error, JSON_THROW_ON_ERROR) . "\n"); }
    exit($result['failed'] > 0 || $result['manual_review'] > 0 ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Reconcilierea Stripe nu a putut rula. Verifică setările și conectivitatea. Nu au fost afișate date private.\n");
    exit(1);
}
