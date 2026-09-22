<?php



declare(strict_types=1);



require dirname(__DIR__, 2) . '/bootstrap.php';



use OcMaker\DocumentAccessService;
use OcMaker\DocumentService;
use OcMaker\ExcelService;



try {
    $user = DocumentAccessService::requireLogin();

    $adsId = (string) ($_POST['adsId'] ?? '');

    $spreadsheetKey = trim((string) ($_POST['spreadsheetKey'] ?? ''));

    $sourceDocumentId = (int) ($_POST['sourceDocumentId'] ?? 0);

    $spreadsheet = resolveExistingSpreadsheetPath();



    $existingDocument = $adsId !== ''
        ? (new DocumentService())->findForEditingByAdsId($adsId, $user)
        : null;

    $cached = readCampaignSnapshotCache($spreadsheet['path'], $spreadsheetKey, $adsId, $sourceDocumentId);

    if ($cached !== null) {

        jsonResponse(['campaign' => $cached, 'existingDocument' => $existingDocument]);

    }



    $excel = new ExcelService();

    $campaign = $excel->loadCampaign($spreadsheet['path'], $adsId !== '' ? $adsId : null);

    $payload = campaignSnapshotPayload($campaign);



    writeCampaignSnapshotCache($spreadsheet['path'], $spreadsheetKey, $adsId, $payload, $sourceDocumentId);



    jsonResponse(['campaign' => $payload, 'existingDocument' => $existingDocument]);

} catch (Throwable $e) {

    jsonResponse(['error' => $e->getMessage()], 400);

}

