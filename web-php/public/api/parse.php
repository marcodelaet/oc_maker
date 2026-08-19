<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\ExcelService;

try {
    $path = requireUpload();
    $archived = archiveUploadedSpreadsheet($path, $_FILES['file']['name'] ?? null);
    $excel = new ExcelService();
    $campaigns = $excel->listCampaigns($archived['path']);
    jsonResponse([
        'campaigns' => $campaigns,
        'spreadsheetKey' => $archived['key'],
        'fileName' => $archived['source_name'],
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
