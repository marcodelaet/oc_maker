<?php



declare(strict_types=1);

set_time_limit(120);
ini_set('memory_limit', '256M');

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentAccessService;
use OcMaker\DocumentService;
use OcMaker\ExcelService;

$user = DocumentAccessService::requireLogin();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}



try {

    $path = requireUpload();

    $archived = archiveUploadedSpreadsheet($path, $_FILES['file']['name'] ?? null);

    $excel = new ExcelService();

    $campaigns = $excel->listCampaigns($archived['path']);



    $campaign = null;
    $existingDocument = null;

    if ($campaigns !== []) {

        $firstAdsId = $campaigns[0]['ads_id'];

        $existingDocument = (new DocumentService())->findForEditingByAdsId($firstAdsId, $user);

        $full = $excel->loadCampaign($archived['path'], $firstAdsId);

        $campaign = campaignSnapshotPayload($full);

        writeCampaignSnapshotCache($archived['path'], $archived['key'], $firstAdsId, $campaign);

    }



    auditLog(
        'spreadsheet.upload',
        'spreadsheet',
        $archived['key'],
        'Planilha enviada para geração de documento',
        [
            'file_name' => $archived['source_name'],
            'campaigns_found' => count($campaigns),
        ],
    );

    jsonResponse([

        'campaigns' => $campaigns,

        'spreadsheetKey' => $archived['key'],

        'fileName' => $archived['source_name'],

        'campaign' => $campaign,

        'existingDocument' => $existingDocument,

    ]);

} catch (Throwable $e) {

    jsonResponse(['error' => $e->getMessage()], 400);

}

