<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\PasswordPolicy;
use OcMaker\Permission;
use OcMaker\SessionAuth;
use OcMaker\UserProfileService;
use OcMaker\UserRepository;

$admin = SessionAuth::requireLogin();
if (($admin['role'] ?? '') !== 'administrador') {
    jsonResponse(['error' => 'Acesso restrito a administradores.'], 403);
}

$adminId = (int) $admin['id'];
$repo = new UserRepository();

/** @param array<string, mixed>|null $target */
$adminAuditDetails = static function (?array $target = null, ?array $extra = null) use ($admin, $adminId): array {
    $details = [
        'admin_id' => $adminId,
        'admin_email' => $admin['email'] ?? null,
    ];
    if ($target !== null) {
        $details['target_email'] = $target['email'] ?? null;
        $details['target_username'] = $target['username'] ?? null;
        $details['target_name'] = trim(
            ((string) ($target['name'] ?? '')) . ' ' . ((string) ($target['last_name'] ?? ''))
        ) ?: null;
    }
    if ($extra !== null) {
        $details = array_merge($details, $extra);
    }

    return $details;
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $users = array_map(
            static fn (array $row): array => $repo->publicUser($row),
            $repo->listAll()
        );
        jsonResponse(['users' => $users]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();

    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'create') {
        $username = UserProfileService::normalizeUsername(sanitizeString((string) ($_POST['username'] ?? ''), 64));
        if ($username === '') {
            jsonResponse(['error' => 'Informe o nome de usuário.'], 400);
        }
        UserProfileService::assertUsernameFormat($username);

        $password = (string) ($_POST['password'] ?? '');
        PasswordPolicy::assertValid($password);

        $lastName = trim(sanitizeString((string) ($_POST['last_name'] ?? ''), 120));
        $email = sanitizeString((string) ($_POST['email'] ?? ''), 190);
        $role = (string) ($_POST['role'] ?? 'comercial');
        $id = $repo->create([
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'name' => sanitizeString((string) ($_POST['name'] ?? ''), 120),
            'last_name' => $lastName !== '' ? $lastName : null,
            'role' => $role,
            'must_change_password' => true,
        ]);
        auditLog(
            'admin.user.create',
            'user',
            (string) $id,
            'Administrador criou usuário',
            $adminAuditDetails(
                ['email' => $email, 'username' => $username, 'name' => $_POST['name'] ?? '', 'last_name' => $lastName],
                ['role' => $role],
            ),
        );
        jsonResponse(['ok' => true, 'id' => $id]);
    }

    $targetId = (int) ($_POST['id'] ?? 0);
    if ($targetId <= 0) {
        jsonResponse(['error' => 'ID inválido.'], 400);
    }

    if ($action === 'update') {
        $target = $repo->findById($targetId);
        if ($target === null) {
            jsonResponse(['error' => 'Usuário não encontrado.'], 404);
        }

        $payload = [];
        if (isset($_POST['name'])) {
            $payload['name'] = sanitizeString((string) $_POST['name'], 120);
        }
        if (isset($_POST['last_name'])) {
            $lastName = trim(sanitizeString((string) $_POST['last_name'], 120));
            $payload['last_name'] = $lastName !== '' ? $lastName : null;
        }
        if (isset($_POST['email'])) {
            $payload['email'] = sanitizeString((string) $_POST['email'], 190);
        }
        if (isset($_POST['username'])) {
            $payload['username'] = sanitizeString((string) $_POST['username'], 64);
        }
        if (isset($_POST['role'])) {
            $payload['role'] = (string) $_POST['role'];
        }
        if (isset($_POST['active'])) {
            $payload['active'] = filter_var($_POST['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($payload !== []) {
            $repo->update($targetId, $payload);
        }

        if (!empty($_POST['password'])) {
            $repo->updatePassword($targetId, (string) $_POST['password'], true);
        }

        if ($payload === [] && empty($_POST['password'])) {
            jsonResponse(['error' => 'Nenhum dado para atualizar.'], 400);
        }

        auditLog(
            'admin.user.update',
            'user',
            (string) $targetId,
            'Administrador alterou dados do usuário',
            $adminAuditDetails($target, [
                'fields' => array_keys($payload),
                'password_reset' => !empty($_POST['password']),
            ]),
        );
        jsonResponse(['ok' => true]);
    }

    if ($action === 'disable_totp') {
        $target = $repo->findById($targetId);
        if ($target === null) {
            jsonResponse(['error' => 'Usuário não encontrado.'], 404);
        }
        $repo->adminDisableTotp($targetId);
        auditLog(
            'admin.user.disable_totp',
            'user',
            (string) $targetId,
            'Administrador desativou 2FA do usuário',
            $adminAuditDetails($target),
        );
        jsonResponse(['ok' => true]);
    }

    if ($action === 'deactivate') {
        if ($targetId === $adminId) {
            jsonResponse(['error' => 'Você não pode desativar sua própria conta.'], 400);
        }
        $target = $repo->findById($targetId);
        if ($target === null) {
            jsonResponse(['error' => 'Usuário não encontrado.'], 404);
        }
        $repo->update($targetId, ['active' => false]);
        auditLog(
            'admin.user.deactivate',
            'user',
            (string) $targetId,
            'Administrador removeu acesso do usuário',
            $adminAuditDetails($target),
        );
        jsonResponse(['ok' => true]);
    }

    if ($action === 'activate') {
        $target = $repo->findById($targetId);
        if ($target === null) {
            jsonResponse(['error' => 'Usuário não encontrado.'], 404);
        }
        $repo->update($targetId, ['active' => true]);
        auditLog(
            'admin.user.activate',
            'user',
            (string) $targetId,
            'Administrador reativou acesso do usuário',
            $adminAuditDetails($target),
        );
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
