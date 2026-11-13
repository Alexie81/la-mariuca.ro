<?php
declare(strict_types=1);

// CLI-only, read-only preview. No number reservation, invoice persistence or notifications.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = \App\Shared\Config\Config::load($root);
$pdo = \App\Shared\Database\ConnectionFactory::create($config, $root);
$preview = new \App\Modules\Invoicing\InvoicePdfPreview(
    new \App\Modules\Companies\CompanyRepository($pdo),
    new \App\Modules\Invoicing\InvoiceConfigurationRepository($pdo),
    $root,
);
$path = $root . '/output/pdf/Factura-La-Mariuca-model.pdf';
$preview->pdf($path);
fwrite(STDOUT, "Model PDF generat din datele actuale ale firmei: {$path}\n");
