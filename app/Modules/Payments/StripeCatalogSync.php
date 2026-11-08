<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Shared\Config\Config;
use PDO;
use RuntimeException;
use Stripe\StripeClient;

final class StripeCatalogSync
{
    private readonly StripeCatalogGateway $api;
    private ?string $accountId = null;

    public function __construct(private readonly PDO $pdo, private readonly Config $config, ?StripeCatalogGateway $api = null)
    {
        $this->api = $api ?? new StripeCatalogApi(new StripeClient(['api_key' => $config->get('STRIPE_SECRET_KEY'), 'max_network_retries' => 2]));
    }

    /** @return array{synced:int,failed:int,disabled:bool} */
    public function process(int $limit = 20): array
    {
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Catalog sync must run outside a database transaction.'); }
        if (!$this->config->bool('STRIPE_CATALOG_ENABLED') || !$this->config->stripeConfigured()) {
            return ['synced' => 0, 'failed' => 0, 'disabled' => true];
        }
        $account = $this->accountId ??= $this->api->accountId();
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $account)) { throw new RuntimeException('Contul Stripe nu a putut fi identificat.'); }
        $scope = $account . ':' . $this->config->stripeMode();
        $now = gmdate('c');
        $query = $this->pdo->prepare('SELECT c.product_id,c.revision FROM stripe_catalog_changes c LEFT JOIN stripe_catalog_products p ON p.product_id=c.product_id AND p.scope=:scope WHERE (p.product_id IS NULL OR (p.synced_revision<c.revision AND p.next_attempt_at<=:now AND (p.lease_token IS NULL OR p.lease_expires_at<=:expired))) ORDER BY c.updated_at,c.product_id LIMIT ' . max(1, min(200, $limit)));
        $query->execute(['scope' => $scope, 'now' => $now, 'expired' => $now]);
        $jobs = $query->fetchAll(PDO::FETCH_ASSOC);
        $result = ['synced' => 0, 'failed' => 0, 'disabled' => false];
        foreach ($jobs as $job) {
            $id = (int) $job['product_id'];
            $revision = (int) $job['revision'];
            $remoteId = 'lm_' . substr(hash('sha256', $scope . ':' . $this->config->get('APP_KEY') . ':' . $id), 0, 32);
            $insert = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'INSERT IGNORE INTO stripe_catalog_products (scope,product_id,stripe_product_id,next_attempt_at,updated_at) VALUES (:scope,:id,:remote,:now,:updated)'
                : 'INSERT INTO stripe_catalog_products (scope,product_id,stripe_product_id,next_attempt_at,updated_at) VALUES (:scope,:id,:remote,:now,:updated) ON CONFLICT(scope,product_id) DO NOTHING';
            $this->pdo->prepare($insert)->execute(['scope' => $scope, 'id' => $id, 'remote' => $remoteId, 'now' => $now, 'updated' => $now]);
            $token = bin2hex(random_bytes(16));
            $claim = $this->pdo->prepare('UPDATE stripe_catalog_products SET lease_token=:token,lease_expires_at=:expires WHERE scope=:scope AND product_id=:id AND synced_revision<:revision AND next_attempt_at<=:now AND (lease_token IS NULL OR lease_expires_at<=:expired)');
            $claim->execute(['token' => $token, 'expires' => gmdate('c', time() + 600), 'scope' => $scope, 'id' => $id, 'revision' => $revision, 'now' => $now, 'expired' => $now]);
            if ($claim->rowCount() !== 1) { continue; }
            try {
                $mapping = $this->pdo->prepare('SELECT stripe_product_id FROM stripe_catalog_products WHERE scope=:scope AND product_id=:id');
                $mapping->execute(['scope' => $scope, 'id' => $id]);
                $remoteId = (string) $mapping->fetchColumn();
                $this->syncProduct($scope, $id, $remoteId, $revision, $token);
                $this->pdo->prepare('UPDATE stripe_catalog_products SET synced_revision=:revision,attempts=0,last_error=NULL,next_attempt_at=:now,lease_token=NULL,lease_expires_at=NULL,updated_at=:updated WHERE scope=:scope AND product_id=:id AND lease_token=:token')
                    ->execute(['revision' => $revision, 'now' => gmdate('c'), 'updated' => gmdate('c'), 'scope' => $scope, 'id' => $id, 'token' => $token]);
                ++$result['synced'];
            } catch (\Throwable $error) {
                // Do not store API exception text: it can contain request values or credentials.
                $message = $error instanceof \Stripe\Exception\ApiErrorException
                    ? 'Stripe API: ' . preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($error->getStripeCode() ?? 'request_failed'))
                    : 'Sincronizarea nu a reușit. Se reîncearcă automat.';
                $this->pdo->prepare('UPDATE stripe_catalog_products SET attempts=attempts+1,last_error=:error,next_attempt_at=:next,lease_token=NULL,lease_expires_at=NULL,updated_at=:now WHERE scope=:scope AND product_id=:id AND lease_token=:token')
                    ->execute(['error' => $message, 'next' => gmdate('c', time() + 60), 'now' => gmdate('c'), 'scope' => $scope, 'id' => $id, 'token' => $token]);
                ++$result['failed'];
            }
        }
        return $result;
    }

    private function syncProduct(string $scope, int $id, string $remoteId, int $revision, string $token): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id=:id');
        $statement->execute(['id' => $id]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        $published = is_array($product) && $product['status'] === 'published';
        $images = [];
        if (is_array($product)) {
            $query = $this->pdo->prepare('SELECT m.public_path FROM product_images i JOIN media m ON m.id=i.media_id WHERE i.product_id=:id ORDER BY i.position,i.media_id LIMIT 8');
            $query->execute(['id' => $id]);
            $resolver = new StripePublicImage($this->config);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $url = $resolver->url($path);
                if ($url !== null) { $images[] = $url; }
            }
        }
        $data = [
            'name' => is_array($product) ? mb_substr((string) $product['name'], 0, 250) : 'Produs arhivat ' . $id,
            'description' => is_array($product) ? mb_substr(strip_tags((string) $product['short_description']), 0, 1000) : '',
            'active' => $published,
            'images' => $images === [] ? '' : $images,
            'metadata' => ['source' => 'la_mariuca', 'product_id' => (string) $id, 'sku' => (string) ($product['primary_sku'] ?? '')],
        ];
        $this->heartbeat($scope, $id, $token);
        $this->api->saveProduct($remoteId, $data, 'catalog-product-' . hash('sha256', $remoteId . ':' . $revision . ':' . json_encode($data)));
        $variants = $this->pdo->prepare("SELECT * FROM product_variants WHERE product_id=:id AND status='active' ORDER BY is_default DESC,id");
        $variants->execute(['id' => $id]);
        $activePrices = [];
        if ($published) {
            foreach ($variants->fetchAll(PDO::FETCH_ASSOC) as $variant) {
                $this->heartbeat($scope, $id, $token);
                $currency = 'ron';
                $amount = (int) $variant['price_bani'];
                $variantId = (int) $variant['id'];
                $nickname = mb_substr(trim((string) $variant['sku'] . ' · ' . (string) $variant['option_summary']), 0, 200);
                $key = 'lm_price_' . hash('sha256', $remoteId . ':' . $variantId . ':' . $amount . ':' . $currency . ':' . $nickname);
                $query = $this->pdo->prepare('SELECT stripe_price_id FROM stripe_catalog_prices WHERE scope=:scope AND price_key=:key LIMIT 1');
                $query->execute(['scope' => $scope, 'key' => $key]);
                $priceId = $query->fetchColumn();
                if (!is_string($priceId)) {
                    $priceId = $this->api->findPrice($key) ?? $this->api->createPrice([
                        'product' => $remoteId, 'unit_amount' => $amount, 'currency' => $currency,
                        'nickname' => $nickname, 'lookup_key' => $key,
                        'metadata' => ['variant_id' => (string) $variantId, 'sku' => (string) $variant['sku']],
                    ], $key);
                    $this->pdo->prepare('INSERT INTO stripe_catalog_prices (scope,product_id,variant_id,stripe_price_id,price_key,amount_bani,currency,active,updated_at) VALUES (:scope,:product,:variant,:price,:key,:amount,:currency,1,:now)')
                        ->execute(['scope' => $scope, 'product' => $id, 'variant' => $variantId, 'price' => $priceId, 'key' => $key, 'amount' => $amount, 'currency' => $currency, 'now' => gmdate('c')]);
                }
                $this->api->setPriceActive($priceId, true);
                $this->pdo->prepare('UPDATE stripe_catalog_prices SET active=1,updated_at=:now WHERE scope=:scope AND stripe_price_id=:price')
                    ->execute(['now' => gmdate('c'), 'scope' => $scope, 'price' => $priceId]);
                $activePrices[] = $priceId;
            }
        }
        // Switch the default before archiving the former price, preserving all historical prices.
        if ($activePrices !== []) {
            $this->heartbeat($scope, $id, $token);
            $this->api->saveProduct($remoteId, ['default_price' => $activePrices[0]], 'catalog-default-' . hash('sha256', $remoteId . ':' . $revision . ':' . $activePrices[0]));
        }
        $query = $this->pdo->prepare('SELECT stripe_price_id FROM stripe_catalog_prices WHERE scope=:scope AND product_id=:product AND active=1');
        $query->execute(['scope' => $scope, 'product' => $id]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $priceId) {
            if (in_array($priceId, $activePrices, true)) { continue; }
            $this->heartbeat($scope, $id, $token);
            $this->api->setPriceActive((string) $priceId, false);
            $this->pdo->prepare('UPDATE stripe_catalog_prices SET active=0,updated_at=:now WHERE scope=:scope AND stripe_price_id=:price')
                ->execute(['now' => gmdate('c'), 'scope' => $scope, 'price' => $priceId]);
        }
    }

    private function heartbeat(string $scope, int $id, string $token): void
    {
        $query = $this->pdo->prepare('UPDATE stripe_catalog_products SET lease_expires_at=:expires WHERE scope=:scope AND product_id=:id AND lease_token=:token');
        $query->execute(['expires' => gmdate('c', time() + 600), 'scope' => $scope, 'id' => $id, 'token' => $token]);
        // MySQL can report zero changed rows when the timestamp stays equal; verify ownership directly.
        $query = $this->pdo->prepare('SELECT lease_token FROM stripe_catalog_products WHERE scope=:scope AND product_id=:id');
        $query->execute(['scope' => $scope, 'id' => $id]);
        if ($query->fetchColumn() !== $token) { throw new RuntimeException('Catalog synchronization lease lost.'); }
    }
}
