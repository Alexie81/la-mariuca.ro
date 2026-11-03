<?php

declare(strict_types=1);

use App\Shared\Support\RomanianLocations;

require dirname(__DIR__) . '/vendor/autoload.php';

const POSTAL_SOURCE = 'https://download.geonames.org/export/zip/RO.zip';

$root = dirname(__DIR__);
$locations = RomanianLocations::fromProjectRoot($root);
$sourceFile = $argv[1] ?? null;
$temporaryFile = null;
if ($sourceFile === null) {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Generatorul necesită extensia PHP zip sau calea către RO.txt ca argument.');
    }
    $curl = curl_init(POSTAL_SOURCE);
    if ($curl === false) {
        throw new RuntimeException('Descărcarea nomenclatorului poștal nu poate fi inițializată.');
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'LaMariuca/1.0 postal-dataset-builder']);
    $archive = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($archive) || $status !== 200) {
        throw new RuntimeException('Descărcarea GeoNames a eșuat: HTTP ' . $status);
    }
    $temporaryFile = tempnam(sys_get_temp_dir(), 'lm-postal-');
    if ($temporaryFile === false || file_put_contents($temporaryFile, $archive) === false) {
        throw new RuntimeException('Arhiva poștală temporară nu poate fi creată.');
    }
    $zip = new ZipArchive();
    try {
        if ($zip->open($temporaryFile) !== true) {
            throw new RuntimeException('Arhiva GeoNames nu poate fi citită.');
        }
        $text = $zip->getFromName('RO.txt');
        $zip->close();
    } finally {
        unlink($temporaryFile);
    }
} else {
    $text = is_file($sourceFile) ? file_get_contents($sourceFile) : false;
}
if (!is_string($text) || $text === '') {
    throw new RuntimeException('Sursa GeoNames RO.txt lipsește sau este goală.');
}

$codes = [];
$unresolvedCodes = [];
$rowsRead = 0;
$rowsMatched = 0;
foreach (preg_split('/\R/u', $text) ?: [] as $line) {
    if ($line === '') {
        continue;
    }
    $columns = explode("\t", $line);
    if (count($columns) < 9 || $columns[0] !== 'RO' || preg_match('/^[0-9]{6}$/D', $columns[1]) !== 1) {
        continue;
    }
    ++$rowsRead;
    $county = strtr(trim($columns[3]), ['ş' => 'ș', 'ţ' => 'ț', 'Ş' => 'Ș', 'Ţ' => 'Ț']);
    $locality = trim($columns[2]);
    // București's sector is supplied explicitly by GeoNames; never infer it from a code prefix.
    if ($county === 'București') {
        $locality = trim($columns[7]);
    }
    $canonical = $locations->canonicalize($county, $locality);
    if ($canonical === null) {
        $unresolvedCodes[$columns[1]] = true;
        continue;
    }
    ++$rowsMatched;
    $match = [$canonical['county'], $canonical['locality']];
    $code = $columns[1];
    if (!in_array($match, $codes[$code] ?? [], true)) {
        $codes[$code][] = $match;
    }
}
// A partially resolved code must never masquerade as one unambiguous destination.
foreach ($unresolvedCodes as $code => $_) {
    unset($codes[$code]);
}
if (count($codes) < 30000) {
    throw new RuntimeException('Sursa conține prea puține coduri recunoscute; indexul existent nu a fost înlocuit.');
}
ksort($codes, SORT_STRING);
$target = $root . '/resources/data/romania-postal-codes.json';
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
    throw new RuntimeException('Directorul de date nu poate fi creat.');
}
$dataset = [
    'meta' => [
        'source' => 'GeoNames', 'source_url' => POSTAL_SOURCE,
        'license' => 'CC BY 4.0', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
        'downloaded_at' => gmdate('Y-m-d'), 'source_sha256' => hash('sha256', $text),
        'canonical_registry' => 'INS SIRUTA S1 2026', 'precision' => 'locality',
        'source_rows' => $rowsRead, 'matched_rows' => $rowsMatched, 'postal_code_count' => count($codes),
    ],
    'codes' => $codes,
];
if (file_put_contents($target, json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Indexul poștal nu poate fi scris.');
}
echo 'Generated ' . count($codes) . ' postal codes from ' . $rowsRead . ' rows (' . $rowsMatched . ' matched to SIRUTA).' . PHP_EOL;
