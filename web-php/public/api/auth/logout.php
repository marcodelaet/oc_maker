<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AuthService;
use OcMaker\SessionAuth;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido.'], 405);
}
validateCsrf();

$user = SessionAuth::user();
auditLog(
    'auth.logout',
    'user',
    $user !== null ? (string) $user['id'] : null,
    'Logout realizado',
);
(new AuthService())->logout();
jsonResponse(['ok' => true, 'csrf_token' => csrfToken()]);
