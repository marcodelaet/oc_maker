<?php

declare(strict_types=1);

/**
 * Router para o servidor embutido do PHP:
 * php -S localhost:8080 -t public public/router.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/maker/api/(parse|summary|generate|history|document|db-check|campaign|fees)\.php$#', $path, $m)) {
    require __DIR__ . '/maker/api/' . $m[1] . '.php';
    return true;
}

if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Rota não encontrada: ' . $path], JSON_UNESCAPED_UNICODE);
return true;
