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
    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
        jsonResponse(['error' => 'Formato inválido. Use csv, xlsx ou pdf.'], 400);
    }

    $action = isset($_GET['action']) ? sanitizeString((string) $_GET['action'], 64) : null;
    $search = isset($_GET['q']) ? sanitizeString((string) $_GET['q'], 190) : null;

    $repo = new ActivityLogRepository();
    $entries = $repo->allForExport($action !== '' ? $action : null, $search !== '' ? $search : null);
    $export = new ActivityLogExportService();
    $stamp = date('Y-m-d_His');
    $filename = "oc_maker_log_{$stamp}.{$format}";

    auditLog(
        'admin.activity_log.export',
        'activity_log',
        null,
        'Exportação do log de eventos',
        ['format' => $format, 'rows' => count($entries), 'action_filter' => $action, 'search' => $search],
    );

    match ($format) {
        'xlsx' => $export->streamXlsx($entries, $filename),
        'pdf' => $export->streamPdf($entries, $filename),
        default => $export->streamCsv($entries, $filename),
    };
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
