<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\CalculatorService;
use OcMaker\DocumentInventorySyncService;
use OcMaker\DocumentRepository;
use OcMaker\SessionAuth;

try {
    SessionAuth::requireRole('calculator');

    $id = (int) ($_GET['document_id'] ?? $_POST['document_id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['documents' => (new DocumentRepository())->recent(50)]);
    }
    $forceResync = filter_var($_GET['force_resync'] ?? $_POST['force_resync'] ?? false, FILTER_VALIDATE_BOOL);
    $sync = (new DocumentInventorySyncService())->ensureLinked($id, $forceResync);
    $data = (new CalculatorService())->build($id);
    $data['meta']['inventory_linked'] = $sync['linked'];
    $data['meta']['inventory_resynced'] = $sync['resynced'];
    $doc = $data['document'] ?? [];

    auditLog(
        'calculator.generate',
        'document',
        isset($doc['document_id']) ? (string) $doc['document_id'] : (string) $id,
        'Calculadora financeira gerada',
        [
            'db_id' => $id,
            'campanha' => $doc['campanha'] ?? null,
            'agencia' => $doc['agencia'] ?? null,
            'entries_count' => count($data['entries'] ?? []),
            'groups_count' => count($data['groups'] ?? []),
            'inventory_linked' => $sync['linked'],
            'inventory_expected' => $sync['expected'],
            'inventory_resynced' => $sync['resynced'],
        ],
    );

    jsonResponse([
        'ok' => true,
        'calculator' => $data,
        'inventory' => $sync,
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
