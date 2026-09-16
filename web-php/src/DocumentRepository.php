<?php

declare(strict_types=1);

namespace OcMaker;

final class DocumentRepository
{
    /** @param array<string, mixed> $data */
    public function save(array $data): int
    {
        $sql = 'INSERT INTO documents (
            document_id, document_title, ads_id, campanha, anunciante, agencia,
            inicio, termino, tipo_venda, tipo_deal, planejador_ssp, deal_id, oc_informe_ssp,
            checking_fotografico, relatorios_adicionais, prazo_pagamento, prazo_unidade,
            valor_liquido_ssp, tech_fee_percent, tech_fee_value, valor_publisher, cpm_medio,
            total_lojas, total_insercoes, total_impactos, budget_bruto, budget_liquido,
            source_file, source_path, pdf_path
        ) VALUES (
            :document_id, :document_title, :ads_id, :campanha, :anunciante, :agencia,
            :inicio, :termino, :tipo_venda, :tipo_deal, :planejador_ssp, :deal_id, :oc_informe_ssp,
            :checking_fotografico, :relatorios_adicionais, :prazo_pagamento, :prazo_unidade,
            :valor_liquido_ssp, :tech_fee_percent, :tech_fee_value, :valor_publisher, :cpm_medio,
            :total_lojas, :total_insercoes, :total_impactos, :budget_bruto, :budget_liquido,
            :source_file, :source_path, :pdf_path
        )';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'document_id' => $data['document_id'],
            'document_title' => $data['document_title'],
            'ads_id' => $data['ads_id'] ?? null,
            'campanha' => $data['campanha'] ?? null,
            'anunciante' => $data['anunciante'] ?? null,
            'agencia' => $data['agencia'] ?? null,
            'inicio' => $data['inicio'] ?? null,
            'termino' => $data['termino'] ?? null,
            'tipo_venda' => $data['tipo_venda'] ?? null,
            'tipo_deal' => $data['tipo_deal'] ?? null,
            'planejador_ssp' => $data['planejador_ssp'] ?? null,
            'deal_id' => $data['deal_id'] ?? null,
            'oc_informe_ssp' => $data['oc_informe_ssp'] ?? null,
            'checking_fotografico' => !empty($data['checking_fotografico']) ? 1 : 0,
            'relatorios_adicionais' => !empty($data['relatorios_adicionais']) ? 1 : 0,
            'prazo_pagamento' => $data['prazo_pagamento'] ?? 15,
            'prazo_unidade' => $data['prazo_unidade'] ?? 'DFM',
            'valor_liquido_ssp' => $data['valor_liquido_ssp'] ?? null,
            'tech_fee_percent' => $data['tech_fee_percent'] ?? null,
            'tech_fee_value' => $data['tech_fee_value'] ?? null,
            'valor_publisher' => $data['valor_publisher'] ?? null,
            'cpm_medio' => $data['cpm_medio'] ?? null,
            'total_lojas' => $data['total_lojas'] ?? null,
            'total_insercoes' => $data['total_insercoes'] ?? null,
            'total_impactos' => $data['total_impactos'] ?? null,
            'budget_bruto' => $data['budget_bruto'] ?? null,
            'budget_liquido' => $data['budget_liquido'] ?? null,
            'source_file' => $data['source_file'] ?? null,
            'source_path' => $data['source_path'] ?? null,
            'pdf_path' => $data['pdf_path'] ?? null,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, document_id, campanha, anunciante
             FROM documents ORDER BY created_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function updateTotalLojas(int $id, int $totalLojas): void
    {
        $stmt = Database::connection()->prepare('UPDATE documents SET total_lojas = ? WHERE id = ?');
        $stmt->execute([$totalLojas, $id]);
    }

    public function deleteById(int $id): bool
    {
        $doc = $this->findById($id);
        if ($doc === null) {
            return false;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            (new InventoryRepository())->deleteLinksByDocument($id);

            $stmt = $pdo->prepare('DELETE FROM calculator_settings WHERE document_id = ?');
            $stmt->execute([$id]);

            $stmt = $pdo->prepare('DELETE FROM documents WHERE id = ?');
            $stmt->execute([$id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!empty($doc['pdf_path']) && is_file((string) $doc['pdf_path'])) {
            @unlink((string) $doc['pdf_path']);
        }

        return true;
    }
}
