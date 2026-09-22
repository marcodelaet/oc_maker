<?php

declare(strict_types=1);

namespace OcMaker;

final class DocumentService
{
    public function __construct(
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly ExcelService $excel = new ExcelService(),
        private readonly TechFeeService $fees = new TechFeeService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function buildSummaryResponse(array $document, ?array $campaign = null, ?array $fin = null): array
    {
        if ($campaign === null || $fin === null) {
            return $this->summaryFromStored($document);
        }

        return [
            'campaign' => [
                'ads_id' => $campaign['ads_id'],
                'campanha' => $campaign['campanha'],
                'anunciante' => $campaign['anunciante'],
                'agencia' => $campaign['agencia'],
                'inicio' => $campaign['inicio'],
                'termino' => $campaign['termino'],
                'inventoryCount' => count($campaign['inventory']),
                'totals' => $fin['totals'],
            ],
            'tipoVenda' => (string) ($document['tipo_venda'] ?? 'SSP'),
            'financials' => [
                'totals' => $fin['totals'],
                'feePercent' => $fin['fee_percent'],
                'feeValue' => $fin['fee_value'],
                'brutoBase' => $fin['bruto_base'],
                'faturamento' => $fin['faturamento'],
                'valorPublisher' => $fin['valor_publisher'],
                'valorPublisherPdf' => $fin['valor_publisher_pdf'],
                'cpm' => $fin['cpm'],
            ],
        ];
    }

    /** @param array<string, mixed>|null $user */
    public function findForEditingByAdsId(string $adsId, ?array $user = null): ?array
    {
        $adsId = trim($adsId);
        if ($adsId === '') {
            return null;
        }

        $document = $this->documents->findByAdsId($adsId);
        if ($document === null) {
            return null;
        }

        if ($user !== null && !DocumentAccessService::canAccessHomeDocument($user, $document)) {
            return null;
        }

        return $this->publicDocument($document);
    }

    /** @return array{document: array<string, mixed>, summary: array<string, mixed>} */
    public function loadSummaryOnly(int $id): array
    {
        $document = $this->documents->findById($id);
        if ($document === null) {
            throw new \RuntimeException('Documento não encontrado.');
        }

        return [
            'document' => $this->publicDocument($document),
            'summary' => $this->summaryFromStored($document),
        ];
    }

    /** @return array{campaigns: list<array<string, mixed>>, summary: array<string, mixed>} */
    public function loadForHistory(int $id): array
    {
        $document = $this->documents->findById($id);
        if ($document === null) {
            throw new \RuntimeException('Documento não encontrado.');
        }

        $campaigns = [];
        $summary = $this->summaryFromStored($document);

        $sourcePath = (string) ($document['source_path'] ?? '');
        if ($sourcePath !== '' && is_file($sourcePath)) {
            $campaigns = $this->excel->listCampaigns($sourcePath);
        }

        return [
            'document' => $this->publicDocument($document),
            'campaigns' => $campaigns,
            'summary' => $summary,
        ];
    }

    /** @param array<string, mixed> $document */
    private function summaryFromStored(array $document): array
    {
        $budgetBruto = (float) ($document['budget_bruto'] ?? 0);
        $budgetLiquido = (float) ($document['budget_liquido'] ?? 0);
        $feePercent = (float) ($document['tech_fee_percent'] ?? 0);
        $feeRate = $feePercent / 100;
        $denom = max(0.0001, 1 - $feeRate);
        $brutoBase = (float) ($document['valor_liquido_ssp'] ?? ($budgetBruto / $denom));
        $feeValue = (float) ($document['tech_fee_value'] ?? (($budgetBruto * $feeRate) / $denom));

        return [
            'campaign' => [
                'ads_id' => $document['ads_id'] ?? '',
                'campanha' => $document['campanha'] ?? '',
                'anunciante' => $document['anunciante'] ?? '',
                'agencia' => $document['agencia'] ?? '',
                'inicio' => $document['inicio'] ?? null,
                'termino' => $document['termino'] ?? null,
                'inventoryCount' => (int) ($document['total_lojas'] ?? 0),
            ],
            'tipoVenda' => (string) ($document['tipo_venda'] ?? 'SSP'),
            'financials' => [
                'totals' => [
                    'insercoes' => (int) ($document['total_insercoes'] ?? 0),
                    'impactos' => (int) ($document['total_impactos'] ?? 0),
                    'bruto' => $budgetBruto,
                    'liquido' => $budgetLiquido,
                ],
                'feePercent' => $feePercent,
                'feeValue' => $feeValue,
                'brutoBase' => $brutoBase,
                'faturamento' => $budgetBruto,
                'valorPublisher' => $budgetLiquido,
                'valorPublisherPdf' => $budgetBruto,
                'cpm' => (float) ($document['cpm_medio'] ?? 0),
            ],
        ];
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function publicDocument(array $document): array
    {
        return [
            'id' => (int) $document['id'],
            'document_id' => $document['document_id'],
            'document_title' => $document['document_title'],
            'ads_id' => $document['ads_id'],
            'campanha' => $document['campanha'],
            'anunciante' => $document['anunciante'],
            'agencia' => $document['agencia'],
            'inicio' => $document['inicio'],
            'termino' => $document['termino'],
            'tipo_venda' => $document['tipo_venda'],
            'tipo_deal' => $document['tipo_deal'],
            'planejador_ssp' => $document['planejador_ssp'],
            'deal_id' => $document['deal_id'],
            'oc_informe_ssp' => $document['oc_informe_ssp'],
            'checking_fotografico' => (bool) ($document['checking_fotografico'] ?? true),
            'relatorios_adicionais' => (bool) ($document['relatorios_adicionais'] ?? false),
            'prazo_pagamento' => (int) ($document['prazo_pagamento'] ?? 15),
            'prazo_unidade' => $document['prazo_unidade'] ?? 'DFM',
            'source_file' => $document['source_file'],
            'source_path' => $document['source_path'],
            'created_at' => $document['created_at'],
        ];
    }
}
