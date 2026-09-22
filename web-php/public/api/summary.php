<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\DocumentAccessService;
use OcMaker\DocumentRepository;
use OcMaker\ExcelService;
use OcMaker\TechFeeService;

try {
    $user = DocumentAccessService::requireLogin();
    $adsId = $_POST['adsId'] ?? null;
    $tipoVenda = (string) ($_POST['tipoVenda'] ?? 'SSP');
    $planejador = (string) ($_POST['planejadorSsp'] ?? 'Admooh');

    $sourceDocumentId = (int) ($_POST['sourceDocumentId'] ?? 0);
    if ($sourceDocumentId > 0) {
        $repo = new OcMaker\DocumentRepository();
        $existing = $repo->findById($sourceDocumentId);
        if ($existing === null) {
            jsonResponse(['error' => 'Documento não encontrado.'], 404);
        }
        DocumentAccessService::assertHomeDocumentAccess($user, $existing);
        $path = (string) ($existing['source_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            jsonResponse(['error' => 'Planilha original não encontrada.'], 400);
        }
    } else {
        $path = requireUpload();
    }

    $excel = new ExcelService();
    $campaign = $excel->loadCampaign($path, $adsId !== '' ? $adsId : null);
    if ($sourceDocumentId <= 0) {
        @unlink($path);
    }

    $fees = new TechFeeService();
    $fin = $fees->financials($campaign, $tipoVenda, $planejador);

    jsonResponse([
        'campaign' => [
            'ads_id' => $campaign['ads_id'],
            'campanha' => $campaign['campanha'],
            'anunciante' => $campaign['anunciante'],
            'agencia' => $campaign['agencia'],
            'inicio' => $campaign['inicio'],
            'termino' => $campaign['termino'],
            'inventoryCount' => count($campaign['inventory']),
        ],
        'financials' => [
            'totals' => $fin['totals'],
            'feePercent' => $fin['fee_percent'],
            'feeValue' => $fin['fee_value'],
            'valorPublisher' => $fin['valor_publisher'],
            'cpm' => $fin['cpm'],
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
