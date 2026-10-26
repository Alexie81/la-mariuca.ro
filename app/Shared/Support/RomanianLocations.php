<?php

declare(strict_types=1);

namespace App\Shared\Support;

final class RomanianLocations
{
    /** @var array<string, list<string>>|null */
    private ?array $counties = null;

    /** @var array<string, string>|null */
    private ?array $countyIndex = null;

    /** @var array<string, array<string, string>> */
    private array $localityIndexes = [];

    public function __construct(private readonly string $datasetPath)
    {
    }

    public static function fromProjectRoot(?string $root = null): self
    {
        $projectRoot = $root ?? dirname(__DIR__, 3);
        return new self($projectRoot . '/public/assets/data/romania-localities.json');
    }

    /** @return array{county:string,locality:string}|null */
    public function canonicalize(string $county, string $locality): ?array
    {
        $this->load();
        $countyKey = self::searchKey($county);
        $officialCounty = $this->countyIndex[$countyKey] ?? null;
        if (!is_string($officialCounty)) {
            return null;
        }
        if (!isset($this->localityIndexes[$officialCounty])) {
            $index = [];
            foreach ($this->counties[$officialCounty] as $officialLocality) {
                $index[self::searchKey($officialLocality)] = $officialLocality;
            }
            $this->localityIndexes[$officialCounty] = $index;
        }
        $localityKey = self::searchKey($locality);
        if ($officialCounty === 'București' && preg_match('/^(?:bucuresti\s+)?sector(?:ul)?\s*([1-6])$/', $localityKey, $matches) === 1) {
            $localityKey = 'bucuresti sector ' . $matches[1];
        }
        $officialLocality = $this->localityIndexes[$officialCounty][$localityKey] ?? null;

        return is_string($officialLocality) ? ['county' => $officialCounty, 'locality' => $officialLocality] : null;
    }

    public function isValid(string $county, string $locality): bool
    {
        return $this->canonicalize($county, $locality) !== null;
    }

    /** @return array<string, list<string>> */
    public function counties(): array
    {
        $this->load();
        return $this->counties;
    }

    private function load(): void
    {
        if ($this->counties !== null) {
            return;
        }
        $json = is_file($this->datasetPath) ? file_get_contents($this->datasetPath) : false;
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $counties = is_array($decoded) ? ($decoded['counties'] ?? null) : null;
        if (!is_array($counties)) {
            throw new \RuntimeException('Nomenclatorul localităților din România nu este disponibil.');
        }
        $this->counties = [];
        $this->countyIndex = [];
        foreach ($counties as $county => $localities) {
            if (!is_string($county) || !is_array($localities)) {
                continue;
            }
            $validLocalities = array_values(array_filter($localities, 'is_string'));
            $this->counties[$county] = $validLocalities;
            $this->countyIndex[self::searchKey($county)] = $county;
        }
        $this->countyIndex['municipiul bucuresti'] = 'București';
    }

    private static function searchKey(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a', 'â' => 'a', 'î' => 'i', '—' => ' ', '-' => ' ']);
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '');
    }
}
