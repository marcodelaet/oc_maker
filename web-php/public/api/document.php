<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentAccessService;
use OcMaker\DocumentRepository;
use OcMaker\DocumentService;

try {
    $user = DocumentAccessService::requireLogin();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'ID do documento inválido.'], 400);
    }

    $document = (new DocumentRepository())->findById($id);
    if ($document === null) {
        jsonResponse(['error' => 'Documento não encontrado.'], 404);
    }
    DocumentAccessService::assertHomeDocumentAccess($user, $document);

    $service = new DocumentService();
    $mode = (string) ($_GET['mode'] ?? 'full');
    if ($mode === 'summary') {
        jsonResponse($service->loadSummaryOnly($id));
    } else {
        jsonResponse($service->loadForHistory($id));
    }
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
