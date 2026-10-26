<?php

declare(strict_types=1);

namespace App\Modules\Customers;

use App\Shared\Config\Config;
use App\Shared\Http\RateLimiter;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(private readonly PDO $pdo, private readonly Auth $auth, private readonly GoogleOAuthService $googleOAuth, private readonly CustomerAccountService $accountService, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
    {
    }

    public function loginForm(): ResponseInterface
    {
        return $this->view->render('storefront/pages/login.twig', ['meta' => ['title' => 'Autentificare', 'noindex' => true]]);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $identity = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown') . '|' . $email;
        if (!$this->limiter->allow('login', $identity, 8, 900)) {
            Flash::set('error', 'Datele de autentificare nu sunt valide sau încercările sunt temporar limitate.');
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        $result = $this->auth->attempt($email, (string) ($data['password'] ?? ''));
        if ($result === 'invalid') {
            Flash::set('error', 'Datele de autentificare nu sunt valide sau încercările sunt temporar limitate.');
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        if ($result === 'totp') {
            return $this->view->redirect($this->config->path('cont/totp/'));
        }
        $user = $this->auth->user();
        Flash::set('success', 'Te-ai autentificat cu succes.');
        return $this->view->redirect($this->config->path(in_array($user['role'] ?? '', ['administrator', 'operator'], true) ? 'admin/' : 'cont/'));
    }

    public function registerForm(): ResponseInterface
    {
        return $this->view->render('storefront/pages/register.twig', ['meta' => ['title' => 'Creează cont', 'noindex' => true]]);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $phone = $this->normalizeRomanianPhone((string) ($data['phone'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 8 || trim((string) ($data['first_name'] ?? '')) === '' || trim((string) ($data['last_name'] ?? '')) === '' || $phone === null) {
            Flash::set('error', 'Completează date valide, inclusiv un număr de telefon românesc; parola trebuie să aibă minimum 8 caractere.');
            return $this->view->redirect($this->config->path('cont/inregistrare/'));
        }
        $token = bin2hex(random_bytes(32));
        $now = gmdate('c');
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,phone,role,status,verification_token_hash,created_at,updated_at) VALUES (:email,:password,:first_name,:last_name,:phone,'customer','pending',:token,:now,:now)")->execute([
                'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'phone' => $phone, 'token' => hash('sha256', $token), 'now' => $now,
            ]);
            $this->pdo->prepare("INSERT INTO outbox_messages (message_type,recipient,payload_json,status,attempts,available_at,created_at) VALUES ('verify_email',:email,:payload,'pending',0,:now,:now)")->execute(['email' => $email, 'payload' => json_encode(['token' => $token], JSON_THROW_ON_ERROR), 'now' => $now]);
            $this->pdo->commit();
            Flash::set('success', 'Contul a fost creat. Verifică emailul pentru activare.');
        } catch (\Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::set('error', 'Contul nu a putut fi creat. Adresa poate fi deja folosită.');
        }
        return $this->view->redirect($this->config->path('cont/autentificare/'));
    }

    public function googleStart(): ResponseInterface
    {
        if (!$this->googleOAuth->enabled()) {
            Flash::set('error', 'Autentificarea cu Google este pregătită, dar trebuie configurată înainte de activare.');
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }

        return $this->view->redirect($this->googleOAuth->authorizationUrl());
    }

    public function googleCallback(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $userId = $this->googleOAuth->authenticateCallback($request->getQueryParams());
            if (!$this->auth->loginCustomer($userId)) {
                throw new \RuntimeException('Contul nu poate fi autentificat.');
            }
            Flash::set('success', 'Te-ai autentificat cu Google. Bine ai revenit!');
            return $this->view->redirect($this->config->path('cont/'));
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        } catch (\Throwable) {
            Flash::set('error', 'Autentificarea cu Google nu a putut fi finalizată. Încearcă din nou.');
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
    }

    private function normalizeRomanianPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '0040')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^0[2-8][0-9]{8}$/', $digits) === 1) {
            $digits = '40' . substr($digits, 1);
        }
        if (preg_match('/^40[2-8][0-9]{8}$/', $digits) !== 1) {
            return null;
        }

        return '+' . $digits;
    }

    public function account(): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        if (($user['role'] ?? '') !== 'customer') {
            return $this->view->redirect($this->config->path('admin/'));
        }
        try {
            return $this->view->render('storefront/pages/account.twig', $this->accountService->dashboard((int) $user['id']) + [
                'meta' => ['title' => 'Contul meu', 'noindex' => true],
            ]);
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->customerId();
        if ($userId === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        try {
            $this->accountService->updateProfile($userId, (array) ($request->getParsedBody() ?? []));
            Flash::set('success', 'Datele tale au fost actualizate.');
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('cont/#date-personale'));
    }

    public function saveAddress(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->customerId();
        if ($userId === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        try {
            $this->accountService->saveAddress($userId, (array) ($request->getParsedBody() ?? []));
            Flash::set('success', 'Adresa a fost salvată în cont.');
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('cont/#adrese'));
    }

    public function deleteAddress(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->customerId();
        if ($userId === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        try {
            $this->accountService->deleteAddress($userId, (int) ($args['id'] ?? 0));
            Flash::set('success', 'Adresa a fost eliminată.');
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('cont/#adrese'));
    }

    public function setDefaultAddress(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->customerId();
        if ($userId === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        try {
            $this->accountService->setDefaultAddress($userId, (int) ($args['id'] ?? 0));
            Flash::set('success', 'Adresa implicită a fost schimbată.');
        } catch (\RuntimeException $exception) {
            Flash::set('error', $exception->getMessage());
        }

        return $this->view->redirect($this->config->path('cont/#adrese'));
    }

    public function logout(): ResponseInterface
    {
        $this->auth->logout();
        Flash::set('success', 'Te-ai deconectat.');
        return $this->view->redirect($this->config->path(''));
    }

    private function customerId(): ?int
    {
        $user = $this->auth->user();
        return $user !== null && ($user['role'] ?? '') === 'customer' ? (int) $user['id'] : null;
    }
}
