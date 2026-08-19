<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\Database;

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1');
    jsonResponse([
        'ok' => true,
        'host' => dbHost(),
        'resolved_host' => Database::resolvedHost(),
        'port' => dbPort(),
        'database' => dbName(),
        'sapi' => PHP_SAPI,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'host' => dbHost(),
        'port' => dbPort(),
        'database' => dbName(),
        'sapi' => PHP_SAPI,
        'error' => $e->getMessage(),
    ], 503);
}
