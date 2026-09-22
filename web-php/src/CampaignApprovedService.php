<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignApprovedService
{
    private const CREATIVE_MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly DocumentRepository $documents = new DocumentRepository(),
        private readonly InventoryRepository $inventory = new InventoryRepository(),
        private readonly CampaignReviewRepository $reviews = new CampaignReviewRepository(),
        private readonly CampaignDealRepository $deals = new CampaignDealRepository(),
        private readonly CampaignCreativeRepository $creatives = new CampaignCreativeRepository(),
        private readonly InventoryScreenRepository $screens = new InventoryScreenRepository(),
        private readonly CmsPlaybookService $playbook = new CmsPlaybookService(),
        private readonly CreativeMediaProbe $mediaProbe = new CreativeMediaProbe(),
        private readonly CampaignDealDailyReportRepository $dealReports = new CampaignDealDailyReportRepository(),
        private readonly DealReportImportService $dealReportImport = new DealReportImportService(),
        private readonly DealReportEstimateService $reportEstimate = new DealReportEstimateService(),
        private readonly CampaignDealDeviceAliasRepository $deviceAliases = new CampaignDealDeviceAliasRepository(),
    ) {
    }

    /** @return array<string, mixed> */
    public function context(int $documentId): array
    {
        (new CampaignManagementService())->finalizeDocumentIfEligible($documentId);
        $doc = $this->assertAccessible($documentId);
        $readOnly = $this->isReadOnlyStatus((string) ($doc['campaign_workflow_status'] ?? ''));

        $inventoryRows = $this->approvedInventory($documentId);
        $dealList = $this->deals->listByDocument($documentId);
        $creativeList = $this->enrichCreativeMetadata($this->creatives->listByDocument($documentId));
        $screenMap = $this->screensByCms($documentId, $inventoryRows);

        return [
            'document' => $this->publicDocument($doc),
            'read_only' => $readOnly,
            'inventory' => array_map([$this, 'mapInventoryRow'], $inventoryRows),
            'deals' => $dealList,
            'creatives' => array_map([$this, 'mapCreative'], $creativeList),
            'playbook' => $readOnly ? null : $this->playbook->build($doc, $dealList, $screenMap['Onsign'] ?? [], $screenMap['Invian'] ?? []),
            'planning_url' => 'https://planning.invian.net/planner/?vehicle=CONVERTAADS&adsid=' . urlencode((string) ($doc['ads_id'] ?? '')),
            'control' => $this->buildControlSummary($doc, $dealList),
            'offline_screens' => array_map(
                fn(array $row): array => [
                    'screen_code' => (string) ($row['screen_code'] ?? ''),
                    'rede_name' => (string) ($row['rede_name'] ?? ''),
                    'offline_duration' => (int) ($row['offline_duration'] ?? 0),
                    'offline_unit' => (string) ($row['offline_unit'] ?? 'horas'),
                ],
                $this->reviews->offlineScreensForDocument($documentId),
            ),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function saveDeal(int $documentId, array $payload): array
    {
        $this->assertEditable($documentId);
        $inventoryRows = $this->approvedInventory($documentId);
        $byId = [];
        foreach ($inventoryRows as $row) {
            $byId[(int) $row['inventory_item_id']] = $row;
        }

        $unitIds = array_values(array_unique(array_map('intval', $payload['inventory_item_ids'] ?? [])));
        if ($unitIds === []) {
            throw new \InvalidArgumentException('Selecione ao menos uma unidade para o Deal.');
        }

        $sumBruto = 0.0;
        $sumImpactos = 0.0;
        $sumInsercoes = 0.0;
        $dias = 0;
        $faces = 0;
        foreach ($unitIds as $itemId) {
            if (!isset($byId[$itemId])) {
                throw new \InvalidArgumentException('Unidade inválida para este documento.');
            }
            $row = $byId[$itemId];
            $sumBruto += (float) ($row['bruto_negociado'] ?? 0);
            $sumImpactos += (float) ($row['impactos'] ?? 0);
            $sumInsercoes += (float) ($row['insercoes'] ?? 0);
            $dias = max($dias, (int) ($row['dias'] ?? 0));
            $faces = max($faces, max(1, (int) ($row['faces'] ?? 1)));
        }

        if ($dias <= 0) {
            $doc = $this->documents->findById($documentId);
            $dias = $this->daysBetween($doc['inicio'] ?? null, $doc['termino'] ?? null);
        }

        $slots = max(1, (int) ($payload['slots'] ?? 1));
        $feePercent = (float) ($payload['fee_adjust_percent'] ?? 0);
        $dealValue = $payload['deal_value'] ?? null;
        if ($dealValue === null || $dealValue === '') {
            $dealValue = self::suggestedDealValue($sumBruto, $feePercent);
        } else {
            $dealValue = (float) $dealValue;
        }

        $targetImpressions = $this->nullableIntFromPayload($payload['target_impressions'] ?? null);
        if ($targetImpressions === null) {
            $targetImpressions = (int) round($sumInsercoes);
        }
        $targetImpactos = $this->nullableIntFromPayload($payload['target_impactos'] ?? null);
        if ($targetImpactos === null) {
            $targetImpactos = (int) round($sumImpactos);
        }

        $cpm = self::calculateCpm((float) $dealValue, (float) $targetImpactos);

        $payload['deal_value'] = round((float) $dealValue, 2);
        $payload['cpm'] = $cpm !== null ? round($cpm, 4) : null;
        $payload['target_impressions'] = $targetImpressions;
        $payload['target_impactos'] = $targetImpactos;
        $payload['target_consumo'] = $payload['target_consumo'] ?? round((float) $dealValue, 2);
        $payload['slots'] = $slots;

        $dealDbId = $this->deals->save($documentId, $payload, $unitIds);

        return $this->context($documentId) + ['saved_deal_id' => $dealDbId];
    }

    public function deleteDeal(int $documentId, int $dealDbId): array
    {
        $this->assertEditable($documentId);
        $this->deals->delete($dealDbId, $documentId);

        return $this->context($documentId);
    }

    public function saveCampaignSlots(int $documentId, int $slots): array
    {
        $this->assertEditable($documentId);
        $slots = max(1, min(99, $slots));
        $stmt = Database::connection()->prepare('UPDATE documents SET campaign_slots = ? WHERE id = ?');
        $stmt->execute([$slots, $documentId]);

        return $this->context($documentId);
    }

    /** @return array<string, mixed> */
    public function uploadCreative(int $documentId, array $file, ?int $userId): array
    {
        $this->assertEditable($documentId);
        $this->assertCreativeUploadOk($file);
        if ((int) ($file['size'] ?? 0) > self::CREATIVE_MAX_BYTES) {
            throw new \InvalidArgumentException('Cada criativo deve ter no máximo 32 MB.');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];
        $mime = $this->detectCreativeMime($file, $allowed);
        if ($mime === null) {
            throw new \InvalidArgumentException('Formato não suportado. Use imagem (JPG, PNG, GIF, WebP) ou vídeo (MP4, WebM).');
        }

        $dir = creativesStorageDir() . '/' . $documentId;
        ensureStorageDirectory($dir, 'criativos');
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($file['name'] ?? 'creative')) ?? 'creative';
        $dest = $dir . '/' . time() . '_' . $safeName;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('Não foi possível salvar o criativo.');
        }

        $media = $this->mediaProbe->probe($dest, $mime);
        $this->creatives->save($documentId, [
            'file_name' => (string) ($file['name'] ?? $safeName),
            'file_path' => $dest,
            'mime_type' => $mime,
            'width' => $media['width'],
            'height' => $media['height'],
            'duration_seconds' => $media['duration_seconds'],
            'frame_rate' => $media['frame_rate'],
            'file_size' => (int) ($file['size'] ?? 0),
        ], $userId);

        return $this->context($documentId);
    }

    public function deleteCreative(int $documentId, int $creativeId): array
    {
        $this->assertEditable($documentId);
        $this->creatives->delete($creativeId, $documentId);

        return $this->context($documentId);
    }

    /** @param list<array<string, mixed>>|null $deviceResolutions */
    public function uploadDealReport(int $documentId, int $dealDbId, array $file, ?array $deviceResolutions = null): array
    {
        $this->assertAccessible($documentId);
        $this->assertCreativeUploadOk($file);

        $deal = $this->deals->findById($dealDbId);
        if ($deal === null || (int) ($deal['document_id'] ?? 0) !== $documentId) {
            throw new \InvalidArgumentException('Deal inválido para este documento.');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formato não suportado. Use CSV, XLS ou XLSX.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new \InvalidArgumentException('Upload inválido.');
        }

        if ($deviceResolutions !== null && $deviceResolutions !== []) {
            $this->applyDeviceResolutions($documentId, $dealDbId, $deal, $deviceResolutions);
            $deal = $this->deals->findById($dealDbId) ?? $deal;
        }

        $knownScreenCodes = $this->knownScreenCodesForImport($documentId, $dealDbId);
        $dealCpm = isset($deal['cpm']) ? (float) $deal['cpm'] : null;
        $aliasMap = $this->deviceAliases->mapByDeal($dealDbId);

        $importResult = $this->dealReportImport->importForDeal(
            $dealDbId,
            (string) ($deal['deal_id'] ?? ''),
            $tmpPath,
            (string) ($file['name'] ?? 'report.' . $ext),
            $knownScreenCodes,
            $dealCpm,
            $aliasMap,
        );

        return $this->context($documentId) + [
            'import_result' => $importResult,
            'campaign_screen_codes' => $this->reviews->screenCodesForDocument($documentId),
            'deal_screen_codes' => $this->reviews->screenCodesForDeal($documentId, $dealDbId),
        ];
    }

    /** @param list<array<string, mixed>> $resolutions */
    private function applyDeviceResolutions(int $documentId, int $dealDbId, array $deal, array $resolutions): void
    {
        $campaignCodes = $this->reviews->screenCodesForDocument($documentId);
        $toSave = [];

        foreach ($resolutions as $item) {
            $deviceName = trim((string) ($item['device_name'] ?? ''));
            if ($deviceName === '') {
                continue;
            }
            $resolution = (string) ($item['resolution'] ?? '');
            if (!in_array($resolution, ['map', 'add', 'ignore'], true)) {
                throw new \InvalidArgumentException('Opção inválida para a tela: ' . $deviceName);
            }

            if ($resolution === 'ignore') {
                $toSave[] = [
                    'device_name' => $deviceName,
                    'resolution' => 'ignore',
                    'screen_code' => CampaignDealDeviceAliasRepository::unidentifiedScreenCode($deviceName),
                ];
                continue;
            }

            if ($resolution === 'map') {
                $screenCode = trim((string) ($item['screen_code'] ?? ''));
                if ($screenCode === '') {
                    throw new \InvalidArgumentException('Selecione a tela da campanha para: ' . $deviceName);
                }
                if (!in_array($screenCode, $campaignCodes, true)) {
                    throw new \InvalidArgumentException('Tela inválida para esta campanha: ' . $screenCode);
                }
                $toSave[] = [
                    'device_name' => $deviceName,
                    'resolution' => 'map',
                    'screen_code' => $screenCode,
                ];
                continue;
            }

            $added = $this->addAdmoohDeviceToDeal($documentId, $dealDbId, $deal, $deviceName);
            $toSave[] = [
                'device_name' => $deviceName,
                'resolution' => 'add',
                'screen_code' => $added['screen_code'],
                'inventory_item_id' => $added['inventory_item_id'],
            ];
        }

        $this->deviceAliases->saveBatch($dealDbId, $toSave);
    }

    /** @return array{screen_code: string, inventory_item_id: int} */
    private function addAdmoohDeviceToDeal(int $documentId, int $dealDbId, array $deal, string $deviceName): array
    {
        $match = $this->findInventoryScreenForDevice($documentId, $deviceName);
        if ($match === null) {
            throw new \InvalidArgumentException(
                'Não foi possível localizar no inventário da campanha a tela: '
                . AdmoohDeviceMatcher::cleanDeviceName($deviceName)
                . '. Use "Vincular a uma tela existente" ou ignore.',
            );
        }

        $inventoryItemId = (int) $match['inventory_item_id'];
        $screenCode = (string) $match['screen_code'];
        $faceNumber = (int) ($match['face_number'] ?? 1);
        $unitIds = array_map(static fn(array $unit): int => (int) ($unit['inventory_item_id'] ?? 0), $deal['units'] ?? []);
        if (!in_array($inventoryItemId, $unitIds, true)) {
            $slots = max(1, (int) ($deal['slots'] ?? 1));
            Database::connection()->prepare(
                'INSERT INTO campaign_deal_units (deal_db_id, inventory_item_id, unit_slots)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE unit_slots = VALUES(unit_slots)'
            )->execute([$dealDbId, $inventoryItemId, $slots]);
        }

        $this->reviews->saveScreenReview($documentId, $inventoryItemId, [
            'face_number' => $faceNumber,
            'screen_code' => $screenCode,
            'is_online' => true,
        ]);

        return ['screen_code' => $screenCode, 'inventory_item_id' => $inventoryItemId];
    }

    /** @return array{screen_code: string, inventory_item_id: int, face_number: int}|null */
    private function findInventoryScreenForDevice(int $documentId, string $deviceName): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.screen_code, s.inventory_item_id, s.face_number
             FROM inventory_screens s
             INNER JOIN document_inventory di
               ON di.inventory_item_id = s.inventory_item_id
              AND di.document_id = ?
             WHERE s.screen_code <> \'\''
        );
        $stmt->execute([$documentId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }

        $codes = array_map(static fn(array $row): string => (string) $row['screen_code'], $rows);
        $matchedCode = AdmoohDeviceMatcher::match($deviceName, $codes);
        if ($matchedCode === null) {
            return null;
        }

        foreach ($rows as $row) {
            if (strcasecmp((string) $row['screen_code'], $matchedCode) === 0) {
                return [
                    'screen_code' => $matchedCode,
                    'inventory_item_id' => (int) $row['inventory_item_id'],
                    'face_number' => (int) ($row['face_number'] ?? 1),
                ];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function knownScreenCodesForImport(int $documentId, int $dealDbId): array
    {
        $codes = [
            ...$this->reviews->screenCodesForDeal($documentId, $dealDbId),
            ...$this->reviews->screenCodesForDocument($documentId),
        ];
        foreach ($this->deviceAliases->mapByDeal($dealDbId) as $alias) {
            $code = trim((string) ($alias['screen_code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** Valor sugerido com fee invertida: base / (1 - fee%). */
    public static function suggestedDealValue(float $base, float $feePercent): float
    {
        if ($base <= 0) {
            return 0.0;
        }
        if ($feePercent == 0.0) {
            return $base;
        }

        $divisor = 1 - ($feePercent / 100);
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('Ajuste Fee % inválido para este cálculo.');
        }

        return $base / $divisor;
    }

    public static function calculateCpm(float $dealValue, float $sumImpactos): ?float
    {
        if ($dealValue <= 0 || $sumImpactos <= 0) {
            return null;
        }

        return ($dealValue / $sumImpactos) * 1000;
    }

    /** @return list<array<string, mixed>> */
    private function approvedInventory(int $documentId): array
    {
        $rows = $this->inventory->listByDocument($documentId);
        $reviews = $this->reviews->unitReviewsByDocument($documentId);
        if ($reviews === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($reviews[(int) $row['inventory_item_id']]['status'] ?? '') === 'approved',
        ));
    }

    /** @param list<array<string, mixed>> $inventoryRows @return array<string, list<string>> */
    private function screensByCms(int $documentId, array $inventoryRows): array
    {
        $reviews = $this->reviews->screenReviewsByDocument($documentId);
        $map = ['Invian' => [], 'Onsign' => [], 'Xibo' => []];

        foreach ($inventoryRows as $row) {
            $itemId = (int) $row['inventory_item_id'];
            $faces = max(1, (int) ($row['faces'] ?? 1));
            $denominacao = (string) ($row['denominacao'] ?? '');
            $globalByFace = [];
            foreach ($this->screens->listByInventoryItem($itemId) as $globalScreen) {
                $globalByFace[(int) $globalScreen['face_number']] = $globalScreen;
            }

            for ($face = 1; $face <= $faces; $face++) {
                $key = $itemId . ':' . $face;
                $screen = $reviews[$key] ?? null;
                $global = $globalByFace[$face] ?? null;

                $code = trim((string) ($global['screen_code'] ?? $screen['screen_code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                $code = ScreenCodeHelper::ensureLocationSuffix($code, $denominacao);

                $cms = $screen['cms'] ?? $global['cms'] ?? null;
                if ($cms === null || $cms === '') {
                    $known = $global ?? $this->screens->findByCode($code);
                    $cms = $known['cms'] ?? null;
                }
                if ($cms !== null && isset($map[$cms])) {
                    $map[$cms][] = $code;
                }
            }
        }

        foreach ($map as &$codes) {
            $codes = array_values(array_unique($codes));
        }
        unset($codes);

        return $map;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapInventoryRow(array $row): array
    {
        return [
            'inventory_item_id' => (int) $row['inventory_item_id'],
            'codigo' => $row['codigo'] ?? '',
            'denominacao' => $row['denominacao'] ?? '',
            'rede_name' => $row['rede_name'] ?? '',
            'publico' => (float) ($row['publico'] ?? 0),
            'impactos' => (float) ($row['impactos'] ?? 0),
            'insercoes' => (float) ($row['insercoes'] ?? 0),
            'bruto_negociado' => (float) ($row['bruto_negociado'] ?? 0),
            'liquido' => (float) ($row['liquido'] ?? 0),
            'dias' => (int) ($row['dias'] ?? 0),
            'faces' => max(1, (int) ($row['faces'] ?? 1)),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function enrichCreativeMetadata(array $rows): array
    {
        foreach ($rows as &$row) {
            $mime = (string) ($row['mime_type'] ?? '');
            $path = (string) ($row['file_path'] ?? '');
            $needsVideoMeta = str_starts_with($mime, 'video/')
                && ((int) ($row['width'] ?? 0) <= 0 || (int) ($row['height'] ?? 0) <= 0
                    || $row['duration_seconds'] === null || $row['frame_rate'] === null);
            $needsImageMeta = str_starts_with($mime, 'image/')
                && ((int) ($row['width'] ?? 0) <= 0 || (int) ($row['height'] ?? 0) <= 0);

            if (($needsVideoMeta || $needsImageMeta) && $path !== '' && is_file($path)) {
                $media = $this->mediaProbe->probe($path, $mime);
                $row['width'] = $media['width'];
                $row['height'] = $media['height'];
                $row['duration_seconds'] = $media['duration_seconds'];
                $row['frame_rate'] = $media['frame_rate'];
                $this->creatives->updateMetadata((int) $row['id'], $media);
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapCreative(array $row): array
    {
        $width = (int) ($row['width'] ?? 0);
        $height = (int) ($row['height'] ?? 0);
        $duration = isset($row['duration_seconds']) ? (float) $row['duration_seconds'] : null;
        $fps = isset($row['frame_rate']) ? (float) $row['frame_rate'] : null;

        return [
            'id' => (int) $row['id'],
            'file_name' => $row['file_name'],
            'mime_type' => $row['mime_type'] ?? '',
            'width' => $width,
            'height' => $height,
            'duration_seconds' => $duration,
            'frame_rate' => $fps,
            'duration_label' => self::formatDurationLabel($duration),
            'width_label' => $width > 0 ? $width . 'px' : null,
            'height_label' => $height > 0 ? $height . 'px' : null,
            'frame_rate_label' => $fps !== null && $fps > 0 ? round($fps) . 'fps' : null,
            'file_size' => (int) ($row['file_size'] ?? 0),
            'url' => url('api/campaign-management.php?action=creative_file&creative_id=' . (int) $row['id']),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private static function formatDurationLabel(?float $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $total = (int) round($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /** @param array<string, mixed> $doc @return array<string, mixed> */
    private function publicDocument(array $doc): array
    {
        return [
            'id' => (int) $doc['id'],
            'document_id' => $doc['document_id'],
            'ads_id' => $doc['ads_id'],
            'campanha' => $doc['campanha'],
            'anunciante' => $doc['anunciante'],
            'agencia' => $doc['agencia'],
            'planejador_ssp' => $doc['planejador_ssp'],
            'oc_informe_ssp' => $doc['oc_informe_ssp'],
            'inicio' => $doc['inicio'],
            'termino' => $doc['termino'],
            'campaign_slots' => (int) ($doc['campaign_slots'] ?? 1),
            'campaign_workflow_status' => (string) ($doc['campaign_workflow_status'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function assertAccessible(int $documentId): array
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }
        $status = (string) ($doc['campaign_workflow_status'] ?? '');
        if (!in_array($status, ['aprovada', 'finalizada_pausada'], true)) {
            throw new \InvalidArgumentException('Campanha não disponível para configuração.');
        }

        return $doc;
    }

    private function assertEditable(int $documentId): void
    {
        $doc = $this->assertAccessible($documentId);
        if ($this->isReadOnlyStatus((string) ($doc['campaign_workflow_status'] ?? ''))) {
            throw new \InvalidArgumentException('Campanha finalizada — alterações não são permitidas.');
        }
    }

    private function isReadOnlyStatus(string $status): bool
    {
        return $status === 'finalizada_pausada';
    }

    private function daysBetween(?string $inicio, ?string $termino): int
    {
        if (!$inicio || !$termino) {
            return 1;
        }
        $a = strtotime((string) $inicio);
        $b = strtotime((string) $termino);
        if ($a === false || $b === false || $b < $a) {
            return 1;
        }

        return max(1, (int) floor(($b - $a) / 86400) + 1);
    }

    /** @param array<string, mixed> $doc @param list<array<string, mixed>> $deals @return array<string, mixed> */
    private function buildControlSummary(array $doc, array $deals): array
    {
        $daysTotal = $this->daysBetween($doc['inicio'] ?? null, $doc['termino'] ?? null);
        $progress = $this->campaignProgress($doc['inicio'] ?? null, $doc['termino'] ?? null, $daysTotal);

        $dealSummaries = [];
        $campaignActual = ['requisicoes' => 0.0, 'impressoes' => 0.0, 'impactos' => 0.0, 'consumo' => 0.0];
        $campaignTargets = ['impressions' => 0, 'impactos' => 0, 'consumo' => 0.0];
        $campaignDailyActual = [];
        $inventoryById = [];
        foreach ($this->approvedInventory((int) ($doc['id'] ?? 0)) as $inventoryRow) {
            $inventoryById[(int) $inventoryRow['inventory_item_id']] = $inventoryRow;
        }

        foreach ($deals as $deal) {
            $dealDbId = (int) ($deal['id'] ?? 0);
            $dealCpm = isset($deal['cpm']) ? (float) $deal['cpm'] : null;
            $actual = $this->applyDealCpmToActual($this->dealReports->totalsByDeal($dealDbId), $dealCpm);
            $rawReports = $this->dealReports->listByDeal($dealDbId, 5000);
            $platformHasScreens = $this->dealReports->hasScreenBreakdown($dealDbId);
            $platformHasNetworks = $this->dealReports->hasNetworkBreakdown($dealDbId);
            $reportBreakdownEstimated = false;
            $reports = $rawReports;

            $dealUnits = $deal['units'] ?? [];
            if ($this->reportEstimate->shouldEstimate($rawReports, $platformHasScreens, $platformHasNetworks)) {
                if ($dealUnits !== []) {
                    $reports = $this->reportEstimate->expandFromDealUnits($rawReports, $dealUnits, $inventoryById);
                    $reportBreakdownEstimated = $reports !== [];
                }
            }

            $networkFromInventory = false;
            if (!$platformHasNetworks && $platformHasScreens && $dealUnits !== []) {
                $screenNetworkMap = $this->reportEstimate->buildScreenNetworkMap(
                    (int) ($doc['id'] ?? 0),
                    $dealUnits,
                    $inventoryById,
                    $this->reviews,
                    $this->screens,
                );
                if ($screenNetworkMap !== []) {
                    $reports = $this->reportEstimate->enrichReportsWithInventoryNetwork($reports, $screenNetworkMap);
                    $networkFromInventory = $this->reportEstimate->reportsHaveNetwork($reports);
                }
            }

            $reportHasScreens = $platformHasScreens || $reportBreakdownEstimated;
            $reportHasNetworks = $platformHasNetworks || $reportBreakdownEstimated || $networkFromInventory;
            $reportNetworks = $reportHasNetworks
                ? ($reportBreakdownEstimated || $networkFromInventory
                    ? $this->reportEstimate->listNetworksFromReports($reports)
                    : $this->dealReports->listNetworksByDeal($dealDbId))
                : [];
            if ($reportNetworks === [] && $reportHasNetworks) {
                $reportNetworks = $this->reportEstimate->listNetworksFromDeal($dealUnits, $inventoryById);
            }
            $dailyRows = $this->dailyRowsFromReports($reports, $dealCpm);
            $targetImpressions = (int) ($deal['target_impressions'] ?? 0);
            $targetImpactos = (int) ($deal['target_impactos'] ?? 0);
            $targetConsumo = (float) ($deal['target_consumo'] ?? $deal['deal_value'] ?? 0);

            $campaignActual['requisicoes'] += $actual['requisicoes'];
            $campaignActual['impressoes'] += $actual['impressoes'];
            $campaignActual['impactos'] += $actual['impactos'];
            $campaignActual['consumo'] += $actual['consumo'];
            $campaignTargets['impressions'] += $targetImpressions;
            $campaignTargets['impactos'] += $targetImpactos;
            $campaignTargets['consumo'] += $targetConsumo;

            foreach ($dailyRows as $dailyRow) {
                $dateKey = (string) ($dailyRow['report_date'] ?? '');
                if ($dateKey === '') {
                    continue;
                }
                if (!isset($campaignDailyActual[$dateKey])) {
                    $campaignDailyActual[$dateKey] = [
                        'requisicoes' => 0.0,
                        'impressoes' => 0.0,
                        'impactos' => 0.0,
                        'consumo' => 0.0,
                    ];
                }
                $campaignDailyActual[$dateKey]['requisicoes'] += (float) ($dailyRow['requisicoes'] ?? 0);
                $campaignDailyActual[$dateKey]['impressoes'] += (float) ($dailyRow['impressoes'] ?? 0);
                $campaignDailyActual[$dateKey]['impactos'] += (float) ($dailyRow['impactos'] ?? 0);
                $campaignDailyActual[$dateKey]['consumo'] += (float) ($dailyRow['consumo'] ?? 0);
            }

            $dealSummaries[] = [
                'deal_db_id' => $dealDbId,
                'deal_id' => (string) ($deal['deal_id'] ?? ''),
                'report_count' => (int) ($deal['report_count'] ?? $actual['row_count']),
                'targets' => [
                    'impressions' => $targetImpressions,
                    'impactos' => $targetImpactos,
                    'consumo' => round($targetConsumo, 2),
                ],
                'daily_targets' => [
                    'impressions' => $daysTotal > 0 ? (int) round($targetImpressions / $daysTotal) : 0,
                    'impactos' => $daysTotal > 0 ? (int) round($targetImpactos / $daysTotal) : 0,
                    'consumo' => $daysTotal > 0 ? round($targetConsumo / $daysTotal, 2) : 0.0,
                ],
                'actual' => [
                    'requisicoes' => (int) round($actual['requisicoes']),
                    'impressoes' => (int) round($actual['impressoes']),
                    'impactos' => (int) round($actual['impactos']),
                    'consumo' => round($actual['consumo'], 2),
                ],
                'pacing' => $this->buildPacingSeries(
                    $doc['inicio'] ?? null,
                    $doc['termino'] ?? null,
                    [
                        'impressoes' => $targetImpressions,
                        'impactos' => $targetImpactos,
                        'consumo' => $targetConsumo,
                    ],
                    $dailyRows,
                    $daysTotal,
                ),
                'reports' => array_map(
                    fn(array $row): array => $this->mapDealReport($row, $reportBreakdownEstimated, $dealCpm),
                    $reports,
                ),
                'cpm' => $dealCpm,
                'report_has_screens' => $reportHasScreens,
                'report_has_networks' => $reportHasNetworks,
                'report_networks' => $reportNetworks,
                'report_breakdown_estimated' => $reportBreakdownEstimated,
                'report_networks_from_inventory' => $networkFromInventory,
            ];
        }

        return [
            'campaign' => [
                'inicio' => $doc['inicio'] ?? null,
                'termino' => $doc['termino'] ?? null,
                'days_total' => $daysTotal,
                'days_elapsed' => $progress['days_elapsed'],
                'days_remaining' => $progress['days_remaining'],
                'progress_percent' => $progress['progress_percent'],
                'targets' => $campaignTargets,
                'actual' => [
                    'requisicoes' => (int) round($campaignActual['requisicoes']),
                    'impressoes' => (int) round($campaignActual['impressoes']),
                    'impactos' => (int) round($campaignActual['impactos']),
                    'consumo' => round($campaignActual['consumo'], 2),
                ],
                'pacing' => $this->buildPacingSeries(
                    $doc['inicio'] ?? null,
                    $doc['termino'] ?? null,
                    [
                        'impressoes' => $campaignTargets['impressions'],
                        'impactos' => $campaignTargets['impactos'],
                        'consumo' => $campaignTargets['consumo'],
                    ],
                    $this->dailyRowsFromMap($campaignDailyActual),
                    $daysTotal,
                ),
            ],
            'deals' => $dealSummaries,
        ];
    }

    /** @param array<string, array{requisicoes: float, impressoes: float, impactos: float, consumo: float}> $byDate @return list<array<string, mixed>> */
    private function dailyRowsFromMap(array $byDate): array
    {
        $rows = [];
        ksort($byDate);
        foreach ($byDate as $date => $values) {
            $rows[] = ['report_date' => $date] + $values;
        }

        return $rows;
    }

    /**
     * @param array{impressoes: float|int, impactos: float|int, consumo: float|int} $targets
     * @param list<array<string, mixed>> $dailyRows
     * @return array<string, mixed>
     */
    private function buildPacingSeries(
        ?string $inicio,
        ?string $termino,
        array $targets,
        array $dailyRows,
        int $fallbackDaysTotal = 1,
    ): array {
        [$start, $end] = $this->resolveCampaignDates($inicio, $termino, $fallbackDaysTotal);
        if ($start === null || $end === null || $start > $end) {
            return [
                'labels' => [],
                'planned_daily' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'planned_cumulative' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'actual_daily' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'actual_cumulative' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'moving_avg_7_daily' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'projected_cumulative' => ['impressoes' => [], 'impactos' => [], 'consumo' => []],
                'has_actual' => false,
            ];
        }

        $actualByDate = [];
        foreach ($dailyRows as $row) {
            $dateKey = substr((string) ($row['report_date'] ?? ''), 0, 10);
            if ($dateKey === '') {
                continue;
            }
            $actualByDate[$dateKey] = [
                'impressoes' => (float) ($row['impressoes'] ?? 0),
                'impactos' => (float) ($row['impactos'] ?? 0),
                'consumo' => (float) ($row['consumo'] ?? 0),
            ];
        }

        $daysTotal = max(1, (int) $start->diff($end)->days + 1);
        $dailyTarget = [
            'impressoes' => ((float) $targets['impressoes']) / $daysTotal,
            'impactos' => ((float) $targets['impactos']) / $daysTotal,
            'consumo' => ((float) $targets['consumo']) / $daysTotal,
        ];

        $labels = [];
        $plannedDaily = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        $plannedCumulative = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        $actualDaily = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        $actualCumulative = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        $running = ['impressoes' => 0.0, 'impactos' => 0.0, 'consumo' => 0.0];
        $plannedRunning = ['impressoes' => 0.0, 'impactos' => 0.0, 'consumo' => 0.0];
        $hasActual = false;

        for ($cursor = $start, $dayIndex = 0; $cursor <= $end; $cursor = $cursor->modify('+1 day'), $dayIndex++) {
            $dateKey = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d/m');

            foreach (['impressoes', 'impactos', 'consumo'] as $metric) {
                $plannedDaily[$metric][] = round($dailyTarget[$metric], $metric === 'consumo' ? 2 : 0);
                $plannedRunning[$metric] += $dailyTarget[$metric];
                $plannedCumulative[$metric][] = round($plannedRunning[$metric], $metric === 'consumo' ? 2 : 0);

                $dayActual = (float) ($actualByDate[$dateKey][$metric] ?? 0);
                if ($dayActual > 0) {
                    $hasActual = true;
                }
                $actualDaily[$metric][] = round($dayActual, $metric === 'consumo' ? 2 : 0);
                $running[$metric] += $dayActual;
                $actualCumulative[$metric][] = round($running[$metric], $metric === 'consumo' ? 2 : 0);
            }
        }

        $movingAvg7Daily = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        $projectedCumulative = ['impressoes' => [], 'impactos' => [], 'consumo' => []];
        foreach (['impressoes', 'impactos', 'consumo'] as $metric) {
            $isMoney = $metric === 'consumo';
            $movingAvg7Daily[$metric] = $this->movingAverage7($actualDaily[$metric], $isMoney);
            $projectedCumulative[$metric] = $this->projectedCumulativeSeries(
                $start,
                $end,
                $actualDaily[$metric],
                $actualCumulative[$metric],
                $isMoney,
            );
        }

        return [
            'labels' => $labels,
            'planned_daily' => $plannedDaily,
            'planned_cumulative' => $plannedCumulative,
            'actual_daily' => $actualDaily,
            'actual_cumulative' => $actualCumulative,
            'moving_avg_7_daily' => $movingAvg7Daily,
            'projected_cumulative' => $projectedCumulative,
            'has_actual' => $hasActual,
        ];
    }

    /** @param list<float|int> $values @return list<float|int> */
    private function movingAverage7(array $values, bool $isMoney): array
    {
        $result = [];
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            $windowStart = max(0, $i - 6);
            $window = array_slice($values, $windowStart, $i - $windowStart + 1);
            $avg = array_sum($window) / max(1, count($window));
            $result[] = round($avg, $isMoney ? 2 : 0);
        }

        return $result;
    }

    /**
     * @param list<float|int> $actualDaily
     * @param list<float|int> $actualCumulative
     * @return list<float|null>
     */
    private function projectedCumulativeSeries(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $actualDaily,
        array $actualCumulative,
        bool $isMoney,
    ): array {
        $count = count($actualDaily);
        if ($count === 0) {
            return [];
        }

        $asOfIndex = $this->projectionAnchorIndex($start, $end, $actualDaily);
        if ($asOfIndex < 0) {
            return array_fill(0, $count, null);
        }

        $windowStart = max(0, $asOfIndex - 6);
        $window = array_slice($actualDaily, $windowStart, $asOfIndex - $windowStart + 1);
        $positiveDays = array_values(array_filter($window, static fn(float|int $value): bool => (float) $value > 0));
        if ($positiveDays !== []) {
            $runRate = array_sum($positiveDays) / count($positiveDays);
        } else {
            $elapsedDays = $asOfIndex + 1;
            $runRate = $elapsedDays > 0 ? ((float) ($actualCumulative[$asOfIndex] ?? 0)) / $elapsedDays : 0.0;
        }

        $projected = array_fill(0, $count, null);
        $base = (float) ($actualCumulative[$asOfIndex] ?? 0);
        $projected[$asOfIndex] = round($base, $isMoney ? 2 : 0);
        for ($i = $asOfIndex + 1; $i < $count; $i++) {
            $projected[$i] = round($base + ($runRate * ($i - $asOfIndex)), $isMoney ? 2 : 0);
        }

        return $projected;
    }

    /** @param list<float|int> $actualDaily */
    private function projectionAnchorIndex(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $actualDaily,
    ): int {
        $today = new \DateTimeImmutable('today');
        if ($today < $start) {
            return -1;
        }

        $todayIndex = -1;
        $lastDataIndex = -1;
        for ($i = 0, $cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day'), $i++) {
            if ((float) ($actualDaily[$i] ?? 0) > 0) {
                $lastDataIndex = $i;
            }
            if ($cursor <= $today) {
                $todayIndex = $i;
            }
        }

        if ($lastDataIndex < 0) {
            return -1;
        }

        if ($today > $end) {
            return $lastDataIndex;
        }

        return min($todayIndex, $lastDataIndex);
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function resolveCampaignDates(?string $inicio, ?string $termino, int $fallbackDaysTotal): array
    {
        $start = $this->parseDateOnly($inicio);
        $end = $this->parseDateOnly($termino);
        $daysTotal = max(1, $fallbackDaysTotal);

        if ($start !== null && $end !== null) {
            return [$start, $end];
        }

        if ($start !== null && $end === null) {
            return [$start, $start->modify('+' . ($daysTotal - 1) . ' days')];
        }

        if ($start === null && $end !== null) {
            return [$end->modify('-' . ($daysTotal - 1) . ' days'), $end];
        }

        $start = new \DateTimeImmutable('today');

        return [$start, $start->modify('+' . ($daysTotal - 1) . ' days')];
    }

    /** @return array{days_elapsed: int, days_remaining: int, progress_percent: float} */
    private function campaignProgress(?string $inicio, ?string $termino, int $daysTotal): array
    {
        $start = $this->parseDateOnly($inicio);
        $end = $this->parseDateOnly($termino);
        $today = new \DateTimeImmutable('today');

        if ($start === null || $end === null) {
            return ['days_elapsed' => 0, 'days_remaining' => $daysTotal, 'progress_percent' => 0.0];
        }

        if ($today < $start) {
            return ['days_elapsed' => 0, 'days_remaining' => $daysTotal, 'progress_percent' => 0.0];
        }

        if ($today > $end) {
            return [
                'days_elapsed' => $daysTotal,
                'days_remaining' => 0,
                'progress_percent' => 100.0,
            ];
        }

        $elapsed = max(1, (int) $start->diff($today)->days + 1);
        $remaining = max(0, $daysTotal - $elapsed);
        $progressPercent = $daysTotal > 0 ? round(($elapsed / $daysTotal) * 100, 1) : 0.0;

        return [
            'days_elapsed' => $elapsed,
            'days_remaining' => $remaining,
            'progress_percent' => $progressPercent,
        ];
    }

    private function parseDateOnly(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $text = trim($value);
        $datePart = substr($text, 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $text, $match)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', "{$match[3]}-{$match[2]}-{$match[1]}");
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed;
            }
        }

        $timestamp = strtotime($text);

        return $timestamp !== false
            ? (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('midnight')
            : null;
    }

    /** @param list<array<string, mixed>> $reports @return list<array<string, mixed>> */
    private function dailyRowsFromReports(array $reports, ?float $dealCpm = null): array
    {
        $byDate = [];

        foreach ($reports as $row) {
            $dateKey = substr((string) ($row['report_date'] ?? ''), 0, 10);
            if ($dateKey === '') {
                continue;
            }

            if (!isset($byDate[$dateKey])) {
                $byDate[$dateKey] = [
                    'report_date' => $dateKey,
                    'requisicoes' => 0.0,
                    'impressoes' => 0.0,
                    'impactos' => 0.0,
                    'consumo' => 0.0,
                ];
            }

            $byDate[$dateKey]['requisicoes'] += (float) ($row['requisicoes'] ?? 0);
            $byDate[$dateKey]['impressoes'] += (float) ($row['impressoes'] ?? 0);
            $byDate[$dateKey]['impactos'] += (float) ($row['impactos'] ?? 0);
            if ($dealCpm === null || $dealCpm <= 0) {
                $byDate[$dateKey]['consumo'] += (float) ($row['consumo'] ?? 0);
            }
        }

        if ($dealCpm !== null && $dealCpm > 0) {
            foreach ($byDate as &$day) {
                $day['consumo'] = $this->consumoFromDealCpm((float) ($day['impactos'] ?? 0), $dealCpm);
            }
            unset($day);
        }

        ksort($byDate);

        return array_values($byDate);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapDealReport(array $row, bool $estimatedBreakdown = false, ?float $dealCpm = null): array
    {
        $impactos = isset($row['impactos']) ? (int) $row['impactos'] : null;
        $consumo = isset($row['consumo']) ? (float) $row['consumo'] : null;
        if ($dealCpm !== null && $dealCpm > 0 && $impactos !== null) {
            $consumo = $this->consumoFromDealCpm((float) $impactos, $dealCpm);
        }

        return [
            'report_date' => $row['report_date'] ?? null,
            'screen_code' => (string) ($row['screen_code'] ?? ''),
            'network' => (string) ($row['network'] ?? ''),
            'requisicoes' => isset($row['requisicoes']) ? (int) $row['requisicoes'] : null,
            'impressoes' => isset($row['impressoes']) ? (int) $row['impressoes'] : null,
            'impactos' => $impactos,
            'moeda' => (string) ($row['moeda'] ?? 'BRL'),
            'cpm_aplicado' => $dealCpm !== null && $dealCpm > 0 ? round($dealCpm, 4) : (
                isset($row['cpm_aplicado']) ? (float) $row['cpm_aplicado'] : null
            ),
            'consumo' => $consumo,
            'estimated' => $estimatedBreakdown || !empty($row['_estimated']),
        ];
    }

    /** @param array{requisicoes: float, impressoes: float, impactos: float, consumo: float, row_count?: int} $actual */
    private function applyDealCpmToActual(array $actual, ?float $dealCpm): array
    {
        if ($dealCpm !== null && $dealCpm > 0) {
            $actual['consumo'] = $this->consumoFromDealCpm((float) ($actual['impactos'] ?? 0), $dealCpm);
        }

        return $actual;
    }

    private function consumoFromDealCpm(float $impactos, float $dealCpm): float
    {
        if ($impactos <= 0 || $dealCpm <= 0) {
            return 0.0;
        }

        return round($impactos * $dealCpm / 1000, 2);
    }

    /** @param array<string, mixed> $file */
    private function assertCreativeUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize do PHP.',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o limite permitido para upload.',
            UPLOAD_ERR_PARTIAL => 'Upload interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária indisponível no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo no disco.',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão do PHP.',
        ];

        throw new \InvalidArgumentException($messages[$error] ?? 'Falha no upload do criativo.');
    }

    /** @param list<string> $allowed */
    private function detectCreativeMime(array $file, array $allowed): ?string
    {
        $declared = trim((string) ($file['type'] ?? ''));
        if ($declared !== '' && in_array($declared, $allowed, true)) {
            return $declared;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath) || !function_exists('finfo_open')) {
            return in_array($declared, $allowed, true) ? $declared : null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return in_array($declared, $allowed, true) ? $declared : null;
        }

        $detected = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        return is_string($detected) && in_array($detected, $allowed, true) ? $detected : null;
    }

    private function nullableIntFromPayload(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
