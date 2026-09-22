<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\CampaignReviewRepository;
use OcMaker\DocumentAccessService;
use OcMaker\DocumentId;
use OcMaker\DocumentRepository;
use OcMaker\ExcelService;
use OcMaker\InventoryRepository;
use OcMaker\PdfFilename;
use OcMaker\PdfService;
use OcMaker\TechFeeService;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
    validateCsrf();
    $user = DocumentAccessService::requireLogin();

    $adsId = $_POST['adsId'] ?? null;
    $documentId = trim((string) ($_POST['documentId'] ?? ''));
    if ($documentId === '') {
        $documentId = DocumentId::generate();
    }

    $options = readDocumentOptionsFromPost($documentId);
    $spreadsheet = resolveSpreadsheetPath();

    $excel = new ExcelService();
    $campaign = $excel->loadCampaign($spreadsheet['path'], $adsId !== '' ? $adsId : null);

    $fees = new TechFeeService();
    $fin = $fees->financials($campaign, $options['tipo_venda'], $options['planejador_ssp']);

    $repo = new DocumentRepository();
    $campaignAdsId = trim((string) ($campaign['ads_id'] ?? ''));
    $existing = $campaignAdsId !== '' ? $repo->findByAdsId($campaignAdsId) : null;
    $storedDocumentId = $existing !== null
        ? (string) $existing['document_id']
        : $documentId;

    $pdfDir = pdfStorageDir();
    $pdfFile = $pdfDir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $storedDocumentId) . '.pdf';

    if ($existing !== null && !empty($existing['pdf_path'])) {
        $oldPdfPath = (string) $existing['pdf_path'];
        if ($oldPdfPath !== $pdfFile && is_file($oldPdfPath)) {
            @unlink($oldPdfPath);
        }
    }

    $pdfService = new PdfService($fees);
    $pdfBinary = $pdfService->render($campaign, $options);
    if ($pdfBinary === '' || strncmp($pdfBinary, '%PDF', 4) !== 0) {
        throw new \RuntimeException('Falha ao gerar PDF. Verifique a planilha e tente novamente.');
    }
    if (file_put_contents($pdfFile, $pdfBinary) === false) {
        throw new \RuntimeException('Falha ao gravar PDF em disco.');
    }

    $docPayload = [
        'document_id' => $storedDocumentId,
        'document_title' => $options['document_title'],
        'ads_id' => $campaign['ads_id'],
        'campanha' => $campaign['campanha'],
        'anunciante' => $campaign['anunciante'],
        'agencia' => $campaign['agencia'],
        'inicio' => $campaign['inicio'],
        'termino' => $campaign['termino'],
        'tipo_venda' => $options['tipo_venda'],
        'tipo_deal' => $options['tipo_deal'],
        'planejador_ssp' => $options['planejador_ssp'],
        'deal_id' => $options['deal_id'],
        'oc_informe_ssp' => $options['oc_informe_ssp'],
        'checking_fotografico' => $options['checking_fotografico'],
        'relatorios_adicionais' => $options['relatorios_adicionais'],
        'prazo_pagamento' => $options['prazo_pagamento'],
        'prazo_unidade' => $options['prazo_unidade'],
        'valor_liquido_ssp' => $fin['valor_ssp'],
        'tech_fee_percent' => $fin['fee_percent'],
        'tech_fee_value' => $fin['fee_value'],
        'valor_publisher' => $fin['valor_publisher'],
        'cpm_medio' => $fin['cpm'],
        'total_lojas' => count($campaign['inventory']),
        'total_insercoes' => $fin['totals']['insercoes'],
        'total_impactos' => $fin['totals']['impactos'],
        'budget_bruto' => $fin['totals']['bruto'],
        'budget_liquido' => $fin['totals']['liquido'],
        'source_file' => $spreadsheet['source_name'],
        'source_path' => $spreadsheet['stored_path'],
        'pdf_path' => $pdfFile,
    ];

    if ($existing !== null) {
        DocumentAccessService::assertHomeDocumentAccess($user, $existing);
        $docDbId = (int) $existing['id'];
        $repo->update($docDbId, $docPayload);
        (new CampaignReviewRepository())->resetForDocument($docDbId);
        (new InventoryRepository())->replaceDocumentInventory($docDbId, $campaign['inventory']);
        $repo->setWorkflowStatus($docDbId, 'aguardando_aprovacao');
    } else {
        $docPayload['created_by'] = (int) $user['id'];
        $docDbId = $repo->save($docPayload);
        $repo->setWorkflowStatus($docDbId, 'aguardando_aprovacao');
        (new InventoryRepository())->syncDocumentInventory($docDbId, $campaign['inventory']);
    }

    auditLog(
        $existing !== null ? 'document.update' : 'document.create',
        'document',
        $storedDocumentId,
        $existing !== null ? 'Documento atualizado no sistema' : 'Documento registrado no sistema',
        [
            'db_id' => $docDbId,
            'campanha' => $campaign['campanha'] ?? null,
            'anunciante' => $campaign['anunciante'] ?? null,
            'document_title' => $options['document_title'],
            'ads_id' => $campaignAdsId,
            'inventory_resynced' => $existing !== null,
        ],
    );
    auditLog(
        'document.pdf_generate',
        'document',
        $storedDocumentId,
        'PDF gerado',
        [
            'db_id' => $docDbId,
            'pdf_path' => basename($pdfFile),
            'campanha' => $campaign['campanha'] ?? null,
        ],
    );

    $filename = PdfFilename::build(
        (string) ($campaign['campanha'] ?? 'campanha'),
        (string) ($campaign['anunciante'] ?? ''),
    );
    if (!is_file($pdfFile)) {
        throw new \RuntimeException('PDF gerado não encontrado no disco.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($pdfFile);
    exit;
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
