<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Config\Config;
use RobThree\Auth\TwoFactorAuth;
use RuntimeException;

final class TotpService
{
    private TwoFactorAuth $totp;
    private string $key;

    public function __construct(Config $config)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('Extensia sodium este necesară pentru protejarea secretelor TOTP.');
        }
        $this->key = hash('sha256', $config->get('APP_KEY'), true);
        $this->totp = new TwoFactorAuth(new NullQrProvider(), 'La Mariuca Boutique');
    }

    public function createSecret(): string
    {
        return $this->totp->createSecret();
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secret TOTP invalid.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Secretul TOTP nu poate fi decriptat cu cheia aplicației.');
        }
        return $plain;
    }

    public function verify(string $secret, string $code, int &$timeslice = 0): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $verifiedSlice = 0;
        $verified = $this->totp->verifyCode($secret, $code, 1, null, $verifiedSlice);
        $timeslice = (int) $verifiedSlice;
        return $verified;
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }
        return $codes;
    }

    public function recoveryHash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))), $this->key);
    }

    public function provisioningUri(string $email, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode('La Mariuca Boutique:' . $email) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode('La Mariuca Boutique') . '&digits=6&period=30';
    }
}
