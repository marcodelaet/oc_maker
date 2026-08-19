<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\ExcelService;

try {
    $adsId = (string) ($_POST['adsId'] ?? '');
    $spreadsheetKey = trim((string) ($_POST['spreadsheetKey'] ?? ''));
    $spreadsheet = resolveExistingSpreadsheetPath();

    $cached = readCampaignSnapshotCache($spreadsheet['path'], $spreadsheetKey, $adsId);
    if ($cached !== null) {
        jsonResponse(['campaign' => $cached]);
    }

    $excel = new ExcelService();
    $campaign = $excel->loadCampaign($spreadsheet['path'], $adsId !== '' ? $adsId : null);

    $payload = [
        'ads_id' => $campaign['ads_id'],
        'campanha' => $campaign['campanha'],
        'anunciante' => $campaign['anunciante'],
        'agencia' => $campaign['agencia'],
        'inicio' => $campaign['inicio'],
        'termino' => $campaign['termino'],
        'inventoryCount' => count($campaign['inventory']),
        'totals' => $campaign['totals'],
    ];

    writeCampaignSnapshotCache($spreadsheetKey, $adsId, $payload);

    jsonResponse(['campaign' => $payload]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
