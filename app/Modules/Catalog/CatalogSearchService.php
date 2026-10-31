<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

final class CatalogSearchService
{
    /** @var array<string, true> */
    private const STOP_WORDS = [
        'a' => true,
        'ai' => true,
        'al' => true,
        'ale' => true,
        'cea' => true,
        'cel' => true,
        'cu' => true,
        'de' => true,
        'din' => true,
        'in' => true,
        'la' => true,
        'o' => true,
        'pe' => true,
        'pentru' => true,
        'si' => true,
        'un' => true,
    ];

    /** @var array<string, string> */
    private const TOKEN_ALIASES = [
        'accesoriu' => 'accesoriu', 'accesorii' => 'accesoriu',
        'baiat' => 'baiat', 'baiati' => 'baiat', 'baietel' => 'baiat', 'baietei' => 'baiat',
        'bebe' => 'bebelus', 'bebelus' => 'bebelus', 'bebelusi' => 'bebelus', 'nascut' => 'bebelus', 'nascuti' => 'bebelus',
        'bentita' => 'bentita', 'bentite' => 'bentita', 'fundita' => 'bentita', 'fundite' => 'bentita',
        'botez' => 'botez', 'botezuri' => 'botez', 'crestinare' => 'botez', 'crestinari' => 'botez',
        'bluza' => 'bluza', 'bluze' => 'bluza', 'tricou' => 'bluza', 'tricouri' => 'bluza',
        'cadou' => 'cadou', 'cadouri' => 'cadou', 'dar' => 'cadou', 'daruri' => 'cadou',
        'camasa' => 'camasa', 'camasi' => 'camasa',
        'copil' => 'copil', 'copii' => 'copil',
        'costum' => 'costum', 'costume' => 'costum', 'costumas' => 'costum', 'costumase' => 'costum',
        'elegant' => 'ocazie', 'eleganta' => 'ocazie', 'elegante' => 'ocazie', 'eveniment' => 'ocazie', 'evenimente' => 'ocazie',
        'fata' => 'fetita', 'fete' => 'fetita', 'fetita' => 'fetita', 'fetite' => 'fetita',
        'festiv' => 'ocazie', 'festiva' => 'ocazie', 'nunta' => 'ocazie', 'petrecere' => 'ocazie',
        'geaca' => 'geaca', 'geci' => 'geaca', 'jacheta' => 'geaca', 'jachete' => 'geaca',
        'gheata' => 'incaltaminte', 'ghete' => 'incaltaminte', 'botina' => 'incaltaminte', 'botine' => 'incaltaminte',
        'cizma' => 'incaltaminte', 'cizme' => 'incaltaminte', 'incaltaminte' => 'incaltaminte',
        'pantof' => 'incaltaminte', 'pantofi' => 'incaltaminte', 'pantofior' => 'incaltaminte', 'pantofiori' => 'incaltaminte',
        'sandala' => 'incaltaminte', 'sandale' => 'incaltaminte',
        'jucarie' => 'jucarie', 'jucarii' => 'jucarie', 'joaca' => 'jucarie',
        'lumanare' => 'lumanare', 'lumanari' => 'lumanare',
        'pantalon' => 'pantalon', 'pantaloni' => 'pantalon', 'colant' => 'pantalon', 'colanti' => 'pantalon',
        'pijama' => 'pijama', 'pijamale' => 'pijama',
        'popular' => 'traditional', 'populara' => 'traditional', 'populare' => 'traditional',
        'pulover' => 'pulover', 'pulovere' => 'pulover', 'cardigan' => 'pulover', 'cardigane' => 'pulover',
        'rochie' => 'rochie', 'rochii' => 'rochie', 'rochita' => 'rochie', 'rochite' => 'rochie',
        'romanesc' => 'traditional', 'romaneasca' => 'traditional', 'romanesti' => 'traditional',
        'salopeta' => 'salopeta', 'salopete' => 'salopeta',
        'set' => 'set', 'seturi' => 'set', 'compleu' => 'set', 'compleuri' => 'set',
        'traditional' => 'traditional', 'traditionala' => 'traditional', 'traditionale' => 'traditional',
        'trusou' => 'trusou', 'trusouri' => 'trusou',
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $productIndex = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $categoryIndex = null;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @return array{products: list<array<string, mixed>>, exact: bool} */
    public function products(string $query): array
    {
        $normalizedQuery = $this->normalize($query);
        $queryTokens = $this->tokens($query);
        if ($normalizedQuery === '' || $queryTokens === []) {
            return ['products' => [], 'exact' => false];
        }

        $products = $this->productIndex ??= $this->catalog->searchableProducts();
        $skuQuery = mb_strtolower(trim($query), 'UTF-8');
        foreach ($products as $product) {
            foreach ($this->csvValues((string) ($product['search_skus'] ?? '')) as $sku) {
                if (mb_strtolower($sku, 'UTF-8') === $skuQuery) {
                    return ['products' => [$product], 'exact' => true];
                }
            }
        }

        foreach ($products as $product) {
            if ($this->normalize((string) $product['name']) === $normalizedQuery) {
                return ['products' => [$product], 'exact' => true];
            }
        }

        $nameMatches = [];
        foreach ($products as $product) {
            $nameMatches[] = [
                'product' => $product,
                'score' => $this->nameIdentityScore($queryTokens, $this->tokens((string) $product['name'], false)),
            ];
        }
        usort($nameMatches, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $bestNameScore = (float) ($nameMatches[0]['score'] ?? 0.0);
        $nextNameScore = (float) ($nameMatches[1]['score'] ?? 0.0);
        if (count($queryTokens) >= 2 && $bestNameScore >= 0.86 && $bestNameScore - $nextNameScore >= 0.07) {
            return ['products' => [$nameMatches[0]['product']], 'exact' => true];
        }

        $ranked = [];
        foreach ($products as $product) {
            $score = $this->productScore($queryTokens, $normalizedQuery, $product);
            if ($score === null) {
                continue;
            }
            $ranked[] = ['product' => $product, 'score' => $score];
        }
        usort($ranked, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            return $byScore !== 0
                ? $byScore
                : strnatcasecmp((string) $left['product']['name'], (string) $right['product']['name']);
        });

        return [
            'products' => array_values(array_map(static fn(array $item): array => $item['product'], $ranked)),
            'exact' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function categories(string $query, int $limit = 4): array
    {
        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return [];
        }

        $ranked = [];
        foreach ($this->categoryIndex ??= $this->catalog->searchableCategories() as $category) {
            $nameTokens = $this->tokens((string) $category['name'], false);
            $descriptionTokens = $this->tokens((string) ($category['description'] ?? ''), false);
            $score = 0.0;
            foreach ($queryTokens as $queryToken) {
                $nameMatch = $this->bestTokenMatch($queryToken, $nameTokens);
                $descriptionMatch = $this->bestTokenMatch($queryToken, $descriptionTokens);
                $best = max($nameMatch, $descriptionMatch);
                if ($best < $this->minimumTokenScore($queryToken)) {
                    continue 2;
                }
                $score += max($nameMatch * 40, $descriptionMatch * 16);
            }
            $ranked[] = ['category' => $category, 'score' => $score];
        }
        usort($ranked, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            $byPosition = (int) $left['category']['position'] <=> (int) $right['category']['position'];
            return $byPosition !== 0
                ? $byPosition
                : strnatcasecmp((string) $left['category']['name'], (string) $right['category']['name']);
        });

        return array_slice(array_values(array_map(static fn(array $item): array => $item['category'], $ranked)), 0, max(1, min(12, $limit)));
    }

    /** @param list<string> $queryTokens @param array<string, mixed> $product */
    private function productScore(array $queryTokens, string $normalizedQuery, array $product): ?float
    {
        $name = $this->normalize((string) $product['name']);
        $categories = $this->normalize((string) ($product['search_categories'] ?? ''));
        $nameTokens = $this->tokens($name, false);
        $categoryTokens = $this->tokens($categories, false);
        $bodyTokens = $this->tokens(implode(' ', [
            (string) ($product['short_description'] ?? ''),
            (string) ($product['description'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (string) ($product['seo_title'] ?? ''),
            (string) ($product['meta_description'] ?? ''),
        ]), false);
        $skuTokens = $this->tokens(str_replace(',', ' ', (string) ($product['search_skus'] ?? '')), false);

        $score = 0.0;
        foreach ($queryTokens as $queryToken) {
            $nameMatch = $this->bestTokenMatch($queryToken, $nameTokens);
            $categoryMatch = $this->bestTokenMatch($queryToken, $categoryTokens);
            $bodyMatch = $this->bestTokenMatch($queryToken, $bodyTokens);
            $skuMatch = $this->bestTokenMatch($queryToken, $skuTokens);
            $best = max($nameMatch, $categoryMatch, $bodyMatch, $skuMatch);
            if ($best < $this->minimumTokenScore($queryToken)) {
                return null;
            }
            $score += max($nameMatch * 48, $categoryMatch * 34, $bodyMatch * 17, $skuMatch * 44);
        }

        if ($normalizedQuery !== '' && str_contains($name, $normalizedQuery)) {
            $score += 38;
        }
        if ($normalizedQuery !== '' && str_contains($categories, $normalizedQuery)) {
            $score += 22;
        }

        return $score;
    }

    /** @param list<string> $queryTokens @param list<string> $nameTokens */
    private function nameIdentityScore(array $queryTokens, array $nameTokens): float
    {
        if ($queryTokens === [] || $nameTokens === []) {
            return 0.0;
        }

        $coverage = array_sum(array_map(fn(string $token): float => $this->bestTokenMatch($token, $nameTokens), $queryTokens)) / count($queryTokens);
        $precision = array_sum(array_map(fn(string $token): float => $this->bestTokenMatch($token, $queryTokens), $nameTokens)) / count($nameTokens);
        return ($coverage * 0.68) + ($precision * 0.32);
    }

    /** @param list<string> $candidates */
    private function bestTokenMatch(string $queryToken, array $candidates): float
    {
        $best = 0.0;
        foreach ($candidates as $candidate) {
            $best = max($best, $this->tokenSimilarity($queryToken, $candidate));
            if ($best === 1.0) {
                break;
            }
        }
        return $best;
    }

    private function tokenSimilarity(string $left, string $right): float
    {
        if ($left === $right) {
            return 1.0;
        }
        $minimumLength = min(strlen($left), strlen($right));
        if ($minimumLength < 4) {
            return 0.0;
        }
        if ((str_starts_with($left, $right) || str_starts_with($right, $left)) && abs(strlen($left) - strlen($right)) <= 3) {
            return 0.82;
        }

        $distance = levenshtein($left, $right);
        $maximumLength = max(strlen($left), strlen($right));
        if ($distance === 1) {
            return max(0.86, 1 - ($distance / $maximumLength));
        }
        if ($distance === 2 && $minimumLength >= 7) {
            return max(0.72, 1 - ($distance / $maximumLength));
        }
        return 0.0;
    }

    private function minimumTokenScore(string $token): float
    {
        return strlen($token) <= 3 ? 1.0 : 0.72;
    }

    /** @return list<string> */
    private function tokens(string $value, bool $removeStopWords = true): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $tokens = array_values(array_unique(array_map($this->canonicalToken(...), $tokens)));
        if (!$removeStopWords) {
            return $tokens;
        }
        return array_values(array_filter($tokens, static fn(string $token): bool => !isset(self::STOP_WORDS[$token])));
    }

    private function canonicalToken(string $token): string
    {
        if (isset(self::TOKEN_ALIASES[$token])) {
            return self::TOKEN_ALIASES[$token];
        }
        if (strlen($token) < 4) {
            return $token;
        }

        $bestAlias = null;
        $bestDistance = PHP_INT_MAX;
        foreach (self::TOKEN_ALIASES as $alias => $canonical) {
            if (abs(strlen($alias) - strlen($token)) > 2) {
                continue;
            }
            $distance = levenshtein($token, $alias);
            $allowedDistance = min(strlen($alias), strlen($token)) >= 7 ? 2 : 1;
            if ($distance <= $allowedDistance && $distance < $bestDistance) {
                $bestAlias = $canonical;
                $bestDistance = $distance;
            }
        }
        return $bestAlias ?? $token;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/<[^>]*>/', ' ', $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @return list<string> */
    private function csvValues(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
}
