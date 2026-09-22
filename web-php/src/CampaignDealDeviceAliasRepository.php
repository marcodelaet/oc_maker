<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignDealDeviceAliasRepository
{
    public const UNIDENTIFIED_PREFIX = '?unidentified:';

    private static bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS campaign_deal_device_aliases (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              deal_db_id INT UNSIGNED NOT NULL,
              device_name VARCHAR(512) NOT NULL,
              resolution ENUM(\'map\', \'add\', \'ignore\') NOT NULL,
              screen_code VARCHAR(255) NOT NULL DEFAULT \'\',
              inventory_item_id INT UNSIGNED NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_deal_device (deal_db_id, device_name(191)),
              KEY idx_deal (deal_db_id),
              CONSTRAINT fk_cdda_deal FOREIGN KEY (deal_db_id) REFERENCES campaign_deals(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }

    /** @return array<string, array{resolution: string, screen_code: string, inventory_item_id: int|null}> */
    public function mapByDeal(int $dealDbId): array
    {
        $this->ensureSchema();
        $stmt = Database::connection()->prepare(
            'SELECT device_name, resolution, screen_code, inventory_item_id
             FROM campaign_deal_device_aliases
             WHERE deal_db_id = ?'
        );
        $stmt->execute([$dealDbId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['device_name']] = [
                'resolution' => (string) $row['resolution'],
                'screen_code' => (string) $row['screen_code'],
                'inventory_item_id' => isset($row['inventory_item_id']) ? (int) $row['inventory_item_id'] : null,
            ];
        }

        return $map;
    }

    /** @param list<array{device_name: string, resolution: string, screen_code?: string, inventory_item_id?: int|null}> $items */
    public function saveBatch(int $dealDbId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->ensureSchema();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO campaign_deal_device_aliases
             (deal_db_id, device_name, resolution, screen_code, inventory_item_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               resolution = VALUES(resolution),
               screen_code = VALUES(screen_code),
               inventory_item_id = VALUES(inventory_item_id)'
        );

        foreach ($items as $item) {
            $deviceName = trim((string) ($item['device_name'] ?? ''));
            if ($deviceName === '') {
                continue;
            }
            $resolution = (string) ($item['resolution'] ?? '');
            if (!in_array($resolution, ['map', 'add', 'ignore'], true)) {
                throw new \InvalidArgumentException('Resolução inválida para tela: ' . $deviceName);
            }
            $screenCode = trim((string) ($item['screen_code'] ?? ''));
            if ($resolution === 'ignore') {
                $screenCode = self::unidentifiedScreenCode($deviceName);
            } elseif ($screenCode === '') {
                throw new \InvalidArgumentException('Informe o código da tela para: ' . $deviceName);
            }
            $inventoryItemId = isset($item['inventory_item_id']) ? (int) $item['inventory_item_id'] : null;
            $stmt->execute([
                $dealDbId,
                $deviceName,
                $resolution,
                $screenCode,
                $inventoryItemId > 0 ? $inventoryItemId : null,
            ]);
        }
    }

    public static function unidentifiedScreenCode(string $deviceName): string
    {
        $cleaned = AdmoohDeviceMatcher::cleanDeviceName($deviceName);
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $cleaned) ?? $cleaned;
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = substr(md5($deviceName), 0, 12);
        }

        $code = self::UNIDENTIFIED_PREFIX . $slug;

        return mb_strlen($code) > 250 ? mb_substr($code, 0, 250) : $code;
    }

    public static function isUnidentifiedScreenCode(string $screenCode): bool
    {
        return str_starts_with($screenCode, self::UNIDENTIFIED_PREFIX);
    }

    /** @return list<array<string, mixed>> */
    public function listByDeal(int $dealDbId): array
    {
        $this->ensureSchema();
        $stmt = Database::connection()->prepare(
            'SELECT device_name, resolution, screen_code, inventory_item_id, created_at, updated_at
             FROM campaign_deal_device_aliases
             WHERE deal_db_id = ?
             ORDER BY device_name'
        );
        $stmt->execute([$dealDbId]);

        return $stmt->fetchAll();
    }
}
