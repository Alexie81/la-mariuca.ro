<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class OrderAdminWorkflowTest extends DatabaseTestCase
{
    private const PASSWORD = 'Orders-admin-2026!';
    private int $adminId;
    private int $deliveredOrderId;
    private int $newOrderId;
    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $now = gmdate('c');
        $admin = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('orders.admin@example.test',:password,'Maria','Admin','administrator','active',:now,:now,:now)");
        $admin->execute(['password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now' => $now]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $catalog = $this->pdo->query(
            "SELECT product.id AS product_id,product.name,product.slug,variant.id AS variant_id,variant.sku "
            . "FROM products product JOIN product_variants variant ON variant.product_id=product.id "
            . "WHERE product.status='published' AND variant.status='active' ORDER BY product.id,variant.id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($catalog);
        $this->variantId = (int) $catalog['variant_id'];
        $this->deliveredOrderId = $this->createOrder('LM-ADMIN-RETURN', 'delivered', 'paid', $catalog);
        $this->newOrderId = $this->createOrder('LM-ADMIN-NEW', 'new', 'pending', $catalog);

        $this->get('/admin');
        $login = $this->post('/admin', [
            '_csrf' => $_SESSION['_csrf'],
            'email' => 'orders.admin@example.test',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(303, $login->getStatusCode());
    }

    public function testOrderWorkspaceRendersProductsContactActionsAndResponsiveAssets(): void
    {
        $productId = (int) $this->pdo->query('SELECT product_id FROM order_items WHERE order_id=' . $this->deliveredOrderId . ' LIMIT 1')->fetchColumn();
        $productName = (string) $this->pdo->query('SELECT product_name FROM order_items WHERE order_id=' . $this->deliveredOrderId . ' LIMIT 1')->fetchColumn();
        $categoryImage = $this->pdo->query(
            'SELECT media.public_path FROM product_categories pc '
            . 'JOIN categories category ON category.id=pc.category_id '
            . 'JOIN media ON media.id=category.image_media_id '
            . 'WHERE pc.product_id=' . $productId . ' AND media.public_path IS NOT NULL LIMIT 1'
        )->fetchColumn();
        self::assertIsString($categoryImage);
        $this->pdo->exec('DELETE FROM product_images WHERE product_id=' . $productId);
        $this->pdo->exec('UPDATE product_variants SET image_media_id=NULL WHERE product_id=' . $productId);

        $page = $this->get('/admin/comenzi/' . $this->deliveredOrderId . '/');
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();

        self::assertStringContainsString('Detaliile comenzii', $html);
        self::assertStringContainsString('Produsele comandate', $html);
        self::assertStringContainsString('Contact și livrare', $html);
        self::assertStringContainsString('Istoricul comenzii', $html);
        self::assertStringContainsString('data-order-modal-open', $html);
        self::assertStringContainsString('data-order-lightbox', $html);
        self::assertStringContainsString('data-order-image-open', $html);
        self::assertStringContainsString((string) $categoryImage, $html);
        self::assertStringContainsString('alt="' . htmlspecialchars($productName, ENT_QUOTES) . '"', $html);
        self::assertStringContainsString('target="_blank" rel="noopener"', $html);
        self::assertStringContainsString('SKU-ADMIN-TEST', $html);
        self::assertStringNotContainsString('Număr AWB', $html);
        self::assertStringNotContainsString('order-action--return', $html);
        self::assertStringContainsString('assets/css/order-admin.css', $html);
        self::assertStringContainsString('assets/js/order-admin.js', $html);

        $returnModal = $this->get('/admin/comenzi/' . $this->deliveredOrderId . '/retur/modal/', ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $returnModal->getStatusCode());
        self::assertStringContainsString('Înregistrează un retur', (string) $returnModal->getBody());
        self::assertStringContainsString('name="quantities[', (string) $returnModal->getBody());
        self::assertStringContainsString('Această acțiune nu transferă bani', (string) $returnModal->getBody());

        $emailModal = $this->get('/admin/comenzi/' . $this->deliveredOrderId . '/email/modal/', ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $emailModal->getStatusCode());
        self::assertStringContainsString('Trimite actualizarea', (string) $emailModal->getBody());
    }

    public function testDirectContactLinksNormalizePhoneNumbersAndNeverPrefillWhatsAppMessages(): void
    {
        $update = $this->pdo->prepare('UPDATE orders SET phone=? WHERE id=?');
        foreach ([
            ['0730000344', '40730000344'],
            [' (0730) 000-344 ', '40730000344'],
            ["+40\u{00A0}(730) 000.344", '40730000344'],
            ['0040 730-000-344', '40730000344'],
            ['40730000344', '40730000344'],
            ['021 234 5678', '40212345678'],
            ['+44 (7700) 900-123', '447700900123'],
            ['0033 6 12 34 56 78', '33612345678'],
            ['+1 (202) 555-0198', '12025550198'],
            ['7700900123', null],
            ['07700900123', null],
            ['0730', null],
            ['+40730', null],
            ['+40 (0) 730 000 344', null],
            ['+123', null],
            ['+1234567890123456', null],
            ['++40 730 000 344', null],
            ['0730000344 ext 2', null],
            ['0730000344?text=Salut', null],
            ['', null],
        ] as [$phone, $expectedNumber]) {
            $update->execute([$phone, $this->newOrderId]);
            $page = $this->get('/admin/comenzi/' . $this->newOrderId . '/');
            self::assertSame(200, $page->getStatusCode());
            self::assertSame(1, preg_match('~<article[^>]*class="[^"]*order-contact--phone[^"]*"[^>]*>.*?</article>~s', (string) $page->getBody(), $matches));
            $contact = $matches[0];
            self::assertStringContainsString('aria-label="Copiază telefonul"', $contact);
            if ($phone === '') {
                self::assertStringNotContainsString('Apelează', $contact);
            } else {
                self::assertStringContainsString('Apelează', $contact);
                self::assertStringContainsString('href="tel:' . htmlspecialchars(trim($phone), ENT_QUOTES) . '"', $contact);
            }
            if ($expectedNumber === null) {
                self::assertStringNotContainsString('https://wa.me/', $contact, $phone);
                continue;
            }
            self::assertStringContainsString('href="tel:+' . $expectedNumber . '"', $contact);
            self::assertStringContainsString('href="https://wa.me/' . $expectedNumber . '" target="_blank" rel="noopener noreferrer"', $contact);
            self::assertSame(1, preg_match('~href="(https://wa\.me/[^"]+)"~', $contact, $whatsapp));
            self::assertNull(parse_url($whatsapp[1], PHP_URL_QUERY));
        }
    }

    public function testStatusWorkflowChangesStateAuditsAndQueuesCustomerEmail(): void
    {
        $modal = $this->get('/admin/comenzi/' . $this->newOrderId . '/status/modal/', ['X-Requested-With' => 'XMLHttpRequest']);
        $markup = (string) $modal->getBody();
        self::assertSame(200, $modal->getStatusCode());
        self::assertStringContainsString('Confirmă comanda', $markup);
        self::assertStringContainsString('Anulează comanda', $markup);
        self::assertStringNotContainsString('name="awb"', $markup);

        $response = $this->post('/admin/comenzi/' . $this->newOrderId . '/actiune/', [
            '_csrf' => $_SESSION['_csrf'],
            'action' => 'confirm',
            'reason' => 'Datele au fost verificate telefonic.',
            'notify_customer' => '1',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('confirmed', $this->pdo->query('SELECT status FROM orders WHERE id=' . $this->newOrderId)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='order' AND entity_id={$this->newOrderId} AND action='order.confirm'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outbox_messages WHERE message_type='order_status_update' AND recipient='alexie.orders@example.test'")->fetchColumn());

        $cancel = $this->post('/admin/comenzi/' . $this->newOrderId . '/actiune/', [
            '_csrf' => $_SESSION['_csrf'], 'action' => 'cancel', 'reason' => 'Solicitare client.',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $cancel->getStatusCode());
        self::assertSame('cancelled', $this->pdo->query('SELECT status FROM orders WHERE id=' . $this->newOrderId)->fetchColumn());
    }

    public function testHistoryExplainsActorsPaymentEventsAndLocalTimesWithoutDuplicatingPlacement(): void
    {
        $this->pdo->prepare("UPDATE orders SET created_at='2026-11-08T08:00:00+00:00' WHERE id=?")->execute([$this->newOrderId]);
        $audit = $this->pdo->prepare("INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,context_json,created_at) VALUES (?,?,'order',?,?,?)");
        foreach ([
            [null, 'order.created', [], '2026-11-08T08:00:00+00:00'],
            [null, 'order.confirm', ['source'=>'stripe'], '2026-11-08T08:01:00+00:00'],
            [null, 'order.cancellation_requested', ['reason'=>'Am ales <altă mărime> & alt model.'], '2026-11-08T08:02:00+00:00'],
            [$this->adminId, 'order.cancellation_rejected', ['reason'=>'Coletul a fost predat curierului.'], '2026-11-08T08:03:00+00:00'],
            [null, 'stripe.session_failed', [], '2026-11-08T08:04:00+00:00'],
        ] as [$actor, $action, $context, $date]) {
            $audit->execute([$actor, $action, $this->newOrderId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $date]);
        }
        $page = $this->get('/admin/comenzi/' . $this->newOrderId . '/');
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(1, preg_match('~<section[^>]*aria-labelledby="order-history-title"[^>]*>.*?</section>~s', (string) $page->getBody(), $matches));
        $history = $matches[0];
        self::assertSame(1, substr_count($history, 'Comandă plasată'));
        self::assertStringContainsString('08.11.2026', $history);
        foreach (['10:00', '10:01', '10:02', '10:03', '10:04'] as $time) {
            self::assertStringContainsString($time, $history);
        }
        self::assertStringContainsString('Plată primită și comandă confirmată', $history);
        self::assertStringContainsString('Inițierea plății cu cardul a eșuat', $history);
        self::assertStringContainsString('Motivul clientului', $history);
        self::assertStringContainsString('Am ales &lt;altă mărime&gt; &amp; alt model.', $history);
        self::assertStringNotContainsString('<altă mărime>', $history);
        $text = html_entity_decode(strip_tags($history), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $requestPosition = strpos($text, 'Clientul a solicitat anularea');
        $decisionPosition = strpos($text, 'Solicitarea de anulare a fost respinsă');
        $failurePosition = strpos($text, 'Inițierea plății cu cardul a eșuat');
        self::assertIsInt($requestPosition);
        self::assertIsInt($decisionPosition);
        self::assertIsInt($failurePosition);
        self::assertGreaterThan($requestPosition, $decisionPosition);
        self::assertGreaterThan($decisionPosition, $failurePosition);
        self::assertStringContainsString('Client', substr($text, $requestPosition, $decisionPosition - $requestPosition));
        self::assertStringContainsString('Maria Admin', substr($text, $decisionPosition, $failurePosition - $decisionPosition));
        self::assertStringContainsString('Sistem', substr($text, $failurePosition));
    }

    public function testPartialAndFullReturnRespectQuantitiesRestoreStockAndRecordRefund(): void
    {
        $itemId = (int) $this->pdo->query('SELECT id FROM order_items WHERE order_id=' . $this->deliveredOrderId)->fetchColumn();
        $stockBefore = (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn();

        $partial = $this->post('/admin/comenzi/' . $this->deliveredOrderId . '/retur/', [
            '_csrf' => $_SESSION['_csrf'],
            'quantities' => [$itemId => '1'],
            'reason' => 'Mărime nepotrivită',
            'notes' => 'Cutia a fost verificată la recepție.',
            'restock' => '1',
            'refund_mode' => 'partial',
            'refund_amount' => '100,00',
            'notify_customer' => '1',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame(200, $partial->getStatusCode(), (string) $partial->getBody());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM order_returns WHERE order_id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT quantity FROM order_return_items WHERE order_item_id=' . $itemId)->fetchColumn());
        self::assertSame($stockBefore + 1, (int) $this->pdo->query('SELECT physical_quantity FROM inventory WHERE variant_id=' . $this->variantId)->fetchColumn());
        self::assertSame('partially_refunded', $this->pdo->query('SELECT payment_status FROM orders WHERE id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame(10000, (int) $this->pdo->query('SELECT refunded_bani FROM payments WHERE order_id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame('delivered', $this->pdo->query('SELECT status FROM orders WHERE id=' . $this->deliveredOrderId)->fetchColumn());

        $tooMuch = $this->post('/admin/comenzi/' . $this->deliveredOrderId . '/retur/', [
            '_csrf' => $_SESSION['_csrf'], 'quantities' => [$itemId => '2'], 'reason' => 'Cantitate invalidă', 'refund_mode' => 'none',
        ], ['Accept' => 'application/json']);
        self::assertSame(422, $tooMuch->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM order_returns WHERE order_id=' . $this->deliveredOrderId)->fetchColumn());

        $full = $this->post('/admin/comenzi/' . $this->deliveredOrderId . '/retur/', [
            '_csrf' => $_SESSION['_csrf'], 'quantities' => [$itemId => '1'], 'reason' => 'Retur final solicitat', 'refund_mode' => 'full',
        ], ['Accept' => 'application/json']);
        self::assertSame(200, $full->getStatusCode(), (string) $full->getBody());
        self::assertSame('returned', $this->pdo->query('SELECT status FROM orders WHERE id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame('refunded', $this->pdo->query('SELECT payment_status FROM orders WHERE id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame(30000, (int) $this->pdo->query('SELECT refunded_bani FROM payments WHERE order_id=' . $this->deliveredOrderId)->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COALESCE(SUM(quantity),0) FROM order_return_items WHERE order_item_id=' . $itemId)->fetchColumn());
    }

    /** @param array<string,mixed> $catalog */
    private function createOrder(string $number, string $status, string $paymentStatus, array $catalog): int
    {
        $now = gmdate('c');
        $cartToken = strtolower($number) . '-' . bin2hex(random_bytes(3));
        $this->pdo->prepare("INSERT INTO carts (token,status,created_at,updated_at,expires_at) VALUES (:token,'converted',:now,:now,:expires)")->execute([
            'token' => $cartToken, 'now' => $now, 'expires' => gmdate('c', time() + 86400),
        ]);
        $cartId = (int) $this->pdo->lastInsertId();
        $address = json_encode(['address' => 'Strada Atelierului 7', 'city' => 'Timișoara', 'county' => 'Timiș', 'postal_code' => '300001'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $order = $this->pdo->prepare(
            'INSERT INTO orders (order_number,public_token_hash,idempotency_key,cart_id,user_id,email,first_name,last_name,phone,customer_type,company_json,shipping_address_json,billing_address_json,subtotal_bani,discount_bani,shipping_bani,tax_bani,total_bani,currency,status,payment_status,payment_method,shipping_method,awb,internal_notes,terms_accepted_at,marketing_consent,created_at,updated_at) '
            . "VALUES (:number,:token,:idempotency,:cart,NULL,'alexie.orders@example.test','Alexie','Popescu','0730000000','person','{}',:shipping,:billing,30000,0,0,0,30000,'RON',:status,:payment_status,'stripe','manual',:awb,'Comandă pentru testarea CRM.',:now,0,:now,:now)"
        );
        $order->execute([
            'number' => $number,
            'token' => hash('sha256', $number . '-public'),
            'idempotency' => hash('sha256', $number . '-idempotency'),
            'cart' => $cartId,
            'shipping' => $address,
            'billing' => $address,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'awb' => $status === 'delivered' ? 'AWB-ADMIN-TEST' : null,
            'now' => $now,
        ]);
        $orderId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO order_items (order_id,product_id,variant_id,product_name,variant_summary,sku,quantity,unit_price_bani,personalization_bani,line_total_bani,tax_bani,personalization_json) "
            . "VALUES (:order,:product,:variant,:name,'Mărime: 2 ani','SKU-ADMIN-TEST',2,15000,0,30000,0,'{}')"
        )->execute(['order' => $orderId, 'product' => $catalog['product_id'], 'variant' => $catalog['variant_id'], 'name' => $catalog['name']]);
        $this->pdo->prepare("INSERT INTO payments (order_id,provider,provider_payment_id,amount_bani,refunded_bani,currency,status,created_at,updated_at) VALUES (:order,'stripe',:provider_id,30000,0,'RON',:status,:now,:now)")->execute([
            'order' => $orderId, 'provider_id' => 'pi_' . strtolower(str_replace('-', '_', $number)), 'status' => $paymentStatus, 'now' => $now,
        ]);

        return $orderId;
    }

    /** @param array<string,string> $headers */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, [], $headers);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function post(string $path, array $data, array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.98'])->withHeader('User-Agent', 'LaMariuca-OrderAdmin-Test/1.0');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
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
