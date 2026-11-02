<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class NewsletterAdminTest extends DatabaseTestCase
{
    private const ADMIN_PASSWORD = 'Newsletter-admin-2026!';
    private int $featuredSubscriberId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $admin = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('newsletter.admin@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $admin->execute(['password' => password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->seedSubscribers();

        $this->get('/admin');
        $login = $this->post('/admin', ['_csrf' => $_SESSION['_csrf'], 'email' => 'newsletter.admin@example.test', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(303, $login->getStatusCode());
    }

    public function testNewsletterCrmSupportsSemanticSearchPaginationSourcesAndMessageHistory(): void
    {
        $page = (string) $this->get('/admin/abonati-newsletter/')->getBody();
        self::assertStringContainsString('<h1 id="admin-newsletter-title">Abonați newsletter</h1>', $page);
        self::assertStringContainsString('data-admin-newsletter-dialog', $page);
        self::assertStringContainsString('Căutare semantică', $page);
        self::assertStringContainsString('assets/css/customer-admin.css', $page);
        self::assertStringContainsString('assets/css/newsletter-admin.css', $page);
        self::assertStringContainsString('Paginarea abonaților newsletter', $page);
        $customers = strpos($page, 'href="/admin/clienti/"');
        $newsletter = strpos($page, 'href="/admin/abonati-newsletter/"');
        $stock = strpos($page, 'Stoc și achiziții');
        self::assertIsInt($customers);
        self::assertIsInt($newsletter);
        self::assertIsInt($stock);
        self::assertTrue($customers < $newsletter && $newsletter < $stock);

        $secondPage = (string) $this->get('/admin/abonati-newsletter/?pagina=2')->getBody();
        self::assertStringContainsString('Pagina anterioară', $secondPage);

        $semantic = (string) $this->get('/admin/abonati-newsletter/?cautare=contact%20activ')->getBody();
        self::assertStringContainsString('Ștefania Ionescu', $semantic);
        self::assertStringContainsString('stefania.newsletter@example.test', $semantic);
        self::assertStringNotContainsString('Abonat Demo 2', $semantic);
        $withoutDiacritics = (string) $this->get('/admin/abonati-newsletter/?cautare=stefania')->getBody();
        self::assertStringContainsString('Ștefania Ionescu', $withoutDiacritics);
        $messageSearch = (string) $this->get('/admin/abonati-newsletter/?cautare=colectia%20de%20toamna')->getBody();
        self::assertStringContainsString('Ștefania Ionescu', $messageSearch);
        $unsubscribed = (string) $this->get('/admin/abonati-newsletter/?cautare=dezabonat')->getBody();
        self::assertStringContainsString('Link din email', $unsubscribed);

        $modal = (string) $this->get('/admin/abonati-newsletter/' . $this->featuredSubscriberId . '/modal/')->getBody();
        self::assertStringContainsString('data-admin-newsletter-profile', $modal);
        self::assertStringContainsString('data-admin-newsletter-sticky-summary', $modal);
        self::assertStringContainsString('data-admin-newsletter-scroll-body', $modal);
        self::assertStringContainsString('Ștefania Ionescu', $modal);
        self::assertStringContainsString('Formular contact', $modal);
        self::assertStringContainsString('Istoricul abonării', $modal);
        self::assertStringNotContainsString('Mesajele trimise', $modal);
        self::assertStringNotContainsString('Paginarea mesajelor abonatului', $modal);
        self::assertStringContainsString('Colecția de toamnă 5', $modal);
        self::assertStringNotContainsString('Colecția de toamnă 4', $modal);

        $standalone = $this->get('/admin/abonati-newsletter/' . $this->featuredSubscriberId . '/');
        self::assertSame(200, $standalone->getStatusCode());
        self::assertStringContainsString('Înapoi la abonați', (string) $standalone->getBody());
    }

    public function testFooterNewsletterFormPersistsItsSourceAndConsent(): void
    {
        $response = $this->post('/newsletter/abonare/', [
            '_csrf' => $_SESSION['_csrf'],
            'newsletter_email' => 'nou@example.test',
            'newsletter_consent' => '1',
            'website' => '',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/#footer-newsletter', $response->getHeaderLine('Location'));
        $subscriber = $this->pdo->query("SELECT status,subscribe_source FROM newsletter_subscribers WHERE email='nou@example.test'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['status' => 'subscribed', 'subscribe_source' => 'footer_newsletter'], $subscriber);
        self::assertSame('subscribe', $this->pdo->query("SELECT event_type FROM newsletter_subscription_events e JOIN newsletter_subscribers s ON s.id=e.subscriber_id WHERE s.email='nou@example.test'")->fetchColumn());
    }

    private function seedSubscribers(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO newsletter_subscribers (user_id,email,name,status,subscribe_source,unsubscribe_source,subscribed_at,unsubscribed_at,last_notified_at,created_at,updated_at)
            VALUES (NULL,:email,:name,:status,:source,:unsubscribe_source,:subscribed,:unsubscribed,:last_notified,:created,:created)");
        $base = new \DateTimeImmutable('2026-09-10 09:00:00', new \DateTimeZone('UTC'));
        for ($index = 1; $index <= 17; $index++) {
            $featured = $index === 1;
            $inactive = $index === 2;
            $created = $base->modify('+' . $index . ' days')->format('c');
            $insert->execute([
                'email' => $featured ? 'stefania.newsletter@example.test' : 'abonat' . $index . '@example.test',
                'name' => $featured ? 'Ștefania Ionescu' : 'Abonat Demo ' . $index,
                'status' => $inactive ? 'unsubscribed' : 'subscribed',
                'source' => $featured ? 'contact_form' : 'footer_newsletter',
                'unsubscribe_source' => $inactive ? 'email_link' : null,
                'subscribed' => $created,
                'unsubscribed' => $inactive ? $base->modify('+' . ($index + 5) . ' days')->format('c') : null,
                'last_notified' => $featured ? $base->modify('+30 days')->format('c') : null,
                'created' => $created,
            ]);
            $subscriberId = (int) $this->pdo->lastInsertId();
            if ($featured) {
                $this->featuredSubscriberId = $subscriberId;
            }
            $event = $this->pdo->prepare("INSERT INTO newsletter_subscription_events (subscriber_id,event_type,source,details_json,created_at) VALUES (:subscriber,:event,:source,'{}',:created)");
            $event->execute(['subscriber' => $subscriberId, 'event' => 'subscribe', 'source' => $featured ? 'contact_form' : 'footer_newsletter', 'created' => $created]);
            if ($inactive) {
                $event->execute(['subscriber' => $subscriberId, 'event' => 'unsubscribe', 'source' => 'email_link', 'created' => $base->modify('+' . ($index + 5) . ' days')->format('c')]);
            }
        }

        $message = $this->pdo->prepare("INSERT INTO newsletter_messages (subject,preview_text,status,sent_at,created_at,updated_at) VALUES (:subject,:preview,'sent',:sent,:sent,:sent)");
        $delivery = $this->pdo->prepare("INSERT INTO newsletter_deliveries (subscriber_id,message_id,status,sent_at,delivered_at,opened_at,clicked_at,error_message,created_at,updated_at) VALUES (:subscriber,:message,:status,:sent,:sent,:opened,NULL,NULL,:sent,:sent)");
        for ($index = 1; $index <= 5; $index++) {
            $sent = $base->modify('+' . (20 + $index) . ' days')->format('c');
            $message->execute(['subject' => 'Colecția de toamnă ' . $index, 'preview' => 'Noutăți alese cu grijă pentru cei mici.', 'sent' => $sent]);
            $delivery->execute(['subscriber' => $this->featuredSubscriberId, 'message' => (int) $this->pdo->lastInsertId(), 'status' => $index % 2 === 0 ? 'delivered' : 'opened', 'sent' => $sent, 'opened' => $index % 2 === 0 ? null : $sent]);
        }
    }

    private function get(string $path): ResponseInterface
    {
        return $this->request('GET', $path);
    }

    /** @param array<string,mixed> $data */
    private function post(string $path, array $data): ResponseInterface
    {
        return $this->request('POST', $path, $data);
    }

    /** @param array<string,mixed> $data */
    private function request(string $method, string $path, array $data = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.98'])->withHeader('User-Agent', 'LaMariuca-NewsletterAdmin-Test/1.0');
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
