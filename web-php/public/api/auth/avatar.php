<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AvatarService;
use OcMaker\SessionAuth;
use OcMaker\UserRepository;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = (int) ($_GET['id'] ?? 0);
        if ($userId <= 0) {
            jsonResponse(['error' => 'Usuário inválido.'], 400);
        }
        $query = ['id' => $userId];
        if (isset($_GET['v']) && $_GET['v'] !== '') {
            $query['v'] = (string) $_GET['v'];
        }
        header('Location: ' . url('avatar.php') . '?' . http_build_query($query), true, 302);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();
    $user = SessionAuth::requireLogin();
    $userId = (int) $user['id'];
    $repo = new UserRepository();
    $avatar = new AvatarService();

    if (empty($_FILES['avatar'])) {
        jsonResponse(['error' => 'Nenhuma imagem enviada.'], 400);
    }

    $oldPath = isset($user['avatar_path']) ? (string) $user['avatar_path'] : null;
    $filename = $avatar->save($userId, $_FILES['avatar']);
    $avatar->deleteFile($oldPath);
    $repo->setAvatarPath($userId, $filename);

    $updated = $repo->findById($userId);
    if ($updated !== null) {
        SessionAuth::refreshUser($updated);
    }
    $public = $updated !== null ? $repo->publicUser($updated) : null;
    auditLog(
        'auth.avatar_update',
        'user',
        (string) $userId,
        'Avatar atualizado',
        ['filename' => basename($filename)],
    );
    jsonResponse([
        'ok' => true,
        'avatar_url' => $public['avatar_url'] ?? null,
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
