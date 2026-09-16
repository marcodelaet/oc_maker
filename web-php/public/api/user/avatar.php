<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\AvatarService;
use OcMaker\UserRepository;

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(404);
    exit;
}

$repo = new UserRepository();
$user = $repo->findById($userId);
$path = $user !== null && !empty($user['avatar_path']) ? (string) $user['avatar_path'] : '';
$file = $path !== '' ? AvatarService::storageDir() . '/' . basename($path) : '';

if ($file === '' || !is_file($file)) {
    http_response_code(404);
    exit;
}

$mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
readfile($file);
