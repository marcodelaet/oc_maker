<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

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

    $pdfDir = pdfStorageDir();
    $pdfFile = $pdfDir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $documentId) . '.pdf';

    $pdfService = new PdfService($fees);
    $pdfBinary = $pdfService->render($campaign, $options);
    if ($pdfBinary === '' || strncmp($pdfBinary, '%PDF', 4) !== 0) {
        throw new \RuntimeException('Falha ao gerar PDF. Verifique a planilha e tente novamente.');
    }
    file_put_contents($pdfFile, $pdfBinary);

    $repo = new DocumentRepository();
    $docDbId = $repo->save([
        'document_id' => $documentId,
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
    ]);

    (new InventoryRepository())->syncDocumentInventory($docDbId, $campaign['inventory']);

    auditLog(
        'document.create',
        'document',
        $documentId,
        'Documento registrado no sistema',
        [
            'db_id' => $docDbId,
            'campanha' => $campaign['campanha'] ?? null,
            'anunciante' => $campaign['anunciante'] ?? null,
            'document_title' => $options['document_title'],
        ],
    );
    auditLog(
        'document.pdf_generate',
        'document',
        $documentId,
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
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($pdfFile);
    exit;
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
