<?php



declare(strict_types=1);



require dirname(__DIR__, 2) . '/bootstrap.php';



use OcMaker\ExcelService;



try {

    $path = requireUpload();

    $archived = archiveUploadedSpreadsheet($path, $_FILES['file']['name'] ?? null);

    $excel = new ExcelService();

    $campaigns = $excel->listCampaigns($archived['path']);



    $campaign = null;

    if ($campaigns !== []) {

        $firstAdsId = $campaigns[0]['ads_id'];

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

    ]);

} catch (Throwable $e) {

    jsonResponse(['error' => $e->getMessage()], 400);

}

