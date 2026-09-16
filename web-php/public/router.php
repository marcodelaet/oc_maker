<?php

declare(strict_types=1);

/**
 * Router para o servidor embutido do PHP:
 * php -S localhost:8080 -t public public/router.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$publicRoot = __DIR__;
$candidates = [$path];

if (preg_match('#/public(/.*)$#', $path, $m)) {
    $candidates[] = $m[1];
}

foreach ($candidates as $candidate) {
    $file = $publicRoot . $candidate;
    if ($candidate !== '/' && is_file($file)) {
        return false;
    }
}

if (preg_match('#/api/([a-z0-9_/-]+\.php)$#i', $path, $m)) {
    $apiFile = $publicRoot . '/api/' . $m[1];
    if (is_file($apiFile)) {
        require $apiFile;
        return true;
    }
}

if (preg_match('#/api/(parse|summary|generate|history|document|db-check|campaign|fees)(?:\.php)?$#', $path, $m)) {
    require __DIR__ . '/api/' . $m[1] . '.php';
    return true;
}

if ($path === '/' || str_ends_with($path, '/index.php')) {
    require __DIR__ . '/index.php';
    return true;
}

if (preg_match('#/avatar\.php$#', $path)) {
    require __DIR__ . '/avatar.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Rota não encontrada: ' . $path], JSON_UNESCAPED_UNICODE);
return true;
