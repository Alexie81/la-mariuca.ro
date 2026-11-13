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

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = Config::load($root);
$pdo = ConnectionFactory::create($config, $root);
$command = $argv[1] ?? 'help';

try {
    switch ($command) {
        case 'invoices:process':
            $result = \App\Modules\Invoicing\InvoiceModule::process($pdo, $root, (int) ($argv[2] ?? 20));
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
            break;
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
        case 'outbox:work':
            $directory = $root . '/storage/private';
            if (!is_dir($directory)) { mkdir($directory, 0770, true); }
            $lock = fopen($directory . '/outbox-worker.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Un proces de email rulează deja pentru acest magazin.');
            }
            $interval = max(1, min(60, (int) ($argv[2] ?? 5)));
            $processor = new \App\Shared\Mail\OutboxProcessor($pdo, $config, $root);
            $deliveryMode = \App\Shared\Mail\MailDeliveryPolicy::resolve($config);
            if ($command === 'outbox:work') {
                fwrite(STDOUT, 'Procesor email pornit; mod=' . $deliveryMode . '; verificare la ' . $interval . " secunde.\n");
            }
            try {
                do {
                    $result = $processor->process();
                    if ($command === 'outbox:process' || $result['processed'] > 0) {
                        fwrite(STDOUT, sprintf("Email: %d procesate, %d acceptate SMTP, %d preview-uri, %d eșuate.\n", $result['processed'], $result['sent'], $result['previewed'], $result['failed']));
                    }
                    if ($command === 'outbox:work') { sleep($interval); }
                } while ($command === 'outbox:work');
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
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
            fwrite(STDOUT, "Comenzi: migrate, seed:demo, seed:catalog, seed:orders-demo [număr], seed:customer-orders-demo <email-client> [număr], seed:newsletter-demo [număr], review:demo [slug], review:demo-pack [slug], admin:create, invoices:process [limită], outbox:process, outbox:work [interval-secunde], push:process [limită], reservations:expire, csv:export [fișier], csv:import <fișier> --dry-run\n");
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $safeMessage = str_starts_with($command, 'outbox:') ? 'Procesorul de email nu a putut continua. Verifică configurația privată și dacă rulează deja.' : $exception->getMessage();
    fwrite(STDERR, 'Eroare: ' . $safeMessage . "\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}
