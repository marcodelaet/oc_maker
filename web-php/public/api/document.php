<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentService;

try {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'ID do documento inválido.'], 400);
    }

    $service = new DocumentService();
    jsonResponse($service->loadForHistory($id));
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
