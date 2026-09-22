<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignDealRepository
{
    /** @return list<array<string, mixed>> */
    public function listByDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cd.*,
                    (SELECT COUNT(*) FROM campaign_deal_daily_reports r WHERE r.deal_db_id = cd.id) AS report_count
             FROM campaign_deals cd
             WHERE cd.document_id = ?
             ORDER BY cd.created_at, cd.deal_id'
        );
        $stmt->execute([$documentId]);
        $deals = $stmt->fetchAll();

        foreach ($deals as &$deal) {
            $deal['units'] = $this->unitsForDeal((int) $deal['id']);
        }
        unset($deal);

        return $deals;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $dealDbId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM campaign_deals WHERE id = ? LIMIT 1');
        $stmt->execute([$dealDbId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['units'] = $this->unitsForDeal((int) $row['id']);

        return $row;
    }

    public function hasReports(int $dealDbId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM campaign_deal_daily_reports WHERE deal_db_id = ?'
        );
        $stmt->execute([$dealDbId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $data @param list<int> $inventoryItemIds */
    public function save(int $documentId, array $data, array $inventoryItemIds): int
    {
        $dealId = trim((string) ($data['deal_id'] ?? ''));
        if ($dealId === '') {
            throw new \InvalidArgumentException('Informe o ID do Deal.');
        }

        $pdo = Database::connection();
        $existingId = (int) ($data['id'] ?? 0);
        $params = [
            'document_id' => $documentId,
            'deal_id' => $dealId,
            'slots' => max(1, (int) ($data['slots'] ?? 1)),
            'screen_type' => $this->nullableString($data['screen_type'] ?? null),
            'target_impressions' => $this->nullableInt($data['target_impressions'] ?? null),
            'target_impactos' => $this->nullableInt($data['target_impactos'] ?? null),
            'target_consumo' => $this->nullableFloat($data['target_consumo'] ?? null),
            'deal_value' => $this->nullableFloat($data['deal_value'] ?? null),
            'fee_adjust_percent' => $this->nullableFloat($data['fee_adjust_percent'] ?? null),
            'cpm' => $this->nullableFloat($data['cpm'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];

        if ($existingId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE campaign_deals SET
                   deal_id = :deal_id, slots = :slots, screen_type = :screen_type,
                   target_impressions = :target_impressions, target_impactos = :target_impactos,
                   target_consumo = :target_consumo, deal_value = :deal_value,
                   fee_adjust_percent = :fee_adjust_percent, cpm = :cpm, notes = :notes
                 WHERE id = :id AND document_id = :document_id'
            );
            $params['id'] = $existingId;
            $stmt->execute($params);
            $dealDbId = $existingId;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO campaign_deals
                 (document_id, deal_id, slots, screen_type, target_impressions, target_impactos,
                  target_consumo, deal_value, fee_adjust_percent, cpm, notes)
                 VALUES
                 (:document_id, :deal_id, :slots, :screen_type, :target_impressions, :target_impactos,
                  :target_consumo, :deal_value, :fee_adjust_percent, :cpm, :notes)'
            );
            $stmt->execute($params);
            $dealDbId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM campaign_deal_units WHERE deal_db_id = ?')->execute([$dealDbId]);
        $insert = $pdo->prepare(
            'INSERT INTO campaign_deal_units (deal_db_id, inventory_item_id, unit_slots) VALUES (?, ?, ?)'
        );
        foreach ($inventoryItemIds as $itemId) {
            $insert->execute([$dealDbId, (int) $itemId, max(1, (int) ($data['slots'] ?? 1))]);
        }

        return $dealDbId;
    }

    public function delete(int $dealDbId, int $documentId): void
    {
        if ($this->hasReports($dealDbId)) {
            throw new \InvalidArgumentException('Este Deal possui histórico de relatórios e não pode ser removido.');
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM campaign_deals WHERE id = ? AND document_id = ?'
        );
        $stmt->execute([$dealDbId, $documentId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Deal não encontrado.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function unitsForDeal(int $dealDbId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cdu.inventory_item_id, cdu.unit_slots
             FROM campaign_deal_units cdu
             WHERE cdu.deal_db_id = ?'
        );
        $stmt->execute([$dealDbId]);

        return $stmt->fetchAll();
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
