<?php

declare(strict_types=1);

namespace OcMaker;

use PDO;

final class InventoryRepository
{
    private static ?bool $documentInventoryHasRedeName = null;
    private static ?bool $documentInventoryHasPublico = null;

    public static function normalizeRedeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }

    /** @param array<string, mixed> $row */
    public function upsertItem(array $row): int
    {
        $codigo = trim((string) ($row['codigo'] ?? ''));
        if ($codigo === '') {
            throw new \InvalidArgumentException('Código de inventário inválido.');
        }

        $redeName = trim((string) ($row['rede'] ?? ''));
        $redeId = $this->resolveNetworkId($redeName);
        $existing = $this->findByCodigo($codigo);
        if ($existing !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE inventory_items SET veiculo = ?, denominacao = ?, faces = ?, classe_social = ?,
                 regiao = ?, estado = ?, cidade = ?, segmento = ?, rede_id = ?, rede_name = ? WHERE id = ?'
            );
            $stmt->execute([
                $row['veiculo'] ?? null,
                $row['denominacao'] ?? null,
                $row['faces'] ?? null,
                $row['classe_social'] ?? null,
                $row['regiao'] ?? null,
                $row['estado'] ?? null,
                $row['cidade'] ?? null,
                $row['segmento'] ?? null,
                $redeId,
                $redeName,
                (int) $existing['id'],
            ]);

            return (int) $existing['id'];
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO inventory_items
             (codigo, veiculo, denominacao, faces, classe_social, regiao, estado, cidade, segmento, rede_id, rede_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $codigo,
            $row['veiculo'] ?? null,
            $row['denominacao'] ?? null,
            $row['faces'] ?? null,
            $row['classe_social'] ?? null,
            $row['regiao'] ?? null,
            $row['estado'] ?? null,
            $row['cidade'] ?? null,
            $row['segmento'] ?? null,
            $redeId,
            $redeName,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string, mixed> $metrics */
    public function linkToDocument(int $documentId, int $inventoryItemId, array $metrics): void
    {
        $redeName = trim((string) ($metrics['rede'] ?? ''));
        $publico = (float) ($metrics['publico'] ?? 0);
        $values = [
            (int) ($metrics['dias'] ?? 0),
            (float) ($metrics['insercoes'] ?? 0),
            (float) ($metrics['impactos'] ?? 0),
            (float) ($metrics['desconto'] ?? 0),
            (float) ($metrics['bruto_negociado'] ?? 0),
            (float) ($metrics['liquido'] ?? 0),
            (float) ($metrics['cpm'] ?? 0),
        ];

        $hasRede = self::documentInventoryHasRedeNameColumn();
        $hasPublico = self::documentInventoryHasPublicoColumn();
        $columns = ['document_id', 'inventory_item_id'];
        $placeholders = ['?', '?'];
        $bind = [$documentId, $inventoryItemId];
        $updates = [];

        if ($hasRede) {
            $columns[] = 'rede_name';
            $placeholders[] = '?';
            $bind[] = $redeName !== '' ? $redeName : null;
            $updates[] = 'rede_name = VALUES(rede_name)';
        }

        foreach ([
            'dias' => $values[0],
            'insercoes' => $values[1],
            'impactos' => $values[2],
            'desconto' => $values[3],
            'bruto_negociado' => $values[4],
            'liquido' => $values[5],
            'cpm' => $values[6],
        ] as $col => $val) {
            $columns[] = $col;
            $placeholders[] = '?';
            $bind[] = $val;
            $updates[] = "{$col} = VALUES({$col})";
        }

        if ($hasPublico) {
            $columns[] = 'publico';
            $placeholders[] = '?';
            $bind[] = $publico;
            $updates[] = 'publico = VALUES(publico)';
        }

        $sql = 'INSERT INTO document_inventory (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($bind);
    }

    /** @param list<array<string, mixed>> $inventory */
    public function syncDocumentInventory(int $documentId, array $inventory): void
    {
        foreach ($inventory as $row) {
            $itemId = $this->upsertItem($row);
            $this->linkToDocument($documentId, $itemId, $row);
        }
    }

    /** @param list<array<string, mixed>> $inventory */
    public function replaceDocumentInventory(int $documentId, array $inventory): void
    {
        $this->deleteLinksByDocument($documentId);
        $this->syncDocumentInventory($documentId, $inventory);
    }

    public function deleteLinksByDocument(int $documentId): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM document_inventory WHERE document_id = ?');
        $stmt->execute([$documentId]);

        return $stmt->rowCount();
    }

    public function countByDocument(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM document_inventory WHERE document_id = ?'
        );
        $stmt->execute([$documentId]);

        return (int) $stmt->fetchColumn();
    }

    public function countNetworksByDocument(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(DISTINCT ' . self::redeNameSql('di', 'ii') . ')
             FROM document_inventory di
             INNER JOIN inventory_items ii ON ii.id = di.inventory_item_id
             WHERE di.document_id = ?'
        );
        $stmt->execute([$documentId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function summarizeNetworksByDocument(int $documentId): array
    {
        $redeExpr = self::redeNameSql('di', 'ii');
        $stmt = Database::connection()->prepare(
            "SELECT {$redeExpr} AS rede_name,
                    COUNT(*) AS qtd_lojas,
                    COALESCE(SUM(di.liquido), 0) AS total_revenue,
                    MAX(rn.group_id) AS group_id,
                    MAX(rng.name) AS group_name
             FROM document_inventory di
             INNER JOIN inventory_items ii ON ii.id = di.inventory_item_id
             LEFT JOIN retail_networks rn ON rn.id = ii.rede_id
             LEFT JOIN retail_network_groups rng ON rng.id = rn.group_id
             WHERE di.document_id = ?
             GROUP BY {$redeExpr}
             ORDER BY {$redeExpr}"
        );
        $stmt->execute([$documentId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => trim((string) ($row['rede_name'] ?? '')) !== '',
        ));
    }

    public function countUnitsByDocument(int $documentId): int
    {
        return $this->countByDocument($documentId);
    }

    private static function redeNameSql(string $diAlias, string $iiAlias): string
    {
        if (self::documentInventoryHasRedeNameColumn()) {
            return "COALESCE(NULLIF({$diAlias}.rede_name, ''), {$iiAlias}.rede_name)";
        }

        return "{$iiAlias}.rede_name";
    }

    private static function documentInventoryHasRedeNameColumn(): bool
    {
        if (self::$documentInventoryHasRedeName !== null) {
            return self::$documentInventoryHasRedeName;
        }

        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM document_inventory LIKE 'rede_name'");
            self::$documentInventoryHasRedeName = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable) {
            self::$documentInventoryHasRedeName = false;
        }

        return self::$documentInventoryHasRedeName;
    }

    private static function documentInventoryHasPublicoColumn(): bool
    {
        if (self::$documentInventoryHasPublico !== null) {
            return self::$documentInventoryHasPublico;
        }

        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM document_inventory LIKE 'publico'");
            self::$documentInventoryHasPublico = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable) {
            self::$documentInventoryHasPublico = false;
        }

        return self::$documentInventoryHasPublico;
    }

    /** @return list<array<string, mixed>> */
    public function listByDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT di.*, ii.codigo, ii.denominacao, ii.faces, ii.cidade, ii.estado, ii.rede_name, ii.rede_id,
                    rn.group_id, rng.name AS group_name
             FROM document_inventory di
             INNER JOIN inventory_items ii ON ii.id = di.inventory_item_id
             LEFT JOIN retail_networks rn ON rn.id = ii.rede_id
             LEFT JOIN retail_network_groups rng ON rng.id = rn.group_id
             WHERE di.document_id = ?
             ORDER BY ii.rede_name, ii.codigo'
        );
        $stmt->execute([$documentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function findByCodigo(string $codigo): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM inventory_items WHERE codigo = ? LIMIT 1');
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function resolveNetworkId(string $redeName): ?int
    {
        if ($redeName === '') {
            return null;
        }
        $normalized = self::normalizeRedeName($redeName);
        $stmt = Database::connection()->prepare('SELECT id FROM retail_networks WHERE normalized_name = ? LIMIT 1');
        $stmt->execute([$normalized]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = Database::connection()->prepare(
            'INSERT INTO retail_networks (name, normalized_name) VALUES (?, ?)'
        );
        $insert->execute([$redeName, $normalized]);

        return (int) Database::connection()->lastInsertId();
    }
}
