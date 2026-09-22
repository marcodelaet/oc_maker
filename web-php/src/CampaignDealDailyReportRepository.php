<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignDealDailyReportRepository
{
    public function hasScreenBreakdown(int $dealDbId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM campaign_deal_daily_reports
             WHERE deal_db_id = ? AND screen_code <> ''
             LIMIT 1"
        );
        $stmt->execute([$dealDbId]);

        return (bool) $stmt->fetchColumn();
    }

    public function hasNetworkBreakdown(int $dealDbId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM campaign_deal_daily_reports
             WHERE deal_db_id = ? AND network <> ''
             LIMIT 1"
        );
        $stmt->execute([$dealDbId]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function listNetworksByDeal(int $dealDbId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT network FROM campaign_deal_daily_reports
             WHERE deal_db_id = ? AND network <> ''
             ORDER BY network ASC"
        );
        $stmt->execute([$dealDbId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<array<string, mixed>> */
    public function listByDeal(int $dealDbId, int $limit = 120): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, deal_db_id, report_date, screen_code, network, requisicoes, impressoes, impactos,
                    moeda, cpm_aplicado, consumo, created_at, updated_at
             FROM campaign_deal_daily_reports
             WHERE deal_db_id = ?
             ORDER BY report_date DESC, network ASC, screen_code ASC
             LIMIT ' . max(1, min(5000, $limit))
        );
        $stmt->execute([$dealDbId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findByDealDateScreenNetwork(
        int $dealDbId,
        string $reportDate,
        string $screenCode = '',
        string $network = '',
    ): ?array {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM campaign_deal_daily_reports
             WHERE deal_db_id = ? AND report_date = ? AND screen_code = ? AND network = ?
             LIMIT 1'
        );
        $stmt->execute([$dealDbId, $reportDate, $screenCode, $network]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function dailyTotalsByDeal(int $dealDbId, ?string $network = null): array
    {
        $sql = 'SELECT report_date,
                    COALESCE(SUM(requisicoes), 0) AS requisicoes,
                    COALESCE(SUM(impressoes), 0) AS impressoes,
                    COALESCE(SUM(impactos), 0) AS impactos,
                    COALESCE(SUM(consumo), 0) AS consumo
             FROM campaign_deal_daily_reports
             WHERE deal_db_id = ?';
        $params = [$dealDbId];
        if ($network !== null && $network !== '') {
            $sql .= ' AND network = ?';
            $params[] = $network;
        }
        $sql .= ' GROUP BY report_date ORDER BY report_date ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{requisicoes: float, impressoes: float, impactos: float, consumo: float, row_count: int} */
    public function totalsByDeal(int $dealDbId, bool $aggregateOnly = false): array
    {
        $sql = 'SELECT
                    COALESCE(SUM(requisicoes), 0) AS requisicoes,
                    COALESCE(SUM(impressoes), 0) AS impressoes,
                    COALESCE(SUM(impactos), 0) AS impactos,
                    COALESCE(SUM(consumo), 0) AS consumo,
                    COUNT(*) AS row_count
                FROM campaign_deal_daily_reports
                WHERE deal_db_id = ?';
        if ($aggregateOnly) {
            $sql .= " AND screen_code = '' AND network = ''";
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$dealDbId]);
        $row = $stmt->fetch();

        return [
            'requisicoes' => (float) ($row['requisicoes'] ?? 0),
            'impressoes' => (float) ($row['impressoes'] ?? 0),
            'impactos' => (float) ($row['impactos'] ?? 0),
            'consumo' => (float) ($row['consumo'] ?? 0),
            'row_count' => (int) ($row['row_count'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $data @return 'inserted'|'updated'|'skipped' */
    public function upsert(int $dealDbId, array $data): string
    {
        $screenCode = trim((string) ($data['screen_code'] ?? ''));
        $network = trim((string) ($data['network'] ?? ''));
        $reportDate = (string) ($data['report_date'] ?? '');
        $existing = $this->findByDealDateScreenNetwork($dealDbId, $reportDate, $screenCode, $network);

        if ($existing === null) {
            $this->insert($dealDbId, $data);

            return 'inserted';
        }

        $merged = $this->mergeWithMax($existing, $data);
        if ($this->rowChanged($existing, $merged)) {
            $this->update((int) $existing['id'], $merged);

            return 'updated';
        }

        return 'skipped';
    }

    /** @param array<string, mixed> $data */
    private function insert(int $dealDbId, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO campaign_deal_daily_reports
             (deal_db_id, report_date, screen_code, network, requisicoes, impressoes, impactos, moeda, cpm_aplicado, consumo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dealDbId,
            $data['report_date'],
            trim((string) ($data['screen_code'] ?? '')),
            trim((string) ($data['network'] ?? '')),
            $this->nullableInt($data['requisicoes'] ?? null),
            $this->nullableInt($data['impressoes'] ?? null),
            $this->nullableInt($data['impactos'] ?? null),
            trim((string) ($data['moeda'] ?? 'BRL')) ?: 'BRL',
            $this->nullableFloat($data['cpm_aplicado'] ?? null),
            $this->nullableFloat($data['consumo'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function update(int $id, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE campaign_deal_daily_reports SET
               requisicoes = :requisicoes, impressoes = :impressoes, impactos = :impactos,
               moeda = :moeda, cpm_aplicado = :cpm_aplicado, consumo = :consumo,
               network = :network
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'requisicoes' => $this->nullableInt($data['requisicoes'] ?? null),
            'impressoes' => $this->nullableInt($data['impressoes'] ?? null),
            'impactos' => $this->nullableInt($data['impactos'] ?? null),
            'moeda' => trim((string) ($data['moeda'] ?? 'BRL')) ?: 'BRL',
            'cpm_aplicado' => $this->nullableFloat($data['cpm_aplicado'] ?? null),
            'consumo' => $this->nullableFloat($data['consumo'] ?? null),
            'network' => trim((string) ($data['network'] ?? '')),
        ]);
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeWithMax(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach (['requisicoes', 'impressoes', 'impactos'] as $field) {
            $merged[$field] = $this->maxNullableInt($existing[$field] ?? null, $incoming[$field] ?? null);
        }
        $merged['consumo'] = $this->maxNullableFloat($existing['consumo'] ?? null, $incoming['consumo'] ?? null);
        $merged['cpm_aplicado'] = $this->maxNullableFloat($existing['cpm_aplicado'] ?? null, $incoming['cpm_aplicado'] ?? null);

        $incomingMoeda = trim((string) ($incoming['moeda'] ?? ''));
        if ($incomingMoeda !== '') {
            $merged['moeda'] = $incomingMoeda;
        }

        $incomingNetwork = trim((string) ($incoming['network'] ?? ''));
        if ($incomingNetwork !== '') {
            $merged['network'] = $incomingNetwork;
        }

        return $merged;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function rowChanged(array $before, array $after): bool
    {
        foreach (['requisicoes', 'impressoes', 'impactos', 'consumo', 'cpm_aplicado', 'moeda', 'network'] as $field) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function maxNullableInt(mixed $existing, mixed $incoming): ?int
    {
        if ($incoming === null || $incoming === '') {
            return $this->nullableInt($existing);
        }
        $in = (int) $incoming;
        $ex = $this->nullableInt($existing);
        if ($ex === null) {
            return $in;
        }

        return max($ex, $in);
    }

    private function maxNullableFloat(mixed $existing, mixed $incoming): ?float
    {
        if ($incoming === null || $incoming === '') {
            return $this->nullableFloat($existing);
        }
        $in = (float) $incoming;
        $ex = $this->nullableFloat($existing);
        if ($ex === null) {
            return $in;
        }

        return max($ex, $in);
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
