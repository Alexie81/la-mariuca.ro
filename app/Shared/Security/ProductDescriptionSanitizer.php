<?php

declare(strict_types=1);

namespace App\Shared\Security;

final class ProductDescriptionSanitizer
{
    private \HTMLPurifier $purifier;

    public function __construct()
    {
        $configuration = \HTMLPurifier_Config::createDefault();
        $configuration->set(
            'HTML.Allowed',
            'p,br,strong,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href|title],div[class|style],img[src|alt|title|width|height|class]'
        );
        $configuration->set('CSS.AllowedProperties', ['width', 'margin-left', 'margin-right']);
        $configuration->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $configuration->set('URI.DisableExternalResources', true);
        $configuration->set('Attr.EnableID', false);
        $configuration->set('Cache.DefinitionImpl', null);
        $this->purifier = new \HTMLPurifier($configuration);
    }

    public function sanitize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Browsers still emit the legacy tags below for contenteditable commands.
        // Normalize them before purification so formatting survives consistently.
        $value = preg_replace(
            ['/<\s*b\b[^>]*>/i', '/<\s*\/\s*b\s*>/i', '/<\s*i\b[^>]*>/i', '/<\s*\/\s*i\s*>/i'],
            ['<strong>', '</strong>', '<em>', '</em>'],
            $value
        ) ?? $value;

        if (!$this->containsRichMarkup($value)) {
            $value = $this->plainTextToHtml($value);
        }

        return trim($this->purifier->purify($value));
    }

    private function containsRichMarkup(string $value): bool
    {
        return preg_match('/<\s*(?:p|br|strong|em|u|ul|ol|li|h[2-4]|blockquote|a|div|img)\b/i', $value) === 1;
    }

    private function plainTextToHtml(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $paragraphs = preg_split('/\n{2,}/', $value) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html[] = '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }

        return implode("\n", $html);
    }
}
