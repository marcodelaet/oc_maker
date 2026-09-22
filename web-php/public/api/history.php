<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentAccessService;
use OcMaker\DocumentRepository;

try {
    $user = DocumentAccessService::requireLogin();
    $repo = new DocumentRepository();
    $createdBy = DocumentAccessService::isAdmin($user) ? null : (int) $user['id'];
    jsonResponse(['documents' => $repo->recent(25, $createdBy)]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage(), 'documents' => []], 503);
}
