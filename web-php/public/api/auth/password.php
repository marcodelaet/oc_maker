<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AuthService;
use OcMaker\SessionAuth;
use OcMaker\UserRepository;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();
    $user = SessionAuth::requireLogin();
    $userId = (int) $user['id'];
    $auth = new AuthService();
    $repo = new UserRepository();

    $force = !empty($_POST['force']) || !empty($user['must_change_password']);
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? $_POST['new_password'] ?? '');

    if ($force) {
        $auth->changePasswordForced($userId, $new, $confirm);
        auditLog(
            'auth.password_force_change',
            'user',
            (string) $userId,
            'Senha alterada (obrigatório no primeiro acesso)',
        );
        $updated = $repo->findById($userId);
        jsonResponse([
            'ok' => true,
            'user' => $updated ? $repo->publicUser($updated) : null,
        ]);
    }

    $current = (string) ($_POST['current_password'] ?? '');
    $auth->changePassword($userId, $current, $new);
    auditLog(
        'auth.password_change',
        'user',
        (string) $userId,
        'Senha alterada',
    );
    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
