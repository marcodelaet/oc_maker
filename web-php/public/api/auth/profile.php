<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\SessionAuth;
use OcMaker\UserProfileService;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }

    validateCsrf();
    $sessionUser = SessionAuth::requireLogin();
    $userId = (int) $sessionUser['id'];

    $profile = new UserProfileService();
    $payload = [
        'name' => $_POST['name'] ?? null,
        'last_name' => $_POST['last_name'] ?? null,
        'birth_date' => $_POST['birth_date'] ?? null,
        'username' => $_POST['username'] ?? null,
        'email' => $_POST['email'] ?? null,
        'phone_country_code' => $_POST['phone_country_code'] ?? null,
        'phone' => $_POST['phone'] ?? null,
    ];
    $updated = $profile->updateProfile($userId, $payload);

    $fields = [];
    foreach (array_keys($payload) as $field) {
        if (array_key_exists($field, $_POST)) {
            $fields[] = $field;
        }
    }
    auditLog(
        'auth.profile_update',
        'user',
        (string) $userId,
        'Dados da conta atualizados',
        ['fields' => $fields],
    );

    jsonResponse(['ok' => true, 'user' => $updated]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
