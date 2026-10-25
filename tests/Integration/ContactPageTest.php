<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class ContactPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_NUMBER'] = '40763715974';
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_NUMBER']);
        parent::tearDown();
    }

    public function testContactPageRendersDedicatedResponsiveForm(): void
    {
        $this->seed();

        $body = (string) $this->get('/pagina/contact/')->getBody();

        self::assertStringContainsString('contact-page-body', $body);
        self::assertStringContainsString('id="formular-contact"', $body);
        self::assertStringContainsString('action="/contact/whatsapp/"', $body);
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertStringContainsString('name="subject"', $body);
        self::assertStringContainsString('name="consent"', $body);
        self::assertStringContainsString('Continuă conversația pe WhatsApp', $body);
        self::assertStringContainsString('Mesajul nu este trimis automat.', $body);
        self::assertStringContainsString('https://wa.me/40763715974?', $body);
        self::assertStringContainsString('href="tel:+40763715974"', $body);
        self::assertStringContainsString('href="https://wa.me/40763715974"', $body);
        self::assertStringContainsString('0763 715 974', $body);
        self::assertStringContainsString('contact-quick-email', $body);
        self::assertStringContainsString('contact@la-mariuca.ro', $body);
        self::assertStringContainsString('data-footer-contact="phone" href="tel:+40763715974"', $body);
        self::assertStringContainsString('Telefon: 0763 715 974', $body);
        self::assertStringContainsString('data-footer-contact="email" href="mailto:contact@la-mariuca.ro"', $body);
        self::assertStringContainsString('Email: contact@la-mariuca.ro', $body);
        self::assertStringNotContainsString('Datele de contact ale comerciantului se configurează', $body);
    }

    public function testEmailMayBeOmittedFromContactMessage(): void
    {
        $this->seed();
        $_SESSION['_csrf'] = 'contact-no-email-token';

        $response = $this->post([
            '_csrf' => 'contact-no-email-token',
            'name' => 'Maria Pop',
            'email' => '',
            'phone' => '',
            'subject' => 'cadou',
            'message' => 'Aș dori o recomandare pentru un cadou potrivit.',
            'consent' => '1',
            'website' => '',
        ]);

        self::assertSame(303, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $message = (string) ($query['text'] ?? '');
        self::assertStringContainsString('Subiect: Alegerea unui cadou', $message);
        self::assertStringNotContainsString('Email:', $message);
        self::assertStringNotContainsString('Telefon:', $message);
    }

    public function testValidContactFormRedirectsToConfiguredWhatsAppWithPrefilledMessage(): void
    {
        $this->seed();
        $_SESSION['_csrf'] = 'contact-test-token';

        $response = $this->post([
            '_csrf' => 'contact-test-token',
            'name' => 'Maria Pop',
            'email' => 'maria@example.test',
            'phone' => '0763 715 974',
            'subject' => 'marime',
            'message' => 'Aș avea nevoie de ajutor pentru alegerea mărimii potrivite.',
            'consent' => '1',
            'website' => '',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://wa.me/40763715974?text=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $message = (string) ($query['text'] ?? '');
        self::assertStringContainsString('Bună, echipa La Măriuca Boutique!', $message);
        self::assertStringContainsString('Numele meu este: Maria Pop', $message);
        self::assertStringContainsString('Subiect: Alegerea mărimii', $message);
        self::assertStringContainsString('Email: maria@example.test', $message);
        self::assertStringContainsString('Telefon: 0763 715 974', $message);
        self::assertStringContainsString('Aș avea nevoie de ajutor', $message);
    }

    public function testInvalidContactFormReturnsFieldErrorsAndKeepsSafeValues(): void
    {
        $this->seed();
        $_SESSION['_csrf'] = 'contact-invalid-token';

        $response = $this->post([
            '_csrf' => 'contact-invalid-token',
            'name' => 'M',
            'email' => 'email-gresit',
            'phone' => 'telefon?',
            'subject' => 'nepermis',
            'message' => 'scurt',
            'website' => '',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/pagina/contact/#formular-contact', $response->getHeaderLine('Location'));

        $body = (string) $this->get('/pagina/contact/')->getBody();
        self::assertStringContainsString('Mai avem nevoie de câteva detalii.', $body);
        self::assertStringContainsString('Scrie un nume între 2 și 80 de caractere.', $body);
        self::assertStringContainsString('Introdu o adresă de email validă.', $body);
        self::assertStringContainsString('Alege subiectul mesajului.', $body);
        self::assertStringContainsString('Confirmă că datele pot fi deschise în WhatsApp.', $body);
        self::assertStringContainsString('value="email-gresit"', $body);
    }

    /** @param array<string, string> $data */
    private function post(array $data): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/contact/whatsapp/', ['REMOTE_ADDR' => '127.0.0.70'])
            ->withParsedBody($data);
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();

        return $response;
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
