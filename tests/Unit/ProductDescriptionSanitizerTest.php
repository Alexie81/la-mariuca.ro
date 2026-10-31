<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Security\ProductDescriptionSanitizer;
use PHPUnit\Framework\TestCase;

final class ProductDescriptionSanitizerTest extends TestCase
{
    public function testItKeepsBoutiqueFormattingAndLocalImages(): void
    {
        $sanitizer = new ProductDescriptionSanitizer();
        $html = <<<'HTML'
<h2>Povestea rochiței</h2>
<p>Moale și <strong>plăcută</strong>, cu <em>detalii fine</em>.</p>
<div class="product-rich-image product-rich-image--center" style="width: 67%; position: fixed">
  <img src="/media/products/poveste.webp" alt="Detaliu rochiță" width="1200" height="900" onerror="alert(1)">
</div>
HTML;

        $clean = $sanitizer->sanitize($html);

        self::assertStringContainsString('<h2>Povestea rochiței</h2>', $clean);
        self::assertStringContainsString('<strong>plăcută</strong>', $clean);
        self::assertStringContainsString('class="product-rich-image product-rich-image--center"', $clean);
        self::assertStringContainsString('width:67%', str_replace(' ', '', $clean));
        self::assertStringContainsString('src="/media/products/poveste.webp"', $clean);
        self::assertStringNotContainsString('position', $clean);
        self::assertStringNotContainsString('onerror', $clean);
    }

    public function testItRemovesScriptsAndExternalEmbeddedImages(): void
    {
        $sanitizer = new ProductDescriptionSanitizer();
        $clean = $sanitizer->sanitize(
            '<p onclick="alert(1)">Text sigur</p><script>alert(1)</script><img src="https://evil.example/tracker.jpg">'
        );

        self::assertStringContainsString('Text sigur', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('evil.example', $clean);
    }

    public function testItTurnsLegacyPlainTextIntoParagraphs(): void
    {
        $clean = (new ProductDescriptionSanitizer())->sanitize("Primul paragraf.\nRând nou.\n\nAl doilea paragraf.");

        self::assertStringContainsString('<p>Primul paragraf.<br', $clean);
        self::assertStringContainsString('<p>Al doilea paragraf.</p>', $clean);
    }

    public function testItPreservesFormattingEmittedByContenteditableBrowsers(): void
    {
        $clean = (new ProductDescriptionSanitizer())->sanitize(
            '<p><b>Text îngroșat</b> și <i>text înclinat</i>.</p>'
        );

        self::assertStringContainsString('<strong>Text îngroșat</strong>', $clean);
        self::assertStringContainsString('<em>text înclinat</em>', $clean);
        self::assertStringNotContainsString('<b>', $clean);
        self::assertStringNotContainsString('<i>', $clean);
    }
}
