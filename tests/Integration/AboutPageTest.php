<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class AboutPageTest extends DatabaseTestCase
{
    public function testAboutPageUsesItsDedicatedEditorialStory(): void
    {
        $this->seed();

        $response = $this->get('/pagina/despre-noi/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<body class="about-page-body">', $body);
        self::assertStringContainsString('class="about-hero"', $body);
        self::assertStringContainsString('Povestea <em>La Măriuca</em> Boutique', $body);
        self::assertStringContainsString('Totul începe cu un nume drag: <em>Maria</em>', $body);
        self::assertStringContainsString('Dintr-un proiect de familie, <em>o pasiune comună</em>', $body);
        self::assertStringContainsString('copiilor de la naștere până la 8 ani', $body);
        self::assertStringContainsString('mai mult decât haine, povești pentru copii.', $body);
        self::assertStringContainsString('href="/magazin/"', $body);
    }

    public function testOtherContentPagesKeepTheGenericTemplate(): void
    {
        $this->seed();

        $body = (string) $this->get('/pagina/contact/')->getBody();

        self::assertStringContainsString('class="container page-shell prose"', $body);
        self::assertStringNotContainsString('class="about-hero"', $body);
    }

    private function get(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
    }
}
