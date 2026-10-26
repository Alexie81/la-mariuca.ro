<?php

declare(strict_types=1);

namespace App\Shared\Security;

use PDO;

final class Auth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, email, first_name, last_name, role, email_verified_at, totp_enabled FROM users WHERE id = :id AND status = :status');
        $statement->execute(['id' => (int) $id, 'status' => 'active']);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    public function attempt(string $email, string $password): string
    {
        $statement = $this->pdo->prepare('SELECT id, password_hash, status, role, totp_enabled FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!is_array($user) || $user['status'] !== 'active' || !password_verify($password, (string) $user['password_hash'])) {
            password_verify($password, '$2y$12$S0m3N0nUs3rHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            return 'invalid';
        }
        session_regenerate_id(true);
        if (in_array($user['role'] ?? 'customer', ['administrator', 'operator'], true) && (int) ($user['totp_enabled'] ?? 0) === 1) {
            $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
            unset($_SESSION['user_id']);
            return 'totp';
        }
        $_SESSION['user_id'] = (int) $user['id'];
        unset($_SESSION['pending_2fa_user_id']);
        return 'authenticated';
    }

    public function attemptAdmin(string $email, string $password): string
    {
        $statement = $this->pdo->prepare('SELECT id, password_hash, status, role, totp_enabled FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch();
        $passwordValid = is_array($user) && password_verify($password, (string) $user['password_hash']);
        $allowed = $passwordValid
            && $user['status'] === 'active'
            && in_array($user['role'] ?? '', ['administrator', 'operator'], true);
        if (!$allowed) {
            if (!is_array($user)) {
                password_verify($password, '$2y$12$S0m3N0nUs3rHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            }
            return 'invalid';
        }

        session_regenerate_id(true);
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
            unset($_SESSION['user_id']);
            return 'totp';
        }
        $_SESSION['user_id'] = (int) $user['id'];
        unset($_SESSION['pending_2fa_user_id']);
        return 'authenticated';
    }

    public function loginCustomer(int $userId): bool
    {
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id=:id AND role='customer' AND status='active' LIMIT 1");
        $statement->execute(['id' => $userId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $id;
        unset($_SESSION['pending_2fa_user_id']);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function pendingTwoFactorUser(): ?array
    {
        $id = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $statement = $this->pdo->prepare("SELECT id,email,role,totp_secret_encrypted,totp_last_timeslice FROM users WHERE id=:id AND status='active' AND totp_enabled=1");
        $statement->execute(['id' => (int) $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function completeTwoFactor(string $code, TotpService $totp): bool
    {
        $user = $this->pendingTwoFactorUser();
        if ($user === null || !is_string($user['totp_secret_encrypted']) || $user['totp_secret_encrypted'] === '') {
            return false;
        }
        $timeslice = 0;
        $verified = $totp->verify($totp->decrypt($user['totp_secret_encrypted']), trim($code), $timeslice);
        if ($verified && $timeslice > (int) $user['totp_last_timeslice']) {
            $update = $this->pdo->prepare('UPDATE users SET totp_last_timeslice=:slice,updated_at=:now WHERE id=:id AND totp_last_timeslice<:slice_check');
            $update->bindValue(':slice', $timeslice, PDO::PARAM_INT);
            $update->bindValue(':slice_check', $timeslice, PDO::PARAM_INT);
            $update->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
            $update->bindValue(':now', gmdate('c'));
            $update->execute();
        } else {
            $hash = $totp->recoveryHash($code);
            $statement = $this->pdo->prepare('UPDATE admin_recovery_codes SET used_at=:now WHERE user_id=:user AND code_hash=:hash AND used_at IS NULL');
            $statement->execute(['now' => gmdate('c'), 'user' => $user['id'], 'hash' => $hash]);
            $verified = $statement->rowCount() === 1;
        }
        if (!$verified) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        unset($_SESSION['pending_2fa_user_id']);
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['pending_2fa_user_id']);
        unset($_SESSION['admin_remember_after_2fa']);
        session_regenerate_id(true);
    }

    /** @param list<string> $roles */
    public function hasRole(array $roles): bool
    {
        $user = $this->user();
        return $user !== null && in_array((string) $user['role'], $roles, true);
    }
}
