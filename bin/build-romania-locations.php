<?php

declare(strict_types=1);

const SIRUTA_SOURCE = 'https://data.gov.ro/dataset/721c9059-5f87-4c79-9854-a1d5c18f58d5/resource/dac903f0-32b5-489a-89e7-2c80d96cf68d/download/siruta_s1_2026.csv';

$root = dirname(__DIR__);
$target = $root . '/public/assets/data/romania-localities.json';
$curl = curl_init(SIRUTA_SOURCE);
if ($curl === false) {
    throw new RuntimeException('Nu s-a putut inițializa descărcarea SIRUTA.');
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_USERAGENT => 'LaMariuca/1.0 location-dataset-builder',
]);
$csv = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);
if (!is_string($csv) || $status !== 200) {
    throw new RuntimeException('Descărcarea SIRUTA a eșuat: HTTP ' . $status . ($error !== '' ? ' / ' . $error : ''));
}

$lines = preg_split('/\R/u', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
$header = array_map('trim', str_getcsv((string) array_shift($lines), ';', '"', ''));
$rows = [];
foreach ($lines as $line) {
    if (trim($line) === '') {
        continue;
    }
    $values = str_getcsv($line, ';', '"', '');
    if (count($values) < count($header)) {
        continue;
    }
    $row = array_combine($header, array_slice($values, 0, count($header)));
    $rows[] = $row;
}

/** @return string */
function normalizeRomanianDiacritics(string $value): string
{
    return strtr($value, ['Ş' => 'Ș', 'Ţ' => 'Ț', 'ş' => 'ș', 'ţ' => 'ț']);
}

/** @return string */
function titleRomanian(string $value): string
{
    return mb_convert_case(mb_strtolower(normalizeRomanianDiacritics(trim($value)), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/** @return string */
function sortKey(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return mb_strtolower(is_string($ascii) ? $ascii : $value, 'UTF-8');
}

$countyByCode = [];
$uatBySiruta = [];
foreach ($rows as $row) {
    $level = (int) ($row['NIV'] ?? 0);
    if ($level === 1) {
        $raw = normalizeRomanianDiacritics((string) ($row['DENLOC'] ?? ''));
        $name = preg_match('/^JUDEȚUL\s+/u', $raw) === 1 ? preg_replace('/^JUDEȚUL\s+/u', '', $raw) : $raw;
        $countyByCode[(string) ($row['JUD'] ?? '')] = $raw === 'MUNICIPIUL BUCUREȘTI' ? 'București' : titleRomanian((string) $name);
    } elseif ($level === 2) {
        $uatBySiruta[(string) ($row['SIRUTA'] ?? '')] = titleRomanian((string) ($row['DENLOC'] ?? ''));
    }
}

$rawLocalities = [];
foreach ($rows as $row) {
    if ((int) ($row['NIV'] ?? 0) !== 3) {
        continue;
    }
    $county = $countyByCode[(string) ($row['JUD'] ?? '')] ?? null;
    if (!is_string($county)) {
        continue;
    }
    $rawName = normalizeRomanianDiacritics((string) ($row['DENLOC'] ?? ''));
    if ($county === 'București' && preg_match('/^BUCUREȘTI SECTORUL\s+([1-6])$/u', $rawName, $matches) === 1) {
        $name = 'București — Sector ' . $matches[1];
    } else {
        $name = titleRomanian($rawName);
    }
    $rawLocalities[$county][] = [
        'name' => $name,
        'parent' => $uatBySiruta[(string) ($row['SIRSUP'] ?? '')] ?? '',
    ];
}

$counties = [];
foreach ($rawLocalities as $county => $items) {
    $nameCounts = [];
    foreach ($items as $item) {
        $nameCounts[$item['name']] = ($nameCounts[$item['name']] ?? 0) + 1;
    }
    $options = [];
    foreach ($items as $item) {
        $label = $item['name'];
        if (($nameCounts[$item['name']] ?? 0) > 1 && $item['parent'] !== '') {
            $label .= ' — ' . $item['parent'];
        }
        $options[$label] = true;
    }
    $localities = array_keys($options);
    usort($localities, static fn(string $left, string $right): int => strnatcasecmp(sortKey($left), sortKey($right)));
    $counties[$county] = $localities;
}
uksort($counties, static fn(string $left, string $right): int => strnatcasecmp(sortKey($left), sortKey($right)));

$payload = [
    'meta' => [
        'source' => 'Institutul Național de Statistică / data.gov.ro',
        'registry' => 'SIRUTA S1 2026',
        'source_url' => SIRUTA_SOURCE,
        'license' => 'CC BY 4.0',
        'reference_date' => '2026-S1',
        'county_count' => count($counties),
        'locality_count' => array_sum(array_map('count', $counties)),
    ],
    'counties' => $counties,
];

$directory = dirname($target);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Directorul pentru nomenclator nu a putut fi creat.');
}
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($target, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Nomenclatorul generat nu a putut fi salvat.');
}

echo sprintf("Generated %d counties and %d localities in %s\n", $payload['meta']['county_count'], $payload['meta']['locality_count'], $target);
