<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\ActivityLogExportService;
use OcMaker\ActivityLogRepository;
use OcMaker\SessionAuth;

$admin = SessionAuth::requireLogin();
if (($admin['role'] ?? '') !== 'administrador') {
    jsonResponse(['error' => 'Acesso restrito a administradores.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Método não permitido.'], 405);
}

try {
    $repo = new ActivityLogRepository();
    $limit = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);
    $action = isset($_GET['action']) ? sanitizeString((string) $_GET['action'], 64) : null;
    $search = isset($_GET['q']) ? sanitizeString((string) $_GET['q'], 190) : null;

    $result = $repo->list($limit, $offset, $action !== '' ? $action : null, $search !== '' ? $search : null);

    jsonResponse([
        'entries' => $result['entries'],
        'total' => $result['total'],
        'actions' => $repo->distinctActions(),
        'columns' => ActivityLogExportService::COLUMNS,
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
