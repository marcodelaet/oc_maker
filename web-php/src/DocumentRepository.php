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
            source_file, source_path, pdf_path, created_by
        ) VALUES (
            :document_id, :document_title, :ads_id, :campanha, :anunciante, :agencia,
            :inicio, :termino, :tipo_venda, :tipo_deal, :planejador_ssp, :deal_id, :oc_informe_ssp,
            :checking_fotografico, :relatorios_adicionais, :prazo_pagamento, :prazo_unidade,
            :valor_liquido_ssp, :tech_fee_percent, :tech_fee_value, :valor_publisher, :cpm_medio,
            :total_lojas, :total_insercoes, :total_impactos, :budget_bruto, :budget_liquido,
            :source_file, :source_path, :pdf_path, :created_by
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
            'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20, ?int $createdBy = null): array
    {
        $sql = 'SELECT id, document_id, campanha, anunciante FROM documents';
        if ($createdBy !== null && $createdBy > 0) {
            $sql .= ' WHERE created_by = :created_by';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :lim';

        $stmt = Database::connection()->prepare($sql);
        if ($createdBy !== null && $createdBy > 0) {
            $stmt->bindValue(':created_by', $createdBy, \PDO::PARAM_INT);
        }
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

    /** @return array<string, mixed>|null */
    public function findByAdsId(string $adsId): ?array
    {
        $adsId = trim($adsId);
        if ($adsId === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT * FROM documents WHERE ads_id = :ads_id ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['ads_id' => $adsId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $sql = 'UPDATE documents SET
            document_title = :document_title,
            ads_id = :ads_id,
            campanha = :campanha,
            anunciante = :anunciante,
            agencia = :agencia,
            inicio = :inicio,
            termino = :termino,
            tipo_venda = :tipo_venda,
            tipo_deal = :tipo_deal,
            planejador_ssp = :planejador_ssp,
            deal_id = :deal_id,
            oc_informe_ssp = :oc_informe_ssp,
            checking_fotografico = :checking_fotografico,
            relatorios_adicionais = :relatorios_adicionais,
            prazo_pagamento = :prazo_pagamento,
            prazo_unidade = :prazo_unidade,
            valor_liquido_ssp = :valor_liquido_ssp,
            tech_fee_percent = :tech_fee_percent,
            tech_fee_value = :tech_fee_value,
            valor_publisher = :valor_publisher,
            cpm_medio = :cpm_medio,
            total_lojas = :total_lojas,
            total_insercoes = :total_insercoes,
            total_impactos = :total_impactos,
            budget_bruto = :budget_bruto,
            budget_liquido = :budget_liquido,
            source_file = :source_file,
            source_path = :source_path,
            pdf_path = :pdf_path
            WHERE id = :id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id' => $id,
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
    }

    public function setWorkflowStatus(
        int $id,
        string $status,
        ?string $rejectionReason = null,
        ?int $reviewedBy = null,
    ): void {
        $allowed = ['aguardando_aprovacao', 'aprovada', 'rejeitada', 'finalizada_pausada'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Status de campanha inválido.');
        }

        $stmt = Database::connection()->prepare(
            'UPDATE documents SET
               campaign_workflow_status = ?,
               campaign_rejection_reason = ?,
               campaign_reviewed_by = ?,
               campaign_reviewed_at = ?
             WHERE id = ?'
        );
        $reviewedAt = in_array($status, ['aprovada', 'rejeitada'], true) ? date('Y-m-d H:i:s') : null;
        $stmt->execute([
            $status,
            $rejectionReason,
            $reviewedBy,
            $reviewedAt,
            $id,
        ]);
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
            (new CampaignReviewRepository())->resetForDocument($id);

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
