<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Shared\Config\Config;
use PDO;
use RuntimeException;

final class GoogleOAuthService
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const TOKEN_INFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->bool('GOOGLE_OAUTH_ENABLED')
            && $this->config->get('GOOGLE_CLIENT_ID') !== ''
            && $this->config->get('GOOGLE_CLIENT_SECRET') !== '';
    }

    public function authorizationUrl(): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Autentificarea cu Google nu este configurată.');
        }

        $state = $this->randomUrlSafe(32);
        $nonce = $this->randomUrlSafe(32);
        $verifier = $this->randomUrlSafe(64);
        $_SESSION['google_oauth'] = [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'started_at' => time(),
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->get('GOOGLE_CLIENT_ID'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $query */
    public function authenticateCallback(array $query): int
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Autentificarea cu Google nu este configurată.');
        }

        $pending = $_SESSION['google_oauth'] ?? null;
        unset($_SESSION['google_oauth']);
        if (!is_array($pending)
            || !is_string($pending['state'] ?? null)
            || !is_string($pending['nonce'] ?? null)
            || !is_string($pending['verifier'] ?? null)
            || !is_int($pending['started_at'] ?? null)
            || time() - $pending['started_at'] > 600
        ) {
            throw new RuntimeException('Sesiunea Google a expirat.');
        }

        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($state === '' || !hash_equals($pending['state'], $state) || $code === '') {
            throw new RuntimeException('Răspunsul Google nu a putut fi validat.');
        }

        $tokens = $this->postForm(self::TOKEN_URL, [
            'client_id' => $this->config->get('GOOGLE_CLIENT_ID'),
            'client_secret' => $this->config->get('GOOGLE_CLIENT_SECRET'),
            'code' => $code,
            'code_verifier' => $pending['verifier'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
        $idToken = is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : '';
        if ($idToken === '') {
            throw new RuntimeException('Google nu a furnizat identitatea contului.');
        }

        $claims = $this->getJson(self::TOKEN_INFO_URL . '?id_token=' . rawurlencode($idToken));
        $this->validateClaims($claims, $pending['nonce']);

        return $this->resolveCustomer($claims);
    }

    /** @param array<string, mixed> $claims */
    private function validateClaims(array $claims, string $nonce): void
    {
        $issuer = is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        $audience = is_string($claims['aud'] ?? null) ? $claims['aud'] : '';
        $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
        $receivedNonce = is_string($claims['nonce'] ?? null) ? $claims['nonce'] : '';
        $verified = $claims['email_verified'] ?? false;
        $emailVerified = $verified === true || $verified === 'true' || $verified === '1' || $verified === 1;

        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || !hash_equals($this->config->get('GOOGLE_CLIENT_ID'), $audience)
            || !is_int($expiresAt)
            || $expiresAt <= time()
            || $receivedNonce === ''
            || !hash_equals($nonce, $receivedNonce)
            || !$emailVerified
        ) {
            throw new RuntimeException('Identitatea Google nu a putut fi confirmată.');
        }
    }

    /** @param array<string, mixed> $claims */
    private function resolveCustomer(array $claims): int
    {
        $subject = is_string($claims['sub'] ?? null) ? trim($claims['sub']) : '';
        $email = is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email'])) : '';
        if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Contul Google nu are o adresă de email validă.');
        }

        $identity = $this->pdo->prepare("SELECT u.id,u.role,u.status FROM oauth_identities oi JOIN users u ON u.id=oi.user_id WHERE oi.provider='google' AND oi.provider_subject=:subject LIMIT 1");
        $identity->execute(['subject' => $subject]);
        $existingIdentity = $identity->fetch();
        if (is_array($existingIdentity)) {
            if ($existingIdentity['role'] !== 'customer' || $existingIdentity['status'] !== 'active') {
                throw new RuntimeException('Acest cont nu poate folosi autentificarea socială.');
            }
            return (int) $existingIdentity['id'];
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT id,role,status FROM users WHERE email=:email LIMIT 1');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();
            $now = gmdate('c');

            if (is_array($user)) {
                if ($user['role'] !== 'customer' || $user['status'] === 'blocked') {
                    throw new RuntimeException('Acest cont nu poate folosi autentificarea socială.');
                }
                $userId = (int) $user['id'];
                $this->pdo->prepare("UPDATE users SET status='active',email_verified_at=COALESCE(email_verified_at,:now),verification_token_hash=NULL,updated_at=:now WHERE id=:id")
                    ->execute(['now' => $now, 'id' => $userId]);
            } else {
                [$firstName, $lastName] = $this->namesFromClaims($claims, $email);
                $insert = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES (:email,:password,:first_name,:last_name,'customer','active',:now,:now,:now)");
                $insert->execute([
                    'email' => $email,
                    'password' => password_hash($this->randomUrlSafe(48), PASSWORD_DEFAULT),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'now' => $now,
                ]);
                $userId = (int) $this->pdo->lastInsertId();
            }

            $this->pdo->prepare("INSERT INTO oauth_identities (user_id,provider,provider_subject,created_at,updated_at) VALUES (:user_id,'google',:subject,:now,:now)")
                ->execute(['user_id' => $userId, 'subject' => $subject, 'now' => $now]);
            $this->pdo->commit();

            return $userId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $claims
     *  @return array{0: string, 1: string}
     */
    private function namesFromClaims(array $claims, string $email): array
    {
        $firstName = is_string($claims['given_name'] ?? null) ? trim($claims['given_name']) : '';
        $lastName = is_string($claims['family_name'] ?? null) ? trim($claims['family_name']) : '';
        if ($firstName === '' && is_string($claims['name'] ?? null)) {
            $parts = preg_split('/\s+/u', trim($claims['name']), 2) ?: [];
            $firstName = trim((string) ($parts[0] ?? ''));
            $lastName = trim((string) ($parts[1] ?? ''));
        }
        if ($firstName === '') {
            $firstName = ucfirst((string) strstr($email, '@', true));
        }
        if ($lastName === '') {
            $lastName = 'Google';
        }

        return [mb_substr($firstName, 0, 120), mb_substr($lastName, 0, 120)];
    }

    /** @param array<string, string> $data
     *  @return array<string, mixed>
     */
    private function postForm(string $url, array $data): array
    {
        return $this->requestJson($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        return $this->requestJson($url, [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    }

    /** @param array<int, mixed> $options
     *  @return array<string, mixed>
     */
    private function requestJson(string $url, array $options): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Conexiunea securizată cu Google nu a putut fi inițiată.');
        }
        curl_setopt_array($handle, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? 'Google nu a putut fi contactat.' : 'Google a refuzat solicitarea de autentificare.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Răspunsul Google nu a putut fi citit.');
        }

        return $decoded;
    }

    private function redirectUri(): string
    {
        return $this->config->rootUrl() . '/cont/google/retur/';
    }

    private function randomUrlSafe(int $bytes): string
    {
        return $this->base64Url(random_bytes($bytes));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
