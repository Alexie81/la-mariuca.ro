<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

final class ProductRecommendationService
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'pentru', 'copii', 'copil', 'bebe', 'bebelusi', 'bebelus', 'din', 'cu', 'si', 'sau',
        'de', 'la', 'in', 'un', 'o', 'set', 'produs', 'produse', 'mici', 'mica', 'mic', 'mariuca',
    ];

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @param array<string, mixed> $product @return list<array<string, mixed>> */
    public function similarTo(array $product, int $limit = 15): array
    {
        $limit = max(1, min(15, $limit));
        $candidates = $this->catalog->searchableProducts();
        $target = null;
        foreach ($candidates as $candidate) {
            if ((int) $candidate['id'] === (int) $product['id']) {
                $target = $candidate;
                break;
            }
        }
        $target ??= $product;

        $targetCategories = $this->terms((string) ($target['search_categories'] ?? $target['category_name'] ?? ''));
        $targetNameTerms = $this->terms((string) ($target['name'] ?? ''));
        $targetContextTerms = $this->terms(implode(' ', [
            (string) ($target['short_description'] ?? ''),
            (string) ($target['description'] ?? ''),
            (string) ($target['search_categories'] ?? $target['category_name'] ?? ''),
        ]));
        $targetPrice = max(1, (int) ($target['price_bani'] ?? 0));

        $ranked = [];
        foreach ($candidates as $candidate) {
            if ((int) $candidate['id'] === (int) $product['id']) {
                continue;
            }

            $candidateCategories = $this->terms((string) ($candidate['search_categories'] ?? $candidate['category_name'] ?? ''));
            $candidateNameTerms = $this->terms((string) ($candidate['name'] ?? ''));
            $candidateContextTerms = $this->terms(implode(' ', [
                (string) ($candidate['short_description'] ?? ''),
                (string) ($candidate['description'] ?? ''),
                (string) ($candidate['search_categories'] ?? $candidate['category_name'] ?? ''),
            ]));

            $categoryOverlap = count(array_intersect($targetCategories, $candidateCategories));
            $nameOverlap = count(array_intersect($targetNameTerms, $candidateNameTerms));
            $contextOverlap = count(array_intersect($targetContextTerms, $candidateContextTerms));
            $samePrimaryCategory = (int) ($target['primary_category_id'] ?? 0) > 0
                && (int) ($target['primary_category_id'] ?? 0) === (int) ($candidate['primary_category_id'] ?? 0);
            $candidatePrice = max(1, (int) ($candidate['price_bani'] ?? 0));
            $priceDistance = abs($candidatePrice - $targetPrice) / max($candidatePrice, $targetPrice);

            $score = ($samePrimaryCategory ? 120 : 0)
                + ($categoryOverlap * 55)
                + ($nameOverlap * 22)
                + (min(8, $contextOverlap) * 5)
                + (int) round(max(0, 1 - $priceDistance) * 18)
                + ((int) ($candidate['available_quantity'] ?? 0) > 0 ? 3 : 0);

            $candidate['similarity_score'] = $score;
            $ranked[] = $candidate;
        }

        usort($ranked, static function (array $left, array $right): int {
            $byScore = (int) $right['similarity_score'] <=> (int) $left['similarity_score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            $byPublished = strcmp((string) ($right['published_at'] ?? ''), (string) ($left['published_at'] ?? ''));
            return $byPublished !== 0 ? $byPublished : ((int) $right['id'] <=> (int) $left['id']);
        });

        return array_slice($ranked, 0, $limit);
    }

    /** @return list<string> */
    private function terms(string $value): array
    {
        $value = mb_strtolower($value);
        $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $parts = preg_split('/[^a-z0-9]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_filter($parts, static fn(string $term): bool => mb_strlen($term) >= 3 && !in_array($term, self::STOP_WORDS, true));
        return array_values(array_unique($terms));
    }
}
