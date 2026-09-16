<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentRepository;

try {
    $repo = new DocumentRepository();
    jsonResponse(['documents' => $repo->recent(25)]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage(), 'documents' => []], 503);
}
