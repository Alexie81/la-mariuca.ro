<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Security\TotpService;
use App\Shared\Support\Flash;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class TwoFactorController
{
    public function __construct(private readonly PDO $pdo, private readonly Auth $auth, private readonly TotpService $totp, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
    {
    }

    public function challengeForm(): ResponseInterface
    {
        if ($this->auth->pendingTwoFactorUser() === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        return $this->view->render('storefront/pages/totp-challenge.twig', ['meta' => ['title' => 'Verificare în doi pași', 'noindex' => true]]);
    }

    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $user = $this->auth->pendingTwoFactorUser();
        $identity = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown') . '|' . (string) ($user['id'] ?? 0);
        if ($user === null || !$this->limiter->allow('totp', $identity, 8, 900) || !$this->auth->completeTwoFactor((string) ($data['code'] ?? ''), $this->totp)) {
            Flash::set('error', 'Codul este invalid, expirat sau a fost deja folosit.');
            return $this->view->redirect($this->config->path('cont/totp/'));
        }
        Flash::set('success', 'Autentificare completă.');
        return $this->view->redirect($this->config->path('admin/'));
    }

    public function setupForm(): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null || !in_array($user['role'], ['administrator', 'operator'], true)) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        if ((int) $user['totp_enabled'] === 1) {
            return $this->view->render('admin/pages/totp-setup.twig', ['enabled' => true]);
        }
        if (!isset($_SESSION['totp_setup_secret'], $_SESSION['totp_recovery_codes'])) {
            $_SESSION['totp_setup_secret'] = $this->totp->createSecret();
            $_SESSION['totp_recovery_codes'] = $this->totp->recoveryCodes();
        }
        $secret = (string) $_SESSION['totp_setup_secret'];
        return $this->view->render('admin/pages/totp-setup.twig', [
            'enabled' => false, 'secret' => $secret, 'recovery_codes' => $_SESSION['totp_recovery_codes'],
            'provisioning_uri' => $this->totp->provisioningUri((string) $user['email'], $secret),
        ]);
    }

    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();
        $secret = $_SESSION['totp_setup_secret'] ?? null;
        $codes = $_SESSION['totp_recovery_codes'] ?? null;
        if ($user === null || !is_string($secret) || !is_array($codes)) {
            throw new RuntimeException('Sesiunea de configurare TOTP a expirat.');
        }
        $data = (array) ($request->getParsedBody() ?? []);
        $slice = 0;
        if (!$this->totp->verify($secret, trim((string) ($data['code'] ?? '')), $slice)) {
            Flash::set('error', 'Codul TOTP nu este valid.');
            return $this->view->redirect($this->config->path('admin/securitate/totp/'));
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE users SET totp_secret_encrypted=:secret,totp_enabled=1,totp_last_timeslice=:slice,updated_at=:now WHERE id=:id')->execute([
                'secret' => $this->totp->encrypt($secret), 'slice' => $slice, 'now' => gmdate('c'), 'id' => $user['id'],
            ]);
            $this->pdo->prepare('DELETE FROM admin_recovery_codes WHERE user_id=:id')->execute(['id' => $user['id']]);
            $insert = $this->pdo->prepare('INSERT INTO admin_recovery_codes (user_id,code_hash,created_at) VALUES (:user,:hash,:now)');
            foreach ($codes as $code) {
                $insert->execute(['user' => $user['id'], 'hash' => $this->totp->recoveryHash((string) $code), 'now' => gmdate('c')]);
            }
            $this->pdo->commit();
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_recovery_codes']);
            Flash::set('success', 'TOTP a fost activat. Codurile de recuperare nu vor mai fi afișate.');
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->view->redirect($this->config->path('admin/securitate/totp/'));
    }
}
