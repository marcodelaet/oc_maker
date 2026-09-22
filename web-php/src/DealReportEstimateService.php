<?php

declare(strict_types=1);

namespace OcMaker;

/**
 * Distribui totais diários do relatório da plataforma (sem rede/tela)
 * proporcionalmente às unidades do Deal, usando pesos do inventário.
 */
final class DealReportEstimateService
{
    /**
     * @param list<array<string, mixed>> $rawReports
     * @param list<array<string, mixed>> $dealUnits
     * @param array<int, array<string, mixed>> $inventoryById
     * @return list<array<string, mixed>>
     */
    public function expandFromDealUnits(array $rawReports, array $dealUnits, array $inventoryById): array
    {
        $units = $this->resolveUnits($dealUnits, $inventoryById);
        if ($units === []) {
            return $rawReports;
        }

        $byDate = $this->aggregateDailyTotals($rawReports);
        if ($byDate === []) {
            return [];
        }

        $weights = array_column($units, 'weight');
        $expanded = [];

        foreach ($byDate as $date => $totals) {
            $distReq = $this->distributeInt((int) $totals['requisicoes'], $weights);
            $distImp = $this->distributeInt((int) $totals['impressoes'], $weights);
            $distImpacts = $this->distributeInt((int) $totals['impactos'], $weights);
            $distConsumo = $this->distributeFloat((float) $totals['consumo'], $weights);

            foreach ($units as $index => $unit) {
                $expanded[] = [
                    'report_date' => $date,
                    'screen_code' => $unit['screen_code'],
                    'network' => $unit['network'],
                    'requisicoes' => $distReq[$index],
                    'impressoes' => $distImp[$index],
                    'impactos' => $distImpacts[$index],
                    'moeda' => $totals['moeda'],
                    'cpm_aplicado' => $totals['cpm_aplicado'],
                    'consumo' => $distConsumo[$index],
                    '_estimated' => true,
                ];
            }
        }

        usort(
            $expanded,
            static function (array $a, array $b): int {
                $dateCmp = strcmp((string) ($b['report_date'] ?? ''), (string) ($a['report_date'] ?? ''));
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }

                $netCmp = strcmp((string) ($a['network'] ?? ''), (string) ($b['network'] ?? ''));
                if ($netCmp !== 0) {
                    return $netCmp;
                }

                return strcmp((string) ($a['screen_code'] ?? ''), (string) ($b['screen_code'] ?? ''));
            },
        );

        return $expanded;
    }

    /**
     * @param list<array<string, mixed>> $rawReports
     */
    public function shouldEstimate(array $rawReports, bool $hasPlatformScreens, bool $hasPlatformNetworks): bool
    {
        if ($hasPlatformScreens || $hasPlatformNetworks) {
            return false;
        }

        foreach ($rawReports as $row) {
            if (trim((string) ($row['report_date'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $dealUnits
     * @param array<int, array<string, mixed>> $inventoryById
     * @return array<string, string> screen_code => network
     */
    public function buildScreenNetworkMap(
        int $documentId,
        array $dealUnits,
        array $inventoryById,
        CampaignReviewRepository $reviews,
        InventoryScreenRepository $screens,
    ): array {
        $map = [];
        $screenReviews = $reviews->screenReviewsByDocument($documentId);

        foreach ($dealUnits as $dealUnit) {
            $itemId = (int) ($dealUnit['inventory_item_id'] ?? 0);
            if ($itemId <= 0 || !isset($inventoryById[$itemId])) {
                continue;
            }

            $network = trim((string) ($inventoryById[$itemId]['rede_name'] ?? ''));
            if ($network === '') {
                continue;
            }

            foreach ($screens->listByInventoryItem($itemId) as $screen) {
                $code = trim((string) ($screen['screen_code'] ?? ''));
                if ($code !== '') {
                    $map[$code] = $network;
                }
            }

            foreach ($screenReviews as $review) {
                if ((int) ($review['inventory_item_id'] ?? 0) !== $itemId) {
                    continue;
                }
                $code = trim((string) ($review['screen_code'] ?? ''));
                if ($code !== '') {
                    $map[$code] = $network;
                }
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @param array<string, string> $screenNetworkMap
     * @return list<array<string, mixed>>
     */
    public function enrichReportsWithInventoryNetwork(array $reports, array $screenNetworkMap): array
    {
        if ($screenNetworkMap === []) {
            return $reports;
        }

        $knownCodes = array_keys($screenNetworkMap);

        foreach ($reports as &$row) {
            if (trim((string) ($row['network'] ?? '')) !== '') {
                continue;
            }

            $screenCode = trim((string) ($row['screen_code'] ?? ''));
            if ($screenCode === '') {
                continue;
            }

            if (isset($screenNetworkMap[$screenCode])) {
                $row['network'] = $screenNetworkMap[$screenCode];
                continue;
            }

            $matched = AdmoohDeviceMatcher::match($screenCode, $knownCodes);
            if ($matched !== null) {
                $row['network'] = $screenNetworkMap[$matched];
            }
        }
        unset($row);

        return $reports;
    }

    /** @param list<array<string, mixed>> $reports */
    public function reportsHaveNetwork(array $reports): bool
    {
        foreach ($reports as $row) {
            if (trim((string) ($row['network'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $reports @return list<string> */
    public function listNetworksFromReports(array $reports): array
    {
        $networks = [];
        foreach ($reports as $row) {
            $network = trim((string) ($row['network'] ?? ''));
            if ($network !== '') {
                $networks[$network] = true;
            }
        }

        $list = array_keys($networks);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $dealUnits
     * @param array<int, array<string, mixed>> $inventoryById
     * @return list<string>
     */
    public function listNetworksFromDeal(array $dealUnits, array $inventoryById): array
    {
        $networks = [];
        foreach ($this->resolveUnits($dealUnits, $inventoryById) as $unit) {
            $network = trim((string) ($unit['network'] ?? ''));
            if ($network !== '') {
                $networks[$network] = true;
            }
        }

        $list = array_keys($networks);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $rawReports
     * @return array<string, array<string, mixed>>
     */
    private function aggregateDailyTotals(array $rawReports): array
    {
        $byDate = [];

        foreach ($rawReports as $row) {
            $date = substr((string) ($row['report_date'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }

            if (trim((string) ($row['screen_code'] ?? '')) !== '' || trim((string) ($row['network'] ?? '')) !== '') {
                continue;
            }

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'requisicoes' => 0,
                    'impressoes' => 0,
                    'impactos' => 0,
                    'consumo' => 0.0,
                    'moeda' => 'BRL',
                    'cpm_aplicado' => null,
                ];
            }

            foreach (['requisicoes', 'impressoes', 'impactos'] as $metric) {
                $value = $row[$metric] ?? null;
                if ($value !== null && $value !== '') {
                    $byDate[$date][$metric] += (int) $value;
                }
            }

            $consumo = $row['consumo'] ?? null;
            if ($consumo !== null && $consumo !== '') {
                $byDate[$date]['consumo'] += (float) $consumo;
            }

            $moeda = trim((string) ($row['moeda'] ?? ''));
            if ($moeda !== '') {
                $byDate[$date]['moeda'] = $moeda;
            }

            $cpm = $row['cpm_aplicado'] ?? null;
            if ($cpm !== null && $cpm !== '') {
                $byDate[$date]['cpm_aplicado'] = (float) $cpm;
            }
        }

        return $byDate;
    }

    /**
     * @param list<array<string, mixed>> $dealUnits
     * @param array<int, array<string, mixed>> $inventoryById
     * @return list<array{inventory_item_id: int, screen_code: string, network: string, weight: float}>
     */
    private function resolveUnits(array $dealUnits, array $inventoryById): array
    {
        $units = [];

        foreach ($dealUnits as $dealUnit) {
            $itemId = (int) ($dealUnit['inventory_item_id'] ?? 0);
            if ($itemId <= 0 || !isset($inventoryById[$itemId])) {
                continue;
            }

            $inv = $inventoryById[$itemId];
            $weight = (float) ($inv['impactos'] ?? 0);
            if ($weight <= 0) {
                $weight = (float) ($inv['insercoes'] ?? 0);
            }
            if ($weight <= 0) {
                $weight = 1.0;
            }

            $units[] = [
                'inventory_item_id' => $itemId,
                'screen_code' => trim((string) ($inv['codigo'] ?? '')),
                'network' => trim((string) ($inv['rede_name'] ?? '')),
                'weight' => $weight,
            ];
        }

        return $units;
    }

    /** @param list<float> $weights @return list<int> */
    private function distributeInt(int $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }
        if ($total <= 0) {
            return array_fill(0, $count, 0);
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            $equal = intdiv($total, $count);
            $results = array_fill(0, $count, $equal);
            $results[$count - 1] += $total - ($equal * $count);

            return $results;
        }

        $results = [];
        $allocated = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1) {
                $results[] = $total - $allocated;
                continue;
            }

            $share = (int) floor($total * $weights[$i] / $sum);
            $results[] = $share;
            $allocated += $share;
        }

        return $results;
    }

    /** @param list<float> $weights @return list<float> */
    private function distributeFloat(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }
        if ($total <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            $equal = round($total / $count, 2);
            $results = array_fill(0, $count, $equal);
            $results[$count - 1] = round($total - ($equal * ($count - 1)), 2);

            return $results;
        }

        $results = [];
        $allocated = 0.0;
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1) {
                $results[] = round($total - $allocated, 2);
                continue;
            }

            $share = round($total * $weights[$i] / $sum, 2);
            $results[] = $share;
            $allocated += $share;
        }

        return $results;
    }
}
