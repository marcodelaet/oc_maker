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
    $userId = (int) $user['id'];
    $setup = (new AuthService())->beginTotpSetup($userId);

    auditLog(
        'auth.totp_setup_start',
        'user',
        (string) $userId,
        'Início da configuração 2FA',
    );

    jsonResponse(['ok' => true, 'secret' => $setup['secret'], 'uri' => $setup['uri']]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
