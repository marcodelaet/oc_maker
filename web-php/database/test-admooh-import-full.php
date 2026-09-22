<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\CampaignDealRepository;
use OcMaker\CampaignReviewRepository;
use OcMaker\Database;
use OcMaker\DealReportImportService;
use OcMaker\DocumentRepository;

$csvPath = $argv[1] ?? '';
$campaignHint = $argv[2] ?? 'BROTHER';

if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php database/test-admooh-import-full.php <csv-path> [campaign-hint]\n");
    exit(1);
}

$pdo = Database::connection();
$stmt = $pdo->prepare(
    "SELECT d.id, d.campanha, d.planejador_ssp
     FROM documents d
     WHERE d.campaign_workflow_status = 'aprovada'
       AND (d.campanha LIKE ? OR d.anunciante LIKE ?)
     ORDER BY d.id DESC
     LIMIT 5"
);
$like = '%' . $campaignHint . '%';
$stmt->execute([$like, $like]);
$docs = $stmt->fetchAll();

if ($docs === []) {
    fwrite(STDERR, "Nenhuma campanha aprovada encontrada para: {$campaignHint}\n");
    exit(1);
}

$doc = $docs[0];
$documentId = (int) $doc['id'];
echo 'Documento: ' . $doc['campanha'] . " (#{$documentId})\n";

$deals = (new CampaignDealRepository())->listByDocument($documentId);
if ($deals === []) {
    fwrite(STDERR, "Nenhum deal cadastrado.\n");
    exit(1);
}

$deal = $deals[0];
$dealDbId = (int) $deal['id'];
echo 'Deal: ' . ($deal['deal_id'] ?? '') . " (db #{$dealDbId})\n";

$known = (new CampaignReviewRepository())->screenCodesForDeal($documentId, $dealDbId);
echo 'Telas conhecidas no deal: ' . count($known) . "\n";
if ($known !== []) {
    echo '  Ex.: ' . implode(', ', array_slice($known, 0, 3)) . "\n";
}

$dealCpm = isset($deal['cpm']) ? (float) $deal['cpm'] : null;
echo 'CPM do deal: ' . ($dealCpm ?? 'null') . "\n";

$result = (new DealReportImportService())->importForDeal(
    $dealDbId,
    (string) ($deal['deal_id'] ?? ''),
    $csvPath,
    basename($csvPath),
    $known,
    $dealCpm,
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($result['inserted'] + $result['updated']) > 0 ? 0 : 1);
