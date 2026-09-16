<?php



declare(strict_types=1);



require dirname(__DIR__, 2) . '/bootstrap.php';



use OcMaker\ExcelService;



try {

    $adsId = (string) ($_POST['adsId'] ?? '');

    $spreadsheetKey = trim((string) ($_POST['spreadsheetKey'] ?? ''));

    $sourceDocumentId = (int) ($_POST['sourceDocumentId'] ?? 0);

    $spreadsheet = resolveExistingSpreadsheetPath();



    $cached = readCampaignSnapshotCache($spreadsheet['path'], $spreadsheetKey, $adsId, $sourceDocumentId);

    if ($cached !== null) {

        jsonResponse(['campaign' => $cached]);

    }



    $excel = new ExcelService();

    $campaign = $excel->loadCampaign($spreadsheet['path'], $adsId !== '' ? $adsId : null);

    $payload = campaignSnapshotPayload($campaign);



    writeCampaignSnapshotCache($spreadsheet['path'], $spreadsheetKey, $adsId, $payload, $sourceDocumentId);



    jsonResponse(['campaign' => $payload]);

} catch (Throwable $e) {

    jsonResponse(['error' => $e->getMessage()], 400);

}

