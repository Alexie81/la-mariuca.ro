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
    public function __construct(private readonly PDO $pdo, private readonly Auth $auth, private readonly RateLimiter $limiter, private readonly View $view, private readonly Config $config)
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12 || trim((string) ($data['first_name'] ?? '')) === '' || trim((string) ($data['last_name'] ?? '')) === '') {
            Flash::set('error', 'Completează date valide; parola trebuie să aibă minimum 12 caractere.');
            return $this->view->redirect($this->config->path('cont/inregistrare/'));
        }
        $token = bin2hex(random_bytes(32));
        $now = gmdate('c');
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,verification_token_hash,created_at,updated_at) VALUES (:email,:password,:first_name,:last_name,'customer','pending',:token,:now,:now)")->execute([
                'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'token' => hash('sha256', $token), 'now' => $now,
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

    public function account(): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->view->redirect($this->config->path('cont/autentificare/'));
        }
        $orders = $this->pdo->prepare('SELECT order_number,total_bani,status,payment_status,created_at FROM orders WHERE user_id=:id ORDER BY id DESC');
        $orders->execute(['id' => $user['id']]);
        return $this->view->render('storefront/pages/account.twig', ['account' => $user, 'orders' => $orders->fetchAll(), 'meta' => ['title' => 'Contul meu', 'noindex' => true]]);
    }

    public function logout(): ResponseInterface
    {
        $this->auth->logout();
        Flash::set('success', 'Te-ai deconectat.');
        return $this->view->redirect($this->config->path(''));
    }
}
