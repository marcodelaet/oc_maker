<?php

declare(strict_types=1);

namespace OcMaker;

final class InventoryScreenRepository
{
    /** @return array<string, mixed>|null */
    public function findByCode(string $screenCode): ?array
    {
        $code = trim($screenCode);
        if ($code === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT * FROM inventory_screens WHERE screen_code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function listByInventoryItem(int $inventoryItemId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM inventory_screens
             WHERE inventory_item_id = ?
             ORDER BY face_number, screen_code'
        );
        $stmt->execute([$inventoryItemId]);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function upsert(array $data): int
    {
        $screenCode = trim((string) ($data['screen_code'] ?? ''));
        if ($screenCode === '') {
            throw new \InvalidArgumentException('Código da tela é obrigatório.');
        }

        $inventoryItemId = (int) ($data['inventory_item_id'] ?? 0);
        if ($inventoryItemId <= 0) {
            throw new \InvalidArgumentException('Unidade de inventário inválida.');
        }

        $existing = $this->findByCode($screenCode);
        $params = [
            'inventory_item_id' => $inventoryItemId,
            'face_number' => max(1, (int) ($data['face_number'] ?? 1)),
            'cms' => $this->nullableString($data['cms'] ?? null),
            'os_name' => $this->nullableString($data['os_name'] ?? null),
            'is_online' => !empty($data['is_online']) ? 1 : 0,
            'offline_duration' => $this->offlineDuration($data),
            'offline_unit' => $this->offlineUnit($data),
            'screen_code' => $screenCode,
        ];

        if ($existing !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE inventory_screens SET
                   inventory_item_id = :inventory_item_id,
                   face_number = :face_number,
                   cms = :cms,
                   os_name = :os_name,
                   is_online = :is_online,
                   offline_duration = :offline_duration,
                   offline_unit = :offline_unit
                 WHERE screen_code = :screen_code'
            );
            $stmt->execute($params);

            return (int) $existing['id'];
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO inventory_screens
             (inventory_item_id, screen_code, face_number, cms, os_name, is_online, offline_duration, offline_unit)
             VALUES
             (:inventory_item_id, :screen_code, :face_number, :cms, :os_name, :is_online, :offline_duration, :offline_unit)'
        );
        $stmt->execute($params);

        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    private function offlineDuration(array $data): ?int
    {
        if (!empty($data['is_online'])) {
            return null;
        }

        $unit = $this->offlineUnit($data);
        if ($unit === 'nunca') {
            return 0;
        }

        $duration = (int) ($data['offline_duration'] ?? 0);

        return max(0, $duration);
    }

    /** @param array<string, mixed> $data */
    private function offlineUnit(array $data): ?string
    {
        if (!empty($data['is_online'])) {
            return null;
        }

        $unit = strtolower(trim((string) ($data['offline_unit'] ?? '')));
        $allowed = ['horas', 'dias', 'meses', 'anos', 'nunca'];

        return in_array($unit, $allowed, true) ? $unit : 'horas';
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
