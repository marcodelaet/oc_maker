<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignReviewRepository
{
    public function resetForDocument(int $documentId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM campaign_screen_reviews WHERE document_id = ?')->execute([$documentId]);
        $pdo->prepare('DELETE FROM campaign_unit_reviews WHERE document_id = ?')->execute([$documentId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function unitReviewsByDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM campaign_unit_reviews WHERE document_id = ?'
        );
        $stmt->execute([$documentId]);
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['inventory_item_id']] = $row;
        }

        return $map;
    }

    /** @return array<string, array<string, mixed>> keyed by inventory_item_id:face_number */
    public function screenReviewsByDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM campaign_screen_reviews WHERE document_id = ? ORDER BY inventory_item_id, face_number'
        );
        $stmt->execute([$documentId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (int) $row['inventory_item_id'] . ':' . (int) $row['face_number'];
            $map[$key] = $row;
        }

        return $map;
    }

    /** @param array<string, mixed> $data */
    public function saveUnitReview(int $documentId, int $inventoryItemId, array $data, ?int $userId): void
    {
        $status = (string) ($data['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Status de unidade inválido.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO campaign_unit_reviews
             (document_id, inventory_item_id, status, rejection_reason, replacement_codigo, reviewed_by, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               status = VALUES(status),
               rejection_reason = VALUES(rejection_reason),
               replacement_codigo = VALUES(replacement_codigo),
               reviewed_by = VALUES(reviewed_by),
               reviewed_at = VALUES(reviewed_at)'
        );
        $reviewedAt = in_array($status, ['approved', 'rejected'], true) ? date('Y-m-d H:i:s') : null;
        $stmt->execute([
            $documentId,
            $inventoryItemId,
            $status,
            $this->nullableString($data['rejection_reason'] ?? null),
            $this->nullableString($data['replacement_codigo'] ?? null),
            $userId,
            $reviewedAt,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function saveScreenReview(int $documentId, int $inventoryItemId, array $data): void
    {
        $faceNumber = max(1, (int) ($data['face_number'] ?? 1));
        $screenCode = trim((string) ($data['screen_code'] ?? ''));
        if ($screenCode === '') {
            throw new \InvalidArgumentException('Código da tela é obrigatório.');
        }

        $isOnline = !empty($data['is_online']);
        $offlineUnit = null;
        $offlineDuration = null;
        if (!$isOnline) {
            $offlineUnit = strtolower(trim((string) ($data['offline_unit'] ?? 'horas')));
            if (!in_array($offlineUnit, ['horas', 'dias', 'meses', 'anos', 'nunca'], true)) {
                $offlineUnit = 'horas';
            }
            $offlineDuration = $offlineUnit === 'nunca' ? 0 : max(0, (int) ($data['offline_duration'] ?? 0));
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO campaign_screen_reviews
             (document_id, inventory_item_id, face_number, screen_code, cms, os_name, is_online, offline_duration, offline_unit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               screen_code = VALUES(screen_code),
               cms = VALUES(cms),
               os_name = VALUES(os_name),
               is_online = VALUES(is_online),
               offline_duration = VALUES(offline_duration),
               offline_unit = VALUES(offline_unit)'
        );
        $stmt->execute([
            $documentId,
            $inventoryItemId,
            $faceNumber,
            $screenCode,
            $this->nullableString($data['cms'] ?? null),
            $this->nullableString($data['os_name'] ?? null),
            $isOnline ? 1 : 0,
            $offlineDuration,
            $offlineUnit,
        ]);
    }

    /** @return list<string> */
    public function screenCodesForDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT csr.screen_code
             FROM campaign_screen_reviews csr
             WHERE csr.document_id = ?
               AND csr.screen_code <> \'\'
             ORDER BY csr.screen_code'
        );
        $stmt->execute([$documentId]);
        $codes = array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));

        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT s.screen_code
             FROM inventory_screens s
             INNER JOIN document_inventory di
               ON di.inventory_item_id = s.inventory_item_id
              AND di.document_id = ?
             WHERE s.screen_code <> \'\'
             ORDER BY s.screen_code'
        );
        $stmt->execute([$documentId]);
        $globalCodes = array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));

        return array_values(array_unique([...$codes, ...$globalCodes]));
    }

    /** @return list<string> */
    public function screenCodesForDeal(int $documentId, int $dealDbId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT csr.screen_code
             FROM campaign_screen_reviews csr
             INNER JOIN campaign_deal_units cdu
               ON cdu.inventory_item_id = csr.inventory_item_id
              AND cdu.deal_db_id = ?
             WHERE csr.document_id = ?
               AND csr.screen_code <> \'\'
             ORDER BY csr.screen_code'
        );
        $stmt->execute([$dealDbId, $documentId]);
        $codes = array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));

        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT s.screen_code
             FROM inventory_screens s
             INNER JOIN campaign_deal_units cdu
               ON cdu.inventory_item_id = s.inventory_item_id
              AND cdu.deal_db_id = ?
             INNER JOIN document_inventory di
               ON di.inventory_item_id = s.inventory_item_id
              AND di.document_id = ?
             WHERE s.screen_code <> \'\'
             ORDER BY s.screen_code'
        );
        $stmt->execute([$dealDbId, $documentId]);
        $globalCodes = array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));

        return array_values(array_unique([...$codes, ...$globalCodes]));
    }

    /** @return list<array<string, mixed>> */
    public function offlineScreensForDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT csr.*, ii.rede_name, ii.codigo AS unit_codigo
             FROM campaign_screen_reviews csr
             INNER JOIN inventory_items ii ON ii.id = csr.inventory_item_id
             WHERE csr.document_id = ? AND csr.is_online = 0
             ORDER BY csr.screen_code'
        );
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
