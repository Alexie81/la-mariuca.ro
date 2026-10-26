<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Config\Config;
use PDO;
use Psr\Http\Message\ResponseInterface;

final class AdminRememberMe
{
    public const COOKIE_NAME = 'lamariuca_admin_remember';
    private const LIFETIME_SECONDS = 2_592_000;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function issue(int $userId, string $userAgent): string
    {
        $allowed = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND status='active' AND role IN ('administrator','operator')");
        $allowed->execute(['id' => $userId]);
        if ($allowed->fetchColumn() === false) {
            throw new \RuntimeException('Contul nu poate folosi autentificarea persistentă pentru administrare.');
        }

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now = gmdate('c');
        $expiresAt = gmdate('c', time() + self::LIFETIME_SECONDS);
        $this->pdo->prepare('DELETE FROM admin_remember_tokens WHERE expires_at<=:now')->execute(['now' => $now]);
        $this->pdo->prepare('INSERT INTO admin_remember_tokens (user_id,selector_hash,validator_hash,user_agent_hash,expires_at,last_used_at,created_at) VALUES (:user,:selector,:validator,:agent,:expires,NULL,:created)')->execute([
            'user' => $userId,
            'selector' => hash('sha256', $selector),
            'validator' => hash('sha256', $validator),
            'agent' => hash('sha256', $userAgent),
            'expires' => $expiresAt,
            'created' => $now,
        ]);
        $this->keepNewestTokens($userId, 5);

        return $selector . '.' . $validator;
    }

    /**
     * Restores the admin session and rotates the token.
     * Returns null when no cookie exists, false for an invalid cookie, or the rotated value.
     */
    public function restore(string $cookieValue, string $userAgent): string|false|null
    {
        if ($cookieValue === '') {
            return null;
        }
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2 || preg_match('/^[a-f0-9]{24}$/', $parts[0]) !== 1 || preg_match('/^[a-f0-9]{64}$/', $parts[1]) !== 1) {
            return false;
        }
        [$selector, $validator] = $parts;
        $statement = $this->pdo->prepare("SELECT t.id,t.user_id,t.validator_hash,t.user_agent_hash,t.expires_at FROM admin_remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector_hash=:selector AND u.status='active' AND u.role IN ('administrator','operator') LIMIT 1");
        $statement->execute(['selector' => hash('sha256', $selector)]);
        $token = $statement->fetch();
        if (!is_array($token)) {
            return false;
        }
        $valid = strtotime((string) $token['expires_at']) > time()
            && hash_equals((string) $token['validator_hash'], hash('sha256', $validator))
            && hash_equals((string) $token['user_agent_hash'], hash('sha256', $userAgent));
        if (!$valid) {
            $this->pdo->prepare('DELETE FROM admin_remember_tokens WHERE id=:id')->execute(['id' => $token['id']]);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $token['user_id'];
        unset($_SESSION['pending_2fa_user_id']);
        $newValidator = bin2hex(random_bytes(32));
        $now = gmdate('c');
        $expiresAt = gmdate('c', time() + self::LIFETIME_SECONDS);
        $this->pdo->prepare('UPDATE admin_remember_tokens SET validator_hash=:validator,expires_at=:expires,last_used_at=:used WHERE id=:id')->execute([
            'validator' => hash('sha256', $newValidator),
            'expires' => $expiresAt,
            'used' => $now,
            'id' => $token['id'],
        ]);

        return $selector . '.' . $newValidator;
    }

    public function revoke(string $cookieValue): void
    {
        $selector = explode('.', $cookieValue, 2)[0] ?? '';
        if (preg_match('/^[a-f0-9]{24}$/', $selector) === 1) {
            $this->pdo->prepare('DELETE FROM admin_remember_tokens WHERE selector_hash=:selector')->execute(['selector' => hash('sha256', $selector)]);
        }
    }

    public function withCookie(ResponseInterface $response, string $value): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->cookieHeader($value, self::LIFETIME_SECONDS));
    }

    public function withoutCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->cookieHeader('', 0));
    }

    private function cookieHeader(string $value, int $maxAge): string
    {
        $expires = $maxAge > 0 ? time() + $maxAge : 1;
        $parts = [
            self::COOKIE_NAME . '=' . rawurlencode($value),
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
            'Max-Age=' . $maxAge,
            'Path=' . $this->config->path('admin'),
            'HttpOnly',
            'SameSite=Strict',
        ];
        if ($this->config->bool('SESSION_SECURE') || str_starts_with($this->config->rootUrl(), 'https://')) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function keepNewestTokens(int $userId, int $limit): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM admin_remember_tokens WHERE user_id=:user ORDER BY id DESC');
        $statement->execute(['user' => $userId]);
        $ids = array_map('intval', array_column($statement->fetchAll(), 'id'));
        foreach (array_slice($ids, $limit) as $id) {
            $this->pdo->prepare('DELETE FROM admin_remember_tokens WHERE id=:id')->execute(['id' => $id]);
        }
    }
}
