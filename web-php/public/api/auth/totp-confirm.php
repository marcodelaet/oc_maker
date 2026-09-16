<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AuthService;
use OcMaker\SessionAuth;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();
    $user = SessionAuth::requireLogin();

    $secret = trim((string) ($_POST['secret'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $userId = (int) $user['id'];
    (new AuthService())->confirmTotpSetup($userId, $secret, $code);

    auditLog(
        'auth.totp_setup_confirm',
        'user',
        (string) $userId,
        '2FA ativado',
    );

    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
