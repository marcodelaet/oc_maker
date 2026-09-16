<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AuthService;

$user = (new AuthService())->currentUser();

$role = $user['role'] ?? '';
$isAdmin = $user !== null && $role === 'administrador';

jsonResponse([
    'authenticated' => $user !== null,
    'user' => $user,
    'csrf_token' => csrfToken(),
    'can_delete' => $isAdmin,
    'is_admin' => $isAdmin,
    'can_calculator' => $user !== null && in_array($role, ['administrador', 'financeiro'], true),
]);
