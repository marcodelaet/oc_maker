<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AuthService;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }

    $login = sanitizeString((string) ($_POST['login'] ?? $_POST['email'] ?? ''), 190);
    $password = (string) ($_POST['password'] ?? '');
    $totp = trim((string) ($_POST['totp'] ?? ''));

    $auth = new AuthService();
    $result = $auth->attemptLogin($login, $password, $totp !== '' ? $totp : null);

    if (empty($result['requires_totp'])) {
        auditLog(
            'auth.login',
            'user',
            isset($result['user']['id']) ? (string) $result['user']['id'] : null,
            'Login realizado',
            ['email' => $result['user']['email'] ?? null],
        );
    }

    jsonResponse([
        'ok' => true,
        'user' => $result['user'],
        'requires_totp' => !empty($result['requires_totp']),
        'csrf_token' => csrfToken(),
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 401);
}
