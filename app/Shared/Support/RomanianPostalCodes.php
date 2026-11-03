<?php

declare(strict_types=1);

namespace App\Shared\Support;

final class RomanianPostalCodes
{
    /** @var array<string|int, list<array{0:string,1:string}>>|null */
    private ?array $codes = null;

    public function __construct(private readonly string $datasetPath)
    {
    }

    public static function fromProjectRoot(?string $root = null): self
    {
        return new self(($root ?? dirname(__DIR__, 3)) . '/resources/data/romania-postal-codes.json');
    }

    /** @return list<array{county:string,city:string,address:string}> */
    public function lookup(string $postalCode): array
    {
        if (preg_match('/^[0-9]{6}$/D', $postalCode) !== 1) {
            throw new \InvalidArgumentException('Codul poștal trebuie să conțină exact 6 cifre.');
        }
        if ($this->codes === null) {
            $contents = is_file($this->datasetPath) ? file_get_contents($this->datasetPath) : false;
            $dataset = is_string($contents) ? json_decode($contents, true) : null;
            if (!is_array($dataset) || !is_array($dataset['codes'] ?? null)) {
                throw new \RuntimeException('Căutarea după cod poștal nu este disponibilă momentan.');
            }
            $this->codes = $dataset['codes'];
        }
        $matches = [];
        foreach ($this->codes[$postalCode] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry[0] ?? null) && is_string($entry[1] ?? null)) {
                $matches[] = ['county' => $entry[0], 'city' => $entry[1], 'address' => ''];
            }
        }
        return $matches;
    }
}
