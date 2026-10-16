<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Config\Config;
use App\Shared\Security\Auth;
use App\Shared\Security\NullQrProvider;
use App\Shared\Security\TotpService;
use RobThree\Auth\TwoFactorAuth;
use Tests\DatabaseTestCase;

final class TwoFactorTest extends DatabaseTestCase
{
    public function testAdminTotpRequiresFreshCodeAndRecoveryIsSingleUse(): void
    {
        $totp = new TotpService(Config::load($this->root));
        $secret = $totp->createSecret();
        $now = gmdate('c');
        $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,totp_secret_encrypted,totp_enabled,created_at,updated_at) VALUES ('admin@example.test',:password,'Admin','Test','administrator','active',:now,:secret,1,:now,:now)")->execute([
            'password' => password_hash('Secure-test-password-2026!', PASSWORD_DEFAULT), 'now' => $now, 'secret' => $totp->encrypt($secret),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $recovery = 'ABCDE-12345';
        $this->pdo->prepare('INSERT INTO admin_recovery_codes (user_id,code_hash,created_at) VALUES (:user,:hash,:now)')->execute(['user' => $userId, 'hash' => $totp->recoveryHash($recovery), 'now' => $now]);
        $auth = new Auth($this->pdo);
        self::assertSame('totp', $auth->attempt('admin@example.test', 'Secure-test-password-2026!'));
        $code = (new TwoFactorAuth(new NullQrProvider(), 'La Mariuca Boutique'))->getCode($secret);
        self::assertTrue($auth->completeTwoFactor($code, $totp));
        $auth->logout();
        self::assertSame('totp', $auth->attempt('admin@example.test', 'Secure-test-password-2026!'));
        self::assertFalse($auth->completeTwoFactor($code, $totp), 'Același interval TOTP nu trebuie reutilizat.');
        self::assertTrue($auth->completeTwoFactor($recovery, $totp));
        $auth->logout();
        self::assertSame('totp', $auth->attempt('admin@example.test', 'Secure-test-password-2026!'));
        self::assertFalse($auth->completeTwoFactor($recovery, $totp), 'Codul de recuperare trebuie folosit o singură dată.');
    }

    public function testSecretEncryptionRoundTrip(): void
    {
        $totp = new TotpService(Config::load($this->root));
        $secret = $totp->createSecret();
        self::assertSame($secret, $totp->decrypt($totp->encrypt($secret)));
    }
}
