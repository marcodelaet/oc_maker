<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use OcMaker\DocumentRepository;
use OcMaker\InventoryRepository;
use OcMaker\SessionAuth;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();
    SessionAuth::requireLogin();
    if (!SessionAuth::isAdmin()) {
        jsonResponse(['error' => 'Acesso restrito a administradores.'], 403);
    }

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'ID inválido.'], 400);
    }

    $repo = new DocumentRepository();
    $doc = $repo->findById($id);
    if ($doc === null) {
        jsonResponse(['error' => 'Documento não encontrado.'], 404);
    }

    $inventoryLinks = (new InventoryRepository())->countByDocument($id);

    if (!$repo->deleteById($id)) {
        jsonResponse(['error' => 'Documento não encontrado.'], 404);
    }

    auditLog(
        'document.delete',
        'document',
        (string) ($doc['document_id'] ?? $id),
        'Documento excluído do histórico',
        [
            'db_id' => $id,
            'campanha' => $doc['campanha'] ?? null,
            'anunciante' => $doc['anunciante'] ?? null,
            'inventory_links_removed' => $inventoryLinks,
        ],
    );

    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
