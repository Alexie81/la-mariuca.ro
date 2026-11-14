<?php
declare(strict_types=1);

// Read-only, CLI-only design samples; never issues documents or changes stock.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = \App\Shared\Config\Config::load($root);
$pdo = \App\Shared\Database\ConnectionFactory::create($config, $root);
$preview = new \App\Modules\Invoicing\DispatchNotePdfPreview(new \App\Modules\Companies\CompanyRepository($pdo), $root);
foreach (['Aviz-La-Mariuca-model.pdf'=>2, 'Aviz-La-Mariuca-model-multipagina.pdf'=>42] as $name=>$count) {
    $path = $root . '/output/pdf/' . $name;
    $preview->pdf($path, null, $count);
    fwrite(STDOUT, "Model de aviz: {$path}\n");
}
