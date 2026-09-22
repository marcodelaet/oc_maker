<?php

declare(strict_types=1);

namespace OcMaker;

final class InventoryScreenCodeMigration
{
    /** @return array{updated:int, skipped:int, conflicts:list<string>} */
    public function run(): array
    {
        $updated = 0;
        $skipped = 0;
        $conflicts = [];
        $processed = [];

        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT s.screen_code, ii.denominacao
             FROM inventory_screens s
             INNER JOIN inventory_items ii ON ii.id = s.inventory_item_id
             ORDER BY s.id'
        );

        foreach ($stmt->fetchAll() as $row) {
            $result = $this->applyRename(
                trim((string) ($row['screen_code'] ?? '')),
                (string) ($row['denominacao'] ?? ''),
                $processed,
            );
            $updated += $result['updated'];
            $skipped += $result['skipped'];
            $conflicts = array_merge($conflicts, $result['conflicts']);
        }

        $reviewStmt = $pdo->query(
            'SELECT csr.screen_code, ii.denominacao
             FROM campaign_screen_reviews csr
             INNER JOIN inventory_items ii ON ii.id = csr.inventory_item_id
             ORDER BY csr.id'
        );

        foreach ($reviewStmt->fetchAll() as $row) {
            $result = $this->applyRename(
                trim((string) ($row['screen_code'] ?? '')),
                (string) ($row['denominacao'] ?? ''),
                $processed,
            );
            $updated += $result['updated'];
            $skipped += $result['skipped'];
            $conflicts = array_merge($conflicts, $result['conflicts']);
        }

        $synced = $this->syncReviewsFromInventoryScreens();
        $updated += $synced;

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'conflicts' => array_values(array_unique($conflicts)),
        ];
    }

    /**
     * @param array<string, true> $processed
     * @return array{updated:int, skipped:int, conflicts:list<string>}
     */
    private function applyRename(string $oldCode, string $denominacao, array &$processed): array
    {
        if ($oldCode === '' || isset($processed[$oldCode])) {
            return ['updated' => 0, 'skipped' => $oldCode === '' ? 0 : 1, 'conflicts' => []];
        }

        $processed[$oldCode] = true;
        $newCode = ScreenCodeHelper::ensureLocationSuffix($oldCode, $denominacao);
        if ($newCode === $oldCode) {
            return ['updated' => 0, 'skipped' => 1, 'conflicts' => []];
        }

        if (!$this->renameScreenCode($oldCode, $newCode)) {
            return [
                'updated' => 0,
                'skipped' => 1,
                'conflicts' => ["{$oldCode} → {$newCode} (código destino já existe)"],
            ];
        }

        $processed[$newCode] = true;

        return ['updated' => 1, 'skipped' => 0, 'conflicts' => []];
    }

    /** Alinha campaign_screen_reviews com inventory_screens (mesma unidade + face). */
    private function syncReviewsFromInventoryScreens(): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE campaign_screen_reviews csr
             INNER JOIN inventory_screens s
               ON s.inventory_item_id = csr.inventory_item_id
              AND s.face_number = csr.face_number
             SET csr.screen_code = s.screen_code
             WHERE csr.screen_code <> s.screen_code'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function renameScreenCode(string $oldCode, string $newCode): bool
    {
        if ($oldCode === $newCode) {
            return true;
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM inventory_screens WHERE screen_code = ? LIMIT 1');
        $check->execute([$newCode]);
        if ($check->fetch() !== false) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $updScreens = $pdo->prepare('UPDATE inventory_screens SET screen_code = ? WHERE screen_code = ?');
            $updScreens->execute([$newCode, $oldCode]);

            $updReviews = $pdo->prepare(
                'UPDATE campaign_screen_reviews SET screen_code = ? WHERE screen_code = ?'
            );
            $updReviews->execute([$newCode, $oldCode]);

            if ($updScreens->rowCount() === 0 && $updReviews->rowCount() === 0) {
                $pdo->rollBack();

                return false;
            }

            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
