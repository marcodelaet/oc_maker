<?php

declare(strict_types=1);

namespace OcMaker;

final class CalculatorService
{
    public function __construct(
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly InventoryRepository $inventory = new InventoryRepository(),
    ) {
    }

    /** @return array<string, mixed> */
    public function build(int $documentId): array
    {
        $document = $this->documents->findById($documentId);
        if ($document === null) {
            throw new \RuntimeException('Documento não encontrado.');
        }

        $networks = $this->inventory->summarizeNetworksByDocument($documentId);
        $units = $this->inventory->countUnitsByDocument($documentId);

        $piDate = PaymentTermService::calcPaymentDate(
            $document['termino'] ?? null,
            (int) ($document['prazo_pagamento'] ?? 15),
            (string) ($document['prazo_unidade'] ?? 'DFM')
        );
        $repasseDate = $piDate ? PaymentTermService::calcRepasseDate($piDate) : null;

        $byGroup = [];
        $entries = [];
        foreach ($networks as $network) {
            $entry = $this->entryFromDocument($document, [
                'rede' => (string) ($network['rede_name'] ?? 'Sem rede'),
                'qtd_lojas' => (int) ($network['qtd_lojas'] ?? 0),
                'total_revenue' => (float) ($network['total_revenue'] ?? 0),
                'group_name' => $network['group_name'] ?? null,
            ], $piDate, $repasseDate);

            $entries[] = $entry;

            $groupKey = $network['group_name'] ?? null;
            if ($groupKey) {
                if (!isset($byGroup[$groupKey])) {
                    $byGroup[$groupKey] = [
                        'group_name' => $groupKey,
                        'group_id' => $network['group_id'] ?? null,
                        'redes' => [],
                        'qtd_lojas' => 0,
                        'total_revenue' => 0.0,
                    ];
                }
                $byGroup[$groupKey]['qtd_lojas'] += (int) ($network['qtd_lojas'] ?? 0);
                $byGroup[$groupKey]['total_revenue'] += (float) ($network['total_revenue'] ?? 0);
                $byGroup[$groupKey]['redes'][(string) ($network['rede_name'] ?? '')] = true;
            }
        }

        $groups = [];
        foreach ($byGroup as $group) {
            $group['redes'] = array_keys($group['redes']);
            $groups[] = $this->entryFromDocument($document, [
                'rede' => $group['group_name'],
                'group_name' => $group['group_name'],
                'qtd_lojas' => $group['qtd_lojas'],
                'total_revenue' => $group['total_revenue'],
            ], $piDate, $repasseDate, true);
        }

        return [
            'document' => [
                'id' => (int) $document['id'],
                'document_id' => $document['document_id'],
                'campanha' => $document['campanha'],
                'agencia' => $document['agencia'],
                'inicio' => $document['inicio'],
                'termino' => $document['termino'],
            ],
            'meta' => [
                'units' => $units,
                'networks' => count($entries),
            ],
            'entries' => $entries,
            'groups' => $groups,
        ];
    }

    /** @param array<string, mixed> $document
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function entryFromDocument(
        array $document,
        array $metrics,
        ?\DateTimeImmutable $piDate,
        ?\DateTimeImmutable $repasseDate,
        bool $isGroup = false,
    ): array {
        $inicio = $document['inicio'] ?? null;
        $termino = $document['termino'] ?? null;
        $periodo = ($inicio && $termino)
            ? PaymentTermService::formatDateBr((string) $inicio) . ' - ' . PaymentTermService::formatDateBr((string) $termino)
            : '—';

        return [
            'is_group' => $isGroup,
            'agencia' => $document['agencia'] ?? '',
            'varejista' => $metrics['rede'] ?? '',
            'tipo_produto' => 'PADRÃO',
            'tipo_compra' => 'PROGRAMÁTICA',
            'qtd_lojas' => (int) ($metrics['qtd_lojas'] ?? 0),
            'periodo_veiculacao' => $periodo,
            'total_revenue' => round((float) ($metrics['total_revenue'] ?? 0), 2),
            'revenue_varejista_percent' => 0.0,
            'revenue_varejista_value' => 0.0,
            'prazo_recebimento_pi' => $piDate?->format('d/m/Y') ?? '—',
            'prazo_pagamento_repasse' => $repasseDate?->format('d/m/Y') ?? '—',
            'group_name' => $metrics['group_name'] ?? null,
        ];
    }
}

