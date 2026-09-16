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
        'environment' => appEnv(),
        'host' => dbHost(),
        'resolved_host' => Database::resolvedHost(),
        'port' => dbPort(),
        'database' => dbName(),
        'sapi' => PHP_SAPI,
    ]);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'environment' => appEnv(),
        'host' => dbHost(),
        'port' => dbPort(),
        'database' => dbName(),
        'sapi' => PHP_SAPI,
        'error' => $e->getMessage(),
    ];
    if (isDevEnvironment()) {
        $payload['hint'] = 'Apache em Docker/Linux? Tente DB_HOST=host.docker.internal no .env.';
    }
    jsonResponse($payload, 503);
}
