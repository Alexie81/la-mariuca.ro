<?php

declare(strict_types=1);

use App\Shared\Config\Config;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Database\Migrator;
use Database\Seeders\DemoSeeder;
use Database\Seeders\AdminOrderDemoSeeder;
use Database\Seeders\ExpandedCatalogSeeder;
use Database\Seeders\NewsletterDemoSeeder;
use App\Modules\Reviews\ProductReviewRepository;
use App\Modules\Admin\AdminPushService;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = Config::load($root);
$pdo = ConnectionFactory::create($config, $root);
$command = $argv[1] ?? 'help';

try {
    switch ($command) {
        case 'migrate':
            $migrations = (new Migrator($pdo, $root))->migrate();
            fwrite(STDOUT, $migrations === [] ? "Baza este la zi.\n" : "Aplicat: " . implode(', ', $migrations) . "\n");
            break;
        case 'seed:demo':
            (new DemoSeeder($pdo))->run();
            fwrite(STDOUT, "Datele demo au fost verificate/încărcate.\n");
            break;
        case 'seed:catalog':
            $inserted = (new ExpandedCatalogSeeder($pdo))->run();
            fwrite(STDOUT, "Colecția extinsă a fost verificată; produse noi adăugate: {$inserted}.\n");
            break;
        case 'seed:orders-demo':
            $count = max(1, min(100, (int) ($argv[2] ?? 25)));
            $inserted = (new AdminOrderDemoSeeder($pdo))->run($count);
            fwrite(STDOUT, "Comenzi demonstrative adăugate: {$inserted}.\n");
            break;
        case 'seed:customer-orders-demo':
            $email = mb_strtolower(trim((string) ($argv[2] ?? '')));
            $count = max(1, min(100, (int) ($argv[3] ?? 5)));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Folosește: seed:customer-orders-demo <email-client> [număr]');
            }
            $customer = $pdo->prepare("SELECT id FROM users WHERE role='customer' AND LOWER(email)=:email LIMIT 1");
            $customer->execute(['email' => $email]);
            $customerId = $customer->fetchColumn();
            if ($customerId === false) {
                throw new RuntimeException('Clientul nu a fost găsit.');
            }
            $inserted = (new AdminOrderDemoSeeder($pdo))->run($count, (int) $customerId);
            fwrite(STDOUT, "Comenzi demonstrative asociate clientului: {$inserted}.\n");
            break;
        case 'seed:newsletter-demo':
            $count = max(1, min(60, (int) ($argv[2] ?? 18)));
            $inserted = (new NewsletterDemoSeeder($pdo))->run($count);
            fwrite(STDOUT, "Abonați newsletter demo adăugați: {$inserted}.\n");
            break;
        case 'review:demo':
            $slug = trim((string) ($argv[2] ?? 'cuburi-moi-poveste'));
            $reviewId = (new ProductReviewRepository($pdo))->ensureDemoForProductSlug($slug);
            fwrite(STDOUT, "Recenzia demonstrativă este disponibilă pentru {$slug} (ID {$reviewId}).\n");
            break;
        case 'review:demo-pack':
            $slug = trim((string) ($argv[2] ?? 'cuburi-moi-poveste'));
            $reviewIds = (new ProductReviewRepository($pdo))->ensureDemoPackForProductSlug($slug);
            fwrite(STDOUT, 'Setul demonstrativ este disponibil pentru ' . $slug . ' (' . count($reviewIds) . " recenzii).\n");
            break;
        case 'admin:create':
            $email = mb_strtolower(trim((string) (getenv('ADMIN_EMAIL') ?: prompt('Email administrator: '))));
            $firstName = trim((string) (getenv('ADMIN_FIRST_NAME') ?: prompt('Prenume: ')));
            $lastName = trim((string) (getenv('ADMIN_LAST_NAME') ?: prompt('Nume: ')));
            $password = (string) (getenv('ADMIN_PASSWORD') ?: prompt('Parolă (minimum 14 caractere): '));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 14 || $firstName === '' || $lastName === '') {
                throw new RuntimeException('Date invalide. Parola trebuie să aibă minimum 14 caractere.');
            }
            $now = gmdate('c');
            $statement = $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES (:email,:password,:first_name,:last_name,'administrator','active',:now,:now,:now)");
            $statement->execute(['email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'first_name' => $firstName, 'last_name' => $lastName, 'now' => $now]);
            fwrite(STDOUT, "Administratorul a fost creat. Activează TOTP înainte de producție.\n");
            break;
        case 'outbox:process':
            $limit = 20;
            $statement = $pdo->prepare("SELECT * FROM outbox_messages WHERE status IN ('pending','failed') AND available_at <= :now AND attempts < 5 ORDER BY id LIMIT {$limit}");
            $statement->execute(['now' => gmdate('c')]);
            $messages = $statement->fetchAll();
            $transport = null;
            if ($config->get('APP_ENV') === 'production') {
                $dsn = $config->get('MAILER_DSN');
                if ($dsn === '' || $dsn === 'null://null') {
                    throw new RuntimeException('MAILER_DSN nu este configurat pentru producție.');
                }
                $transport = Transport::fromDsn($dsn);
            }
            foreach ($messages as $message) {
                $pdo->prepare("UPDATE outbox_messages SET status='processing',attempts=attempts+1 WHERE id=:id AND status IN ('pending','failed')")->execute(['id' => $message['id']]);
                try {
                    $payload = json_decode((string) $message['payload_json'], true) ?: [];
                    $statusLabels = [
                        'new' => 'nouă', 'confirmed' => 'confirmată', 'preparing' => 'în pregătire',
                        'shipped' => 'expediată', 'delivered' => 'livrată', 'cancelled' => 'anulată',
                        'returned' => 'returnată', 'manual_review' => 'în verificare',
                    ];
                    if ($message['message_type'] === 'order_confirmation') {
                        $subject = 'Confirmarea comenzii La Măriuca';
                        $body = 'Comanda a fost înregistrată. Număr intern: ' . (int) ($payload['order_id'] ?? 0);
                    } elseif ($message['message_type'] === 'order_status_update') {
                        $orderNumber = (string) ($payload['order_number'] ?? ('#' . (int) ($payload['order_id'] ?? 0)));
                        $status = $statusLabels[(string) ($payload['status'] ?? '')] ?? (string) ($payload['status'] ?? 'actualizată');
                        $subject = 'Actualizare pentru comanda ' . $orderNumber;
                        $body = 'Bună, ' . (string) ($payload['customer_name'] ?? '') . "!\n\n"
                            . 'Comanda ' . $orderNumber . ' este acum ' . $status . '.';
                        if (trim((string) ($payload['message'] ?? '')) !== '') {
                            $body .= "\n\n" . trim((string) $payload['message']);
                        }
                        $body .= "\n\nÎți mulțumim,\nEchipa La Măriuca Boutique";
                    } else {
                        $subject = 'Verifică adresa de email';
                        $body = 'Folosește tokenul de verificare transmis securizat de aplicație.';
                    }
                    if ($transport !== null) {
                        $email = (new Email())->from($config->get('MAIL_FROM_ADDRESS', 'no-reply@example.test'))->to((string) $message['recipient'])->subject($subject)->text($body);
                        (new Mailer($transport))->send($email);
                    } else {
                        $mailDir = $root . '/storage/private/mail';
                        if (!is_dir($mailDir)) {
                            mkdir($mailDir, 0770, true);
                        }
                        file_put_contents($mailDir . '/' . (int) $message['id'] . '.txt', "To: " . $message['recipient'] . "\nSubject: {$subject}\n\n{$body}\n", LOCK_EX);
                    }
                    $pdo->prepare("UPDATE outbox_messages SET status='sent',sent_at=:now,last_error=NULL WHERE id=:id")->execute(['now' => gmdate('c'), 'id' => $message['id']]);
                } catch (Throwable $exception) {
                    $pdo->prepare("UPDATE outbox_messages SET status='failed',available_at=:retry,last_error=:error WHERE id=:id")->execute(['retry' => gmdate('c', time() + 300), 'error' => mb_substr($exception->getMessage(), 0, 500), 'id' => $message['id']]);
                }
            }
            fwrite(STDOUT, 'Procesate: ' . count($messages) . " mesaje.\n");
            break;
        case 'push:process':
            $limit = max(1, min(200, (int) ($argv[2] ?? 50)));
            $result = (new AdminPushService($pdo, $config))->processPending($limit);
            fwrite(STDOUT, sprintf("Web Push: %d procesate, %d trimise, %d eșuate, %d abonamente expirate.\n", $result['processed'], $result['sent'], $result['failed'], $result['expired']));
            break;
        case 'reservations:expire':
            $pdo->beginTransaction();
            $now = gmdate('c');
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $statement = $pdo->prepare("SELECT r.order_id FROM inventory_reservations r JOIN orders o ON o.id=r.order_id WHERE r.status='active' AND r.expires_at IS NOT NULL AND r.expires_at<:now AND o.payment_status='pending' ORDER BY r.order_id");
            $statement->execute(['now' => $now]);
            $count = 0;
            $expiredOrderIds = array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))));
            foreach ($expiredOrderIds as $orderId) {
                $orderLock = $pdo->prepare('SELECT id,payment_status FROM orders WHERE id=:id' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $orderLock->execute(['id' => $orderId]);
                $order = $orderLock->fetch();
                if (!is_array($order) || (string) $order['payment_status'] !== 'pending') {
                    continue;
                }
                $reservationLock = $pdo->prepare("SELECT * FROM inventory_reservations WHERE order_id=:order AND status='active' ORDER BY id" . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $reservationLock->execute(['order' => $orderId]);
                $reservations = $reservationLock->fetchAll();
                $hasExpiredReservation = false;
                foreach ($reservations as $reservation) {
                    if ($reservation['expires_at'] !== null && (string) $reservation['expires_at'] < $now) {
                        $hasExpiredReservation = true;
                        break;
                    }
                }
                if (!$hasExpiredReservation) {
                    continue;
                }
                foreach ($reservations as $reservation) {
                    $releaseInventory = $pdo->prepare('UPDATE inventory SET reserved_quantity=reserved_quantity-:quantity_delta,updated_at=:now WHERE variant_id=:variant AND reserved_quantity>=:quantity_check');
                    $releaseInventory->execute(['quantity_delta' => $reservation['quantity'], 'quantity_check' => $reservation['quantity'], 'now' => $now, 'variant' => $reservation['variant_id']]);
                    if ($releaseInventory->rowCount() !== 1) {
                        throw new RuntimeException('Stoc inconsistent la expirarea rezervării #' . (int) $reservation['id'] . '.');
                    }
                    $releaseReservation = $pdo->prepare("UPDATE inventory_reservations SET status='released',updated_at=:now WHERE id=:id AND status='active'");
                    $releaseReservation->execute(['now' => $now, 'id' => $reservation['id']]);
                    if ($releaseReservation->rowCount() !== 1) {
                        throw new RuntimeException('Rezervarea #' . (int) $reservation['id'] . ' nu mai este activă.');
                    }
                    $pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,reference_type,reference_id,created_at) VALUES (:variant,'release',0,:reserved,'Expirare rezervare plată','order',:order,:now)")
                        ->execute(['variant' => $reservation['variant_id'], 'reserved' => -((int) $reservation['quantity']), 'order' => $orderId, 'now' => $now]);
                    $count++;
                }
                $cancelOrder = $pdo->prepare("UPDATE orders SET status='cancelled',cancelled_at=COALESCE(cancelled_at,:now),updated_at=:now WHERE id=:id AND payment_status='pending'");
                $cancelOrder->execute(['now' => $now, 'id' => $orderId]);
                if ($cancelOrder->rowCount() !== 1) {
                    throw new RuntimeException('Comanda #' . $orderId . ' nu mai poate fi anulată pentru expirare.');
                }
                $pdo->prepare("UPDATE coupon_redemptions SET status='released',updated_at=:now WHERE order_id=:order AND status='reserved'")
                    ->execute(['now' => $now, 'order' => $orderId]);
            }
            $pdo->commit();
            fwrite(STDOUT, "Rezervări expirate: {$count}. Plățile tardive trebuie reconciliate separat.\n");
            break;
        case 'csv:export':
            $path = $argv[2] ?? ($root . '/storage/private/products-export.csv');
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Nu se poate crea exportul.');
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['product_slug','product_name','status','category_slug','sku','ean','option_summary','price_ron','physical_stock'], ';');
            $rows = $pdo->query('SELECT p.slug product_slug,p.name product_name,p.status,c.slug category_slug,v.sku,v.ean,v.option_summary,v.price_bani,i.physical_quantity FROM products p JOIN categories c ON c.id=p.primary_category_id JOIN product_variants v ON v.product_id=p.id LEFT JOIN inventory i ON i.variant_id=v.id ORDER BY p.id,v.id')->fetchAll();
            foreach ($rows as $row) {
                $safe = static fn(string $value): string => preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
                fputcsv($handle, [$safe($row['product_slug']),$safe($row['product_name']),$row['status'],$row['category_slug'],$safe($row['sku']),$safe((string) $row['ean']),$row['option_summary'],intdiv((int) $row['price_bani'],100) . ',' . str_pad((string) ((int) $row['price_bani']%100),2,'0',STR_PAD_LEFT),$row['physical_quantity']], ';');
            }
            fclose($handle);
            fwrite(STDOUT, "Export creat: {$path}\n");
            break;
        case 'csv:import':
            $path = $argv[2] ?? '';
            $dryRun = in_array('--dry-run', $argv, true);
            if (!is_file($path)) {
                throw new RuntimeException('Specifică un fișier CSV existent.');
            }
            $handle = fopen($path, 'rb');
            $header = fgetcsv($handle, 0, ';');
            $required = ['product_slug','product_name','status','category_slug','sku','price_ron','physical_stock'];
            if (!is_array($header) || array_diff($required, $header) !== []) {
                throw new RuntimeException('Antet CSV invalid.');
            }
            $line = 1; $valid = 0; $errors = [];
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                $line++;
                if (count($row) !== count($header)) {
                    $errors[] = "Linia {$line}: număr de coloane invalid.";
                    continue;
                }
                $data = array_combine($header, $row);
                if (trim((string) $data['sku']) === '' || !preg_match('/^\d+(?:[,.]\d{1,2})?$/', (string) $data['price_ron']) || filter_var($data['physical_stock'], FILTER_VALIDATE_INT) === false) {
                    $errors[] = "Linia {$line}: SKU, preț sau stoc invalid.";
                    continue;
                }
                $category = $pdo->prepare('SELECT id FROM categories WHERE slug=:slug'); $category->execute(['slug' => $data['category_slug']]);
                if ($category->fetchColumn() === false) {
                    $errors[] = "Linia {$line}: categoria nu există.";
                    continue;
                }
                $valid++;
            }
            fclose($handle);
            fwrite(STDOUT, ($dryRun ? 'Dry-run' : 'Validare') . ": {$valid} rânduri valide, " . count($errors) . " erori.\n");
            foreach ($errors as $error) { fwrite(STDERR, $error . "\n"); }
            if (!$dryRun) { fwrite(STDOUT, "Importul cu scriere este intenționat blocat până la confirmarea mapării; folosește --dry-run.\n"); }
            break;
        case 'help':
        default:
            fwrite(STDOUT, "Comenzi: migrate, seed:demo, seed:catalog, seed:orders-demo [număr], seed:customer-orders-demo <email-client> [număr], seed:newsletter-demo [număr], review:demo [slug], review:demo-pack [slug], admin:create, outbox:process, push:process [limită], reservations:expire, csv:export [fișier], csv:import <fișier> --dry-run\n");
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Eroare: ' . $exception->getMessage() . "\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}
